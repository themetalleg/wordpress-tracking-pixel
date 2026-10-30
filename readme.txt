=== WordPress Tracking Pixel ===
Contributors: themetalleg
Tags: email, tracking, pixel, rest-api
Requires at least: 6.6
Requires PHP: 8.2
Stable tag: 0.3.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/old-licenses/gpl-2.0.html

Persistent tracking pixels for external emails, with project-scoped API keys.

== Description ==

Manage independent tracking projects, register messages through the REST API,
resolve pixel tokens within a project, and retrieve timestamped pixel requests.
No email sending or click tracking.
See Tracking Pixel > Setup / Tutorial after activation.

Each project supports indefinite or rolling 90-day event history. Tracking links
remain valid in either mode. The plugin does not store recipient addresses,
IP addresses, or user agents and does not set tracking cookies.

Pixel requests do not prove human readership. Privacy law and consent requirements
can still apply. Web server logs and backups have independent retention policies.

== Installation ==

Upload wordpress-tracking-pixel.zip from GitHub Releases through Plugins > Add New
Plugin > Upload Plugin, activate, and open Tracking Pixel.

== Frequently Asked Questions ==

= How are updates delivered? =
Stable GitHub Releases are detected through the bundled Plugin Update Checker.
Use the standard WordPress update UI. No separate updater plugin is needed.

= Does uninstall delete tracking history? =
No. Delete a project explicitly in admin to permanently remove its data.

== Changelog ==

= 0.3.0 =
* Add an authenticated, read-only, project-scoped pixel token resolver.
* Prevent resolver responses from being cached and add project-isolation coverage.

= 0.2.0 =
* Add last_days reporting filters and resolved UTC date_range boundaries.
* Use WordPress date/time formats and timezone for admin reports and date pickers.
* Add event page sizes, range totals, and first/previous/next/last navigation.
* Add an environment-configured live tester with read-only defaults and tutorial.

= 0.1.1 =
* Fall back to bundled German for unavailable formal and regional German catalogs.
* Preserve exact locale translations and WordPress user-profile language settings.

= 0.1.0 =
* Initial project-scoped API, tracking endpoint, reporting, retention, and tutorial.