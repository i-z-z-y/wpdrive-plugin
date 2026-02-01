=== WPDrive Sync ===
Contributors: wpdrive
Tags: file sync, private files, download, share link
Requires at least: 6.0
Tested up to: 6.6
Stable tag: 1.0.0
License: GPLv2 or later

WPDrive Sync provides:
- Private file storage (tries ../wpdrive-data, falls back to wp-content/wpdrive-private on shared hosts)
- Chunked uploads for a Python sync client
- Multi-device sync (adds/updates bidirectional; deletes propagate with conflict-preserve rule)
- A browsable file tree via shortcode [wpdrive_browser]
- Password-protected share links

Installation:
1) Upload the plugin folder to /wp-content/plugins/wpdrive-plugin/ and activate
2) WP Admin → WPDrive to configure storage & create shares
3) Create a user with role "WPDrive Sync" and generate an Application Password
4) Use the Python client to sync
