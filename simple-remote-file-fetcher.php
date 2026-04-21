<?php
/*
Plugin Name:       Simple Remote File Fetcher
Plugin URI:        https://hfarazm.com/plugins/simple-remote-file-fetcher
Description:       Fetch any remote file with ultra fast speed into your WordPress directory.
Version:           2.5
Author:            Hfarazm Software LLC
Author URI:        https://hfarazm.com/plugins
License:           GPL v2 or later
License URI:       https://www.gnu.org/licenses/gpl-2.0.html
Text Domain:       simple-remote-file-fetcher
Domain Path:       /languages
Requires at least: 5.0
Requires PHP:      7.2
Tested up to:      6.7
*/

if ( ! defined( 'ABSPATH' ) ) exit;

// ── Admin menu ───────────────────────────────────────────────────────────────
add_action('admin_menu', function() {
    add_menu_page(
        __( 'Remote File Fetcher', 'simple-remote-file-fetcher' ),
        __( 'File Fetcher', 'simple-remote-file-fetcher' ),
        'manage_options',
        'remote-file-fetcher',
        'srf_fetcher_page'
    );
});

// ── Helper: find next available filename (file_1.zip, file_2.zip …) ─────────
function srf_available_filename( $filename ) {
    $info    = pathinfo($filename);
    $base    = $info['filename'];
    $ext     = isset($info['extension']) ? '.' . $info['extension'] : '';
    $counter = 1;
    do {
        $candidate = $base . '_' . $counter . $ext;
        $counter++;
    } while ( file_exists( ABSPATH . $candidate ) );
    return $candidate;
}

// ── AJAX: start download ─────────────────────────────────────────────────────
add_action('wp_ajax_srf_start_download', function() {
    check_ajax_referer('srf_ajax_nonce', 'nonce');
    if ( ! current_user_can('manage_options') ) wp_die('Forbidden', 403);

    $url             = esc_url_raw( trim( $_POST['url'] ?? '' ) );
    $conflict_action = sanitize_text_field( $_POST['conflict_action'] ?? 'none' );
    $token           = preg_replace('/[^a-zA-Z0-9]/', '', $_POST['token'] ?? '');

    // Allowlist conflict_action to prevent unexpected values
    if ( ! in_array( $conflict_action, [ 'none', 'overwrite', 'rename' ], true ) ) {
        $conflict_action = 'none';
    }

    if ( empty($url) || empty($token) ) {
        wp_send_json(['status' => 'error', 'message' => __( 'Invalid request.', 'simple-remote-file-fetcher' )]);
    }

    $filename = basename( parse_url($url, PHP_URL_PATH) );

    // Conflict check
    if ( file_exists( ABSPATH . $filename ) && $conflict_action === 'none' ) {
        wp_send_json([
            'status'   => 'conflict',
            'filename' => $filename,
            'renamed'  => srf_available_filename($filename),
        ]);
    }

    if ( $conflict_action === 'rename' ) {
        $filename = srf_available_filename($filename);
    } elseif ( $conflict_action === 'overwrite' && file_exists( ABSPATH . $filename ) ) {
        wp_delete_file( ABSPATH . $filename );
    }

    $full_path     = ABSPATH . $filename;
    $progress_file = sys_get_temp_dir() . '/srf_progress_' . $token . '.json';

    // Init progress file so poller doesn't get "not found" on first tick
    file_put_contents($progress_file, json_encode([
        'downloaded' => 0, 'total' => 0, 'done' => false,
    ]), LOCK_EX);

    // ── cURL download with progress callback ─────────────────────────────────
    $ch = curl_init($url);
    $fp = fopen($full_path, 'wb');

    if ( ! $fp ) {
        /* translators: %s: full server path of the destination file */
        wp_send_json(['status' => 'error', 'message' => sprintf( __( 'Cannot write to: %s', 'simple-remote-file-fetcher' ), $full_path )]);
    }

    curl_setopt_array($ch, [
        CURLOPT_FILE             => $fp,
        CURLOPT_FOLLOWLOCATION   => true,
        CURLOPT_TIMEOUT          => 300,
        CURLOPT_NOPROGRESS       => false,
        CURLOPT_SSL_VERIFYPEER   => true,
        CURLOPT_USERAGENT        => 'WordPress/' . get_bloginfo('version'),
        CURLOPT_PROGRESSFUNCTION => function( $ch, $total, $downloaded ) use ($progress_file) {
            if ( $total > 0 || $downloaded > 0 ) {
                file_put_contents($progress_file, json_encode([
                    'downloaded' => (int) $downloaded,
                    'total'      => (int) $total,
                    'done'       => false,
                ]), LOCK_EX);
            }
            return 0; // returning non-zero aborts
        },
    ]);

    $ok      = curl_exec($ch);
    $err     = curl_error($ch);
    $http    = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    fclose($fp);

    if ( ! $ok || $http >= 400 ) {
        wp_delete_file( $full_path );
        file_put_contents($progress_file, json_encode(['done' => true, 'error' => true]), LOCK_EX);
        /* translators: %s: HTTP status code */
        wp_send_json(['status' => 'error', 'message' => $err ?: sprintf( __( 'HTTP error: %s', 'simple-remote-file-fetcher' ), $http )]);
    }

    $size = file_exists($full_path) ? filesize($full_path) : 0;

    file_put_contents($progress_file, json_encode([
        'downloaded' => $size, 'total' => $size, 'done' => true,
    ]), LOCK_EX);

    // Log to history
    $history   = get_option('srf_download_history', []);
    $history[] = [
        'url'      => $url,
        'filename' => $filename,
        'path'     => $full_path,
        'time'     => current_time('mysql'),
    ];
    update_option('srf_download_history', $history);

    wp_send_json([
        'status'   => 'success',
        'filename' => $filename,
        'path'     => $full_path,
        'size'     => $size,
    ]);
});

