<?php
/*
Plugin Name: Simple Remote File Fetcher
Description: Fetch any remote file and save it into your WordPress directory.
Version: 2.0
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

// Admin page content
function srf_fetcher_page() {
    if ( isset($_POST['srf_url']) && check_admin_referer('srf_fetch_file') ) {
        $url       = esc_url_raw( trim($_POST['srf_url']) );
        $filename  = basename( parse_url($url, PHP_URL_PATH) );
        $full_path = ABSPATH . $filename;

        echo "<div style='margin:10px 0;padding:10px;border:1px solid #ddd;background:#f9f9f9;'>";
        echo "<strong>Downloading file...</strong><br>";

        $response = wp_remote_get($url, array(
            'timeout'  => 300,
            'stream'   => true,
            'filename' => $full_path,
        ));

        if ( is_wp_error($response) ) {
            echo "&#10060; Error: " . esc_html( $response->get_error_message() );
        } else {
            echo "&#9989; File saved to: <code>" . esc_html($full_path) . "</code>";

            // Save to history
            $history   = get_option('srf_download_history', array());
            $history[] = array(
                'url'       => $url,
                'filename'  => $filename,
                'path'      => $full_path,
                'time'      => current_time('mysql'),
            );
            update_option('srf_download_history', $history);
        }
        echo "</div>";
    }
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

        <?php
        $history = get_option('srf_download_history', array());
        if ( ! empty($history) ) :
            $history = array_reverse($history); // newest first
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
                    <td style="word-break:break-all;max-width:300px;"><a href="<?php echo esc_url($entry['url']); ?>" target="_blank" rel="noopener"><?php echo esc_html($entry['url']); ?></a></td>
                    <td><code><?php echo esc_html($entry['path']); ?></code></td>
                    <td><?php echo esc_html($entry['time']); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
    <?php
}
?>
