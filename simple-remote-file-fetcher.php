<?php
/*
Plugin Name: Simple Remote File Fetcher
Description: Fetch any remote file and save it into your WordPress directory.
Version: 2.2
Author: Hfarazm Software LLC
*/

if ( ! defined( 'ABSPATH' ) ) exit;

// ── Admin menu ───────────────────────────────────────────────────────────────
add_action('admin_menu', function() {
    add_menu_page(
        'Remote File Fetcher', 'File Fetcher', 'manage_options',
        'remote-file-fetcher', 'srf_fetcher_page'
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

    if ( empty($url) || empty($token) ) {
        wp_send_json(['status' => 'error', 'message' => 'Invalid request.']);
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
        wp_send_json(['status' => 'error', 'message' => 'Cannot write to: ' . $full_path]);
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
        @unlink($full_path);
        file_put_contents($progress_file, json_encode(['done' => true, 'error' => true]), LOCK_EX);
        wp_send_json(['status' => 'error', 'message' => $err ?: "HTTP $http"]);
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
        <h2>Remote File Fetcher</h2>

        <table class="form-table">
            <tr>
                <th scope="row"><label for="srf_url">Remote File URL</label></th>
                <td>
                    <input
                        type="url"
                        id="srf_url"
                        style="width:600px;"
                        placeholder="e.g. https://file-examples.com/wp-content/storage/2017/02/zip_5MB.zip"
                    >
                    <p class="description">The file will be saved in the WordPress root directory using the filename from the URL.</p>
                </td>
            </tr>
        </table>
        <p>
            <button id="srf_fetch_btn" class="button button-primary">Fetch File</button>
        </p>

        <!-- Status / conflict / progress area -->
        <div id="srf_status" style="display:none; margin:16px 0; padding:14px 18px; border-left:4px solid #72aee6; background:#f0f6fc; max-width:680px;">
        </div>

        <?php srf_render_history(); ?>
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
                <td><?php echo count($history) - $i; ?></td>
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
