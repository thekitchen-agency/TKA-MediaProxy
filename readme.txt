=== TKA Media Proxy ===
Contributors: TKA
Tags: media, proxy, local, development, uploads
Requires at least: 6.2
Tested up to: 7.0
Stable tag: 1.0.0
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

On-demand media proxying and mirroring for local WordPress development environments to solve SSD bloat.

== Description ==

TKA Media Proxy is an enterprise-grade developer tool plugin designed to eliminate the need to download massive production `uploads/` directories. 

When active in a local development environment, it intercepts 404 requests for missing local media assets and proxies them from the live production site on-demand. Additionally, it writes the binary payload to the exact folder path locally, so subsequent requests for the same image bypass PHP entirely and load instantly.

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/tka-mediaproxy` directory.
2. Activate the plugin through the 'Plugins' screen in WordPress.
3. Configure the remote production URL using the WP-CLI command:
   `wp tka-proxy configure <production_url>`
4. Verify the connection by running:
   `wp tka-proxy status`

== Changelog ==

= 1.0.0 =
* Initial release of TKA Media Proxy.
* Added early request interception (`plugins_loaded` hook at priority 1).
* Added safe environment detection to restrict execution to local environments.
* Added streaming HTTP client with timeout settings, SSL verification preferences, and browser User-Agent spoofing.
* Added smart path translation fallback between Bedrock (`/app/uploads/`) and standard WP (`/wp-content/uploads/`) paths.
* Added WP-CLI command suite under the `wp tka-proxy` namespace with `configure`, `status`, and `clear` subcommands.