// ── AJAX: poll progress ──────────────────────────────────────────────────────
add_action('wp_ajax_srf_get_progress', function() {
    check_ajax_referer('srf_ajax_nonce', 'nonce');
    if ( ! current_user_can('manage_options') ) wp_die('Forbidden', 403);

    $token         = preg_replace('/[^a-zA-Z0-9]/', '', $_GET['token'] ?? '');
    $progress_file = sys_get_temp_dir() . '/srf_progress_' . $token . '.json';

    if ( file_exists($progress_file) ) {
        $data = json_decode( file_get_contents($progress_file), true );
        wp_send_json( $data ?: ['error' => 'invalid data'] );
    } else {
        wp_send_json(['error' => 'not found']);
    }
});

// ── Admin page ───────────────────────────────────────────────────────────────
function srf_fetcher_page() {
    $nonce = wp_create_nonce('srf_ajax_nonce');
    ?>
    <div class="wrap">
        <h2><?php esc_html_e( 'Remote File Fetcher', 'simple-remote-file-fetcher' ); ?></h2>

        <div style="display:flex;gap:28px;align-items:flex-start;">

            <!-- ── Main content ── -->
            <div style="flex:1;min-width:0;">

                <table class="form-table" style="margin-top:0;">
                    <tr>
                        <th scope="row"><label for="srf_url"><?php esc_html_e( 'Remote File URL', 'simple-remote-file-fetcher' ); ?></label></th>
                        <td>
                            <input
                                type="url"
                                id="srf_url"
                                style="width:100%;max-width:580px;"
                                placeholder="<?php esc_attr_e( 'e.g. https://file-examples.com/wp-content/storage/2017/02/zip_5MB.zip', 'simple-remote-file-fetcher' ); ?>"
                            >
                            <p class="description"><?php esc_html_e( 'The file will be saved in the WordPress root directory using the filename from the URL.', 'simple-remote-file-fetcher' ); ?></p>
                        </td>
                    </tr>
                </table>
                <p>
                    <button id="srf_fetch_btn" class="button button-primary"><?php esc_html_e( 'Fetch File', 'simple-remote-file-fetcher' ); ?></button>
                </p>

                <!-- Status / conflict / progress area -->
                <div id="srf_status" style="display:none; margin:16px 0; padding:14px 18px; border-left:4px solid #72aee6; background:#f0f6fc; max-width:680px;"></div>

                <?php srf_render_history(); ?>
            </div>

            <!-- ── Sidebar ── -->
            <div style="width:200px;flex-shrink:0;display:flex;flex-direction:column;gap:14px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;">

                <!-- How it works -->
                <div style="background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:16px;">
                    <p style="margin:0 0 10px;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#1d2327;"><?php esc_html_e( 'How It Works', 'simple-remote-file-fetcher' ); ?></p>
                    <ul style="margin:0;padding:0;list-style:none;display:flex;flex-direction:column;gap:6px;">
                        <?php
                        $steps = [
                            __( 'Paste a file URL', 'simple-remote-file-fetcher' ),
                            __( 'Server fetches the file', 'simple-remote-file-fetcher' ),
                            __( 'Live progress shown', 'simple-remote-file-fetcher' ),
                            __( 'Saved to WP root', 'simple-remote-file-fetcher' ),
                            __( 'Download logged', 'simple-remote-file-fetcher' ),
                        ];
                        foreach ( $steps as $i => $step ) : ?>
                        <li style="display:flex;align-items:flex-start;gap:7px;font-size:12px;color:#3c434a;line-height:1.4;">
                            <span style="flex-shrink:0;width:16px;height:16px;border-radius:50%;background:#2271b1;color:#fff;font-size:9px;font-weight:700;display:flex;align-items:center;justify-content:center;margin-top:1px;"><?php echo absint( $i + 1 ); ?></span>
                            <?php echo esc_html($step); ?>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                </div>

                <!-- Donate via PayPal -->
                <div style="background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:16px;text-align:center;">
                    <p style="margin:0 0 10px;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#1d2327;"><?php esc_html_e( 'Support This Plugin', 'simple-remote-file-fetcher' ); ?></p>
                    <p style="font-size:11px;color:#646970;margin:0 0 12px;line-height:1.5;"><?php esc_html_e( 'If this plugin saves you time, consider a small donation.', 'simple-remote-file-fetcher' ); ?></p>
                    <a href="https://www.paypal.com/donate/?hosted_button_id=U9SCFW8YL8M4J"
                       target="_blank" rel="noopener"
                       style="display:inline-flex;align-items:center;gap:7px;background:#003087;color:#fff;text-decoration:none;padding:8px 12px;border-radius:4px;font-size:12px;font-weight:600;width:100%;box-sizing:border-box;justify-content:center;">
                        <!-- PayPal logo mark -->
                        <svg width="16" height="18" viewBox="0 0 24 28" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <path d="M19.5 3.5C18.5 1.5 16.3 1 13.8 1H6.3C5.6 1 5 1.5 4.9 2.2L2 20.2C1.9 20.7 2.3 21.2 2.8 21.2H7.3L8.4 14.2L8.3 14.6C8.4 13.9 9 13.4 9.7 13.4H12C16.4 13.4 19.8 11.6 20.8 6.4C20.8 6.3 20.9 6 20.9 6C21.2 4.8 20.9 4 19.5 3.5Z" fill="#009cde"/>
                            <path d="M20.8 6C20.8 6.3 20.8 6.3 20.8 6.4C19.8 11.6 16.4 13.4 12 13.4H9.7C9 13.4 8.4 13.9 8.3 14.6L6.8 23.8C6.7 24.2 7 24.6 7.5 24.6H11.4C12 24.6 12.5 24.2 12.6 23.6V23.3L13.4 18.3V18C13.5 17.4 14 17 14.6 17H15.2C18.9 17 21.8 15.5 22.6 11.1C23 9.3 22.8 7.7 21.8 6.7C21.5 6.4 21.2 6.2 20.8 6Z" fill="#012169"/>
                        </svg>
                        <?php esc_html_e( 'Donate via PayPal', 'simple-remote-file-fetcher' ); ?>
                    </a>
                </div>

                <!-- Hire on Fiverr -->
                <div style="background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:16px;text-align:center;">
                    <p style="margin:0 0 10px;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#1d2327;"><?php esc_html_e( 'Hire Me', 'simple-remote-file-fetcher' ); ?></p>
                    <p style="font-size:11px;color:#646970;margin:0 0 12px;line-height:1.5;"><?php esc_html_e( 'WordPress dev & SEO services available.', 'simple-remote-file-fetcher' ); ?></p>
                    <a href="https://www.fiverr.com/users/wordpressseodev"
                       target="_blank" rel="noopener"
                       style="display:inline-flex;align-items:center;gap:7px;background:#1dbf73;color:#fff;text-decoration:none;padding:8px 12px;border-radius:4px;font-size:12px;font-weight:600;width:100%;box-sizing:border-box;justify-content:center;margin-bottom:8px;">
                        <!-- Fiverr logo mark -->
                        <svg width="14" height="14" viewBox="0 0 100 100" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <circle cx="50" cy="50" r="50" fill="#fff"/>
                            <text x="50" y="68" text-anchor="middle" font-size="52" font-family="Arial,sans-serif" font-weight="900" fill="#1dbf73">f</text>
                            <circle cx="73" cy="26" r="7" fill="#1dbf73"/>
                        </svg>
                        <?php esc_html_e( 'Fiverr Profile', 'simple-remote-file-fetcher' ); ?>
                    </a>
                    <a href="https://www.upwork.com/freelancers/hafizfaraz"
                       target="_blank" rel="noopener"
                       style="display:inline-flex;align-items:center;gap:7px;background:#14a800;color:#fff;text-decoration:none;padding:8px 12px;border-radius:4px;font-size:12px;font-weight:600;width:100%;box-sizing:border-box;justify-content:center;">
                        <!-- Upwork logo mark -->
                        <svg width="14" height="14" viewBox="0 0 100 100" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <circle cx="50" cy="50" r="50" fill="#fff"/>
                            <text x="50" y="68" text-anchor="middle" font-size="52" font-family="Arial,sans-serif" font-weight="900" fill="#14a800">U</text>
                        </svg>
                        <?php esc_html_e( 'Upwork Profile', 'simple-remote-file-fetcher' ); ?>
                    </a>
                </div>

            </div><!-- /sidebar -->
        </div><!-- /flex wrapper -->
    </div>

    <script>
    (function(){
        const ajaxUrl = <?php echo wp_json_encode( admin_url('admin-ajax.php') ); ?>;
        const nonce   = <?php echo wp_json_encode( $nonce ); ?>;

        const urlInput  = document.getElementById('srf_url');
        const fetchBtn  = document.getElementById('srf_fetch_btn');
        const statusBox = document.getElementById('srf_status');

        let pollTimer   = null;
        let pollSamples = []; // [{time, bytes}] for speed calc

        // ── Helpers ──────────────────────────────────────────────────────────

        function fmt_bytes(b) {
            if (b === 0 || b == null) return '0 B';
            const units = ['B','KB','MB','GB'];
            const i = Math.floor(Math.log(b) / Math.log(1024));
            return (b / Math.pow(1024, i)).toFixed(i > 0 ? 2 : 0) + ' ' + units[i];
        }

        function fmt_time(sec) {
            if (!isFinite(sec) || sec <= 0) return '—';
            if (sec < 60)  return Math.round(sec) + 's';
            if (sec < 3600) return Math.round(sec / 60) + 'm ' + Math.round(sec % 60) + 's';
            return Math.round(sec / 3600) + 'h ' + Math.round((sec % 3600) / 60) + 'm';
        }

        function show(html, borderColor) {
            statusBox.style.borderLeftColor = borderColor || '#72aee6';
            statusBox.innerHTML = html;
            statusBox.style.display = 'block';
        }

        function stopPolling() {
            if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
            pollSamples = [];
        }

        function setFetching(active) {
            fetchBtn.disabled = active;
            fetchBtn.textContent = active ? 'Fetching…' : 'Fetch File';
        }

        // ── Progress bar HTML ─────────────────────────────────────────────────

        function progressHTML(downloaded, total, speed, eta) {
            const pct      = total > 0 ? Math.min(100, Math.round(downloaded / total * 100)) : 0;
            const sizeText = total > 0
                ? fmt_bytes(downloaded) + ' / ' + fmt_bytes(total) + ' (' + pct + '%)'
                : fmt_bytes(downloaded) + ' downloaded (size unknown)';

            const barFill  = total > 0
                ? `<div style="height:100%;width:${pct}%;background:#2271b1;transition:width .4s ease;border-radius:3px;"></div>`
                : `<div style="height:100%;width:40%;background:#2271b1;animation:srf_indeterminate 1.2s infinite ease-in-out;border-radius:3px;"></div>`;

            return `
                <strong>⬇ Downloading…</strong>
                <div style="margin:10px 0 4px;height:14px;background:#ddd;border-radius:3px;overflow:hidden;">${barFill}</div>
                <div style="display:flex;gap:24px;font-size:13px;color:#444;flex-wrap:wrap;">
                    <span>${sizeText}</span>
                    <span>Speed: <strong>${speed != null ? fmt_bytes(speed) + '/s' : '—'}</strong></span>
                    <span>ETA: <strong>${fmt_time(eta)}</strong></span>
                </div>`;
        }

        // ── Polling ───────────────────────────────────────────────────────────

        function startPolling(token) {
            pollSamples = [];
            pollTimer = setInterval(function() {
                const params = new URLSearchParams({
                    action: 'srf_get_progress',
                    nonce:  nonce,
                    token:  token,
                });
                fetch(ajaxUrl + '?' + params)
                    .then(r => r.json())
                    .then(function(data) {
                        if (!data || data.error) return;

                        const now  = Date.now();
                        const dl   = data.downloaded || 0;
                        const tot  = data.total || 0;

                        // Rolling window speed (last 5s)
                        pollSamples.push({time: now, bytes: dl});
                        pollSamples = pollSamples.filter(s => now - s.time < 5000);

                        let speed = null, eta = Infinity;
                        if (pollSamples.length >= 2) {
                            const oldest = pollSamples[0];
                            const dt = (now - oldest.time) / 1000;
                            speed = dt > 0 ? (dl - oldest.bytes) / dt : 0;
                            if (speed > 0 && tot > 0) eta = (tot - dl) / speed;
                        }

                        if (!data.done) {
                            show(progressHTML(dl, tot, speed, eta), '#72aee6');
                        }
                        // When done, the fetch() promise resolves and handles the final state
                    });
            }, 600);
        }

        // ── Kick off download ─────────────────────────────────────────────────

        function doFetch(url, conflictAction) {
            const token = Math.random().toString(36).slice(2) + Date.now().toString(36);

            setFetching(true);
            show(progressHTML(0, 0, null, null), '#72aee6');
            startPolling(token);

            const body = new FormData();
            body.append('action',          'srf_start_download');
            body.append('nonce',           nonce);
            body.append('url',             url);
            body.append('conflict_action', conflictAction || 'none');
            body.append('token',           token);

            fetch(ajaxUrl, {method: 'POST', body: body})
                .then(r => r.json())
                .then(function(data) {
                    stopPolling();
                    setFetching(false);

                    if (data.status === 'conflict') {
                        show(`
                            <strong>⚠ File already exists:</strong>
                            <code style="margin-left:6px;">${esc(data.filename)}</code><br>
                            <span style="font-size:13px;color:#555;">Choose how to proceed:</span>
                            <div style="margin-top:10px;display:flex;gap:10px;flex-wrap:wrap;">
                                <button class="button button-primary" id="srf_overwrite">🔄 Overwrite existing file</button>
                                <button class="button" id="srf_rename">📄 Save as <strong>${esc(data.renamed)}</strong></button>
                            </div>
                        `, '#ffb900');

                        document.getElementById('srf_overwrite').onclick = function() {
                            doFetch(url, 'overwrite');
                        };
                        document.getElementById('srf_rename').onclick = function() {
                            doFetch(url, 'rename');
                        };

                    } else if (data.status === 'success') {
                        show(`
                            ✅ <strong>Done!</strong>
                            Saved <strong>${esc(data.filename)}</strong>
                            (${fmt_bytes(data.size)}) to:<br>
                            <code style="margin-top:4px;display:inline-block;">${esc(data.path)}</code>
                        `, '#00a32a');
                        // Refresh history table without full page reload
                        setTimeout(function() { location.reload(); }, 1800);

                    } else {
                        show('❌ <strong>Error:</strong> ' + esc(data.message || 'Unknown error.'), '#d63638');
                    }
                })
                .catch(function(err) {
                    stopPolling();
                    setFetching(false);
                    show('❌ <strong>Request failed:</strong> ' + esc(err.message), '#d63638');
                });
        }

        // ── Escape helper ─────────────────────────────────────────────────────

        function esc(str) {
            return String(str)
                .replace(/&/g,'&amp;').replace(/</g,'&lt;')
                .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
        }

        // ── Events ────────────────────────────────────────────────────────────

        fetchBtn.addEventListener('click', function() {
            const url = urlInput.value.trim();
            if (!url) { urlInput.focus(); return; }
            doFetch(url, 'none');
        });

        urlInput.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') fetchBtn.click();
        });
    })();
    </script>

    <style>
    @keyframes srf_indeterminate {
        0%   { transform: translateX(-100%); width: 40%; }
        100% { transform: translateX(280%);  width: 40%; }
    }
    </style>
    <?php
}

// ── History table ────────────────────────────────────────────────────────────
function srf_render_history() {
    $history = get_option('srf_download_history', []);
    if ( empty($history) ) return;
    $history = array_reverse($history);
    ?>
    <h2 style="margin-top:28px;">Download History</h2>
    <table class="widefat striped" style="margin-top:10px;">
        <thead>
            <tr>
                <th>#</th>
                <th>Filename</th>
                <th>Source URL</th>
                <th>Saved To</th>
                <th>Date &amp; Time</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ( $history as $i => $entry ) : ?>
            <tr>
                <td><?php echo absint( count($history) - $i ); ?></td>
                <td><strong><?php echo esc_html($entry['filename']); ?></strong></td>
                <td style="word-break:break-all;max-width:280px;">
                    <a href="<?php echo esc_url($entry['url']); ?>" target="_blank" rel="noopener"><?php echo esc_html($entry['url']); ?></a>
                </td>
                <td><code><?php echo esc_html($entry['path']); ?></code></td>
                <td><?php echo esc_html($entry['time']); ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php
}
?>
