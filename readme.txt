=== Simple Remote File Fetcher ===
Contributors:      hfarazm
Tags:              file fetcher, remote download, file downloader, server download, wget
Requires at least: 5.0
Tested up to:      6.9
Requires PHP:      7.2
Stable tag:        2.6
License:           GPLv2 or later
License URI:       https://www.gnu.org/licenses/gpl-2.0.html

Fetch any remote file by URL and save it directly to your WordPress server with live progress, speed, and ETA.

== Description ==

**Simple Remote File Fetcher** lets WordPress administrators download any publicly accessible file by URL directly to the server — no FTP, no terminal, no browser download required.

The file is fetched entirely server-side using PHP cURL, so even large files transfer quickly without affecting your local internet connection. A live progress bar shows download speed and estimated time remaining in real time.

= Key Features =

* Paste any direct file URL and download it to your WP root in one click
* Live progress bar with download speed and estimated time remaining
* Automatic file conflict detection — choose to overwrite or auto-rename
* Auto-rename increments safely: file_1.zip, file_2.zip, file_3.zip …
* Full download history with filename, source URL, save path, and timestamp
* Restricted to Administrators only (manage_options capability)
* No external libraries — uses PHP cURL available on all major hosts
* Nonce-protected forms to prevent CSRF attacks

= Use Cases =

* Download WordPress themes or plugins directly to the server
* Fetch large media files or ZIP archives without browser timeouts
* Pull files from remote storage or CDN directly onto your server
* Transfer files between servers without FTP

== Installation ==

1. Upload the `simple-remote-file-fetcher` folder to `/wp-content/plugins/`
2. Activate the plugin through the **Plugins** menu in WordPress
3. Go to **File Fetcher** in the WordPress admin sidebar

= Manual Installation =

1. Download the plugin zip
2. Go to **Plugins → Add New → Upload Plugin**
3. Upload the zip and click **Install Now**
4. Click **Activate Plugin**

== Frequently Asked Questions ==

= Where is the file saved? =

Files are saved to the WordPress root directory (ABSPATH) using the same filename as in the URL.

= What happens if a file with the same name already exists? =

The plugin detects the conflict and asks you to either overwrite the existing file or save the new file under an incremented name (e.g. file_1.zip).

= Does it work with large files? =

Yes. The plugin uses PHP cURL with a 300-second timeout and streams the file directly to disk, so memory usage stays low even for very large files.

= Why is cURL used instead of wp_remote_get? =

cURL is used because it supports a real-time progress callback, which powers the live progress bar, speed, and ETA display. wp_remote_get is a blocking call with no progress feedback.

= Is it secure? =

Yes. The plugin is restricted to Administrators only, all forms are nonce-protected, and all inputs are sanitized and escaped before use.

= My server can't connect to the remote URL. What do I do? =

Some hosting environments block outbound HTTP requests. Contact your host and ask them to allow outbound cURL connections.

== Screenshots ==

1. Main plugin page with URL input and live progress bar
2. File conflict prompt — overwrite or rename
3. Download history table

== Changelog ==

= 2.6 =
* Fixed: replaced all cURL calls with wp_remote_get() + wp_remote_head() per Plugin Check
* Fixed: replaced parse_url() with wp_parse_url()
* Fixed: replaced fopen()/fclose() with wp_remote_get streaming (no direct file ops)
* Fixed: added wp_unslash() to all $_POST/$_GET inputs before sanitization
* Fixed: replaced preg_replace token sanitization with sanitize_key()
* Fixed: progress polling now reads filesize() of destination file instead of cURL callbacks
* Fixed: Tested up to updated to 6.9

= 2.5 =
* Fixed: replaced @unlink with wp_delete_file() per Plugin Check guidelines
* Fixed: added allowlist validation for conflict_action parameter
* Fixed: wrapped all user-facing strings in __() for i18n/translation support
* Fixed: escaped integer outputs with absint() in admin page and history table

= 2.4 =
* Added complete WordPress plugin header fields (Plugin URI, License, Text Domain, Requires PHP, Tested up to)
* Added author URI

= 2.3 =
* Added right sidebar with How It Works steps, PayPal donation box, Fiverr and Upwork hire links
* Added brand SVG logos for PayPal, Fiverr, and Upwork

= 2.2 =
* Replaced blocking page-reload download with AJAX-based download
* Added live progress bar showing bytes downloaded, total size, speed, and ETA
* Progress tracked via PHP cURL progress callback writing to a temp file
* Polling endpoint reads progress and sends it to the browser every 600ms

= 2.1 =
* Added file conflict detection before download
* Prompt to overwrite or rename (auto-increments to file_1.zip, file_2.zip, etc.)

= 2.0 =
* Removed manual save path input — filename now extracted automatically from URL
* Added download history table (logged in wp_options)
* Added nonce security to all forms
* Wider URL input with placeholder example

= 1.0 =
* Initial release

== Upgrade Notice ==

= 2.4 =
Full plugin header compliance for WordPress.org submission. No functional changes.

== Privacy Policy ==

This plugin does not collect, store, or transmit any personal data. Download history (URL, filename, save path, timestamp) is stored locally in your WordPress database and is never sent to any external service.
