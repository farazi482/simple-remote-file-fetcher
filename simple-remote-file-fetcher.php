<?php
/*
Plugin Name: Simple Remote File Fetcher
Description: Fetch any remote file and save it into your WordPress directory.
Version: 1.0
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
    if ( isset($_POST['srf_url']) && isset($_POST['srf_path']) ) {
        $url  = esc_url_raw($_POST['srf_url']);
        $path = sanitize_text_field($_POST['srf_path']);
        $full_path = ABSPATH . ltrim($path, '/\\'); // Ensure relative to WP root

        echo "<div style='margin:10px 0;padding:10px;border:1px solid #ddd;'>";
        echo "<strong>Downloading file...</strong><br>";

        $response = wp_remote_get($url, array(
            'timeout' => 300,
            'stream'  => true,
            'filename'=> $full_path,
        ));

        if ( is_wp_error($response) ) {
            echo "❌ Error: " . $response->get_error_message();
        } else {
            echo "✅ File saved to: <code>$full_path</code>";
        }
        echo "</div>";
    }
    ?>
    <div class="wrap">
        <h2>Remote File Fetcher</h2>
        <form method="post">
            <table class="form-table">
                <tr>
                    <th>Remote File URL:</th>
                    <td><input type="text" name="srf_url" size="50" required></td>
                </tr>
                <tr>
                    <th>Save Path (relative to WP root):</th>
                    <td><input type="text" name="srf_path" size="50" placeholder="wp-content/uploads/myfile.zip" required></td>
                </tr>
            </table>
            <?php submit_button('Fetch File'); ?>
        </form>
    </div>
    <?php
}
?>