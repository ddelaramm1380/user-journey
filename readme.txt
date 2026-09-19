=== User Journey ===
Contributors: delaram
Tags: analytics, visitor journey, tracking, xlsx, privacy
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.2.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Privately records anonymous visitor page journeys and exports one visitor per row to a standard XLSX workbook.

== Description ==

User Journey is a lightweight WordPress plugin for recording the sequence of pages visited by anonymous visitors.

Each browser receives an anonymous UUID. Visits are sent in small batches and stored in a dedicated database table. Administrators can view summary statistics, export a standard XLSX workbook, or remove all stored journey data.

Features include:

* Anonymous visitor identification.
* Page URL, referrer, timestamp, browser, and device recording.
* One visitor per row in the XLSX export.
* Duplicate-event protection.
* Batched writes for lower database overhead.
* Automatic exclusion of administrators and common bots.
* Automatic cleanup of old records.
* No IP address, name, email address, phone number, or form-field recording.

The default retention period is 365 days. It can be changed with the `uj_retention_days` filter. Depending on applicable law, the anonymous identifier and browsing history may still be considered analytics data. Site owners should update their privacy notice and consent mechanism as appropriate.

== Installation ==

1. Upload the plugin ZIP from Plugins > Add New > Upload Plugin.
2. Activate User Journey.
3. Open User Journey from the WordPress administration menu.
4. Review the dashboard or download the XLSX report.

== Frequently Asked Questions ==

= What data does the plugin store? =

It stores an anonymous visitor UUID, page URL, referrer, visit timestamp, browser family, and device type. It does not intentionally store IP addresses or form data.

= How can I change the retention period? =

Use the `uj_retention_days` filter. The minimum accepted value is 30 days.

Example:

`add_filter( 'uj_retention_days', function () { return 60; } );`

= Where is the report? =

Administrators can open User Journey in the WordPress dashboard and select Export Excel.

== Changelog ==

= 1.2.2 =

* Replaced the dynamically assembled multi-row SQL value list with fully prepared static insert statements.
* Updated the WordPress tested version metadata.

= 1.2.1 =

* Added a GPL-compatible license declaration and a WordPress.org-compatible readme.
* Hardened request input handling and all custom-table SQL statements.
* Added short-lived caching for dashboard statistics with write-time invalidation.
* Replaced direct temporary-file output and deletion operations with WordPress filesystem APIs.
* Removed an unnecessary query-string status flag from the administration page.

= 1.2.0 =

* Added batched visit recording.
* Added duplicate-event protection without an extra lookup.
* Added scheduled retention cleanup.
* Added bot and administrator exclusions.
* Reduced request and database overhead.

== Upgrade Notice ==

= 1.2.2 =

Resolves the remaining Plugin Check SQL preparation findings and updates compatibility metadata.

= 1.2.1 =

Security, database-query, filesystem, and WordPress.org metadata compliance improvements.

= 1.2.0 =

Adds batching, retention cleanup, and database optimizations.
