<?php
/*
Plugin Name: Simple Remote File Fetcher
Description: Fetch any remote file and save it into your WordPress directory.
Version: 2.1
Author: Hfarazm Software LLC
*/

if ( ! defined( 'ABSPATH' ) ) exit;

// Add admin menu
add_action('admin_menu', function() {
    add_menu_page(
        'Remote File Fetcher',
        'File Fetcher',
        'manage_options',
        'remote-file-fetcher',
        'srf_fetcher_page'
    );
});

// Helper: find a non-conflicting filename by appending _1, _2, _3 ...
function srf_available_filename( $filename ) {
    $info     = pathinfo($filename);
    $base     = $info['filename'];
    $ext      = isset($info['extension']) ? '.' . $info['extension'] : '';
    $counter  = 1;
    do {
        $candidate = $base . '_' . $counter . $ext;
        $counter++;
    } while ( file_exists( ABSPATH . $candidate ) );
    return $candidate;
}

// Helper: perform the actual download and log to history
function srf_do_download( $url, $filename ) {
    $full_path = ABSPATH . $filename;
    $response  = wp_remote_get( $url, array(
        'timeout'  => 300,
        'stream'   => true,
        'filename' => $full_path,
    ));

    echo "<div style='margin:10px 0;padding:12px;border:1px solid #ddd;background:#f9f9f9;'>";
    if ( is_wp_error($response) ) {
        echo "&#10060; <strong>Error:</strong> " . esc_html( $response->get_error_message() );
    } else {
        echo "&#9989; File saved to: <code>" . esc_html($full_path) . "</code>";
        $history   = get_option('srf_download_history', array());
        $history[] = array(
            'url'      => $url,
            'filename' => $filename,
            'path'     => $full_path,
            'time'     => current_time('mysql'),
        );
        update_option('srf_download_history', $history);
    }
    echo "</div>";
}

// Admin page content
function srf_fetcher_page() {

    // ── Step 2a: User chose Overwrite ────────────────────────────────────────
    if ( isset($_POST['srf_action']) && $_POST['srf_action'] === 'overwrite'
         && check_admin_referer('srf_conflict_resolve') ) {

        $url      = esc_url_raw( $_POST['srf_url'] );
        $filename = sanitize_file_name( $_POST['srf_filename'] );
        // Delete existing file so wp_remote_get can write cleanly
        $existing = ABSPATH . $filename;
        if ( file_exists($existing) ) {
            wp_delete_file($existing);
        }
        srf_do_download( $url, $filename );
    }

    // ── Step 2b: User chose Rename ───────────────────────────────────────────
    elseif ( isset($_POST['srf_action']) && $_POST['srf_action'] === 'rename'
             && check_admin_referer('srf_conflict_resolve') ) {

        $url      = esc_url_raw( $_POST['srf_url'] );
        $filename = srf_available_filename( sanitize_file_name($_POST['srf_filename']) );
        srf_do_download( $url, $filename );
    }

    // ── Step 1: Initial URL submission ───────────────────────────────────────
    elseif ( isset($_POST['srf_url']) && check_admin_referer('srf_fetch_file') ) {

        $url      = esc_url_raw( trim($_POST['srf_url']) );
        $filename = basename( parse_url($url, PHP_URL_PATH) );

        if ( file_exists( ABSPATH . $filename ) ) {
            // Conflict — ask what to do
            $renamed_preview = srf_available_filename($filename);
            ?>
            <div class="wrap">
                <h2>Remote File Fetcher</h2>
                <div style="padding:14px 18px;background:#fff8e5;border-left:4px solid #ffb900;margin-bottom:16px;">
                    <strong>&#9888; File already exists:</strong>
                    <code><?php echo esc_html( ABSPATH . $filename ); ?></code><br>
                    <span style="font-size:13px;color:#555;">Choose how you want to proceed:</span>
                </div>
                <form method="post" style="display:inline;">
                    <?php wp_nonce_field('srf_conflict_resolve'); ?>
                    <input type="hidden" name="srf_url"      value="<?php echo esc_attr($url); ?>">
                    <input type="hidden" name="srf_filename" value="<?php echo esc_attr($filename); ?>">
                    <input type="hidden" name="srf_action"   value="overwrite">
                    <button type="submit" class="button button-primary">
                        &#128260; Overwrite existing file
                    </button>
                </form>
                &nbsp;
                <form method="post" style="display:inline;">
                    <?php wp_nonce_field('srf_conflict_resolve'); ?>
                    <input type="hidden" name="srf_url"      value="<?php echo esc_attr($url); ?>">
                    <input type="hidden" name="srf_filename" value="<?php echo esc_attr($filename); ?>">
                    <input type="hidden" name="srf_action"   value="rename">
                    <button type="submit" class="button button-secondary">
                        &#128196; Save as <strong><?php echo esc_html($renamed_preview); ?></strong>
                    </button>
                </form>
            </div>
            <?php
            srf_render_history();
            return;
        }

        // No conflict — download right away
        srf_do_download( $url, $filename );
    }

    // ── Default: show the main form ──────────────────────────────────────────
    ?>
    <div class="wrap">
        <h2>Remote File Fetcher</h2>
        <form method="post">
            <?php wp_nonce_field('srf_fetch_file'); ?>
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="srf_url">Remote File URL</label></th>
                    <td>
                        <input
                            type="url"
                            id="srf_url"
                            name="srf_url"
                            style="width:600px;"
                            placeholder="e.g. https://file-examples.com/wp-content/storage/2017/02/zip_5MB.zip"
                            required
                        >
                        <p class="description">Enter the full URL of the file you want to download. The file will be saved in the WordPress root directory using the same filename as in the URL.</p>
                    </td>
                </tr>
            </table>
            <?php submit_button('Fetch File'); ?>
        </form>
        <?php srf_render_history(); ?>
    </div>
    <?php
}

function srf_render_history() {
    $history = get_option('srf_download_history', array());
    if ( empty($history) ) return;
    $history = array_reverse($history);
    ?>
    <h2>Download History</h2>
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
