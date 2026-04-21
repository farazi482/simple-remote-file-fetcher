# Simple Remote File Fetcher

A lightweight WordPress plugin that lets site administrators fetch any remote file via URL and save it directly into the WordPress directory — without needing FTP or shell access.

---

## Features

- Fetch any publicly accessible file by URL (ZIP, PHP, images, etc.)
- Save the file to any path relative to the WordPress root
- Simple admin interface under the WordPress dashboard
- Uses WordPress's built-in `wp_remote_get` for reliable, timeout-tolerant downloads
- Restricted to administrators (`manage_options` capability)

---

## Requirements

| Requirement | Minimum Version |
|---|---|
| WordPress | 5.0 or higher |
| PHP | 7.2 or higher |
| PHP `allow_url_fopen` | Must be enabled (or cURL available) |
| User Role | Administrator |

> Your server must be able to make outbound HTTP/HTTPS requests. Some managed or restricted hosts block these — check with your host if downloads fail.

---

## Installation

### Option 1: Manual Upload (Recommended)

1. Download or clone this repository.
2. Copy the `simple-remote-file-fetcher.php` file into your WordPress plugins directory:
   ```
   /wp-content/plugins/simple-remote-file-fetcher/
   ```
3. Log in to your WordPress admin panel.
4. Go to **Plugins → Installed Plugins**.
5. Find **Simple Remote File Fetcher** and click **Activate**.

### Option 2: Upload via WordPress Admin

1. Download the plugin file.
2. In WordPress admin, go to **Plugins → Add New → Upload Plugin**.
3. Upload the `.php` file (or a ZIP containing it).
4. Click **Install Now**, then **Activate**.

---

## Usage

1. After activation, go to **File Fetcher** in the WordPress admin sidebar.
2. Fill in the form:
   - **Remote File URL** — the full URL of the file you want to download.
     Example: `https://example.com/files/package.zip`
   - **Save Path (relative to WP root)** — where to save the file on your server, relative to your WordPress installation root.
     Example: `wp-content/uploads/package.zip`
3. Click **Fetch File**.
4. A success message will show the full saved path, or an error message if the download failed.

### Save Path Examples

| Intent | Save Path |
|---|---|
| Save to uploads folder | `wp-content/uploads/myfile.zip` |
| Save to a custom folder | `wp-content/myfolder/script.php` |
| Save to WP root | `myfile.txt` |

> **Note:** The path is always relative to the WordPress root (`ABSPATH`). Leading slashes are stripped automatically.

---

## Security Notes

- Only users with the `manage_options` capability (Administrators) can access this plugin.
- The URL is sanitized with `esc_url_raw` and the save path with `sanitize_text_field` before use.
- Be cautious about the paths you specify — saving executable files (`.php`) to web-accessible locations can be a security risk if the source URL is untrusted.

---

## Troubleshooting

**Download fails with a timeout error**
- The default timeout is set to 300 seconds. For very large files, your server's PHP `max_execution_time` may still cut it short. Increase it in `php.ini` or `.htaccess` if needed.

**"Error: Could not connect" or similar**
- Your server may be blocking outbound HTTP requests. Contact your hosting provider.

**File is not appearing at the expected path**
- Double-check the save path is relative to the WP root, not an absolute server path.
- Ensure the destination directory exists and is writable by the web server.

---

## Author

**Hfarazm Software LLC**

---

## License

This plugin is released under the [GPLv2 or later](https://www.gnu.org/licenses/gpl-2.0.html) license, consistent with WordPress plugin standards.
