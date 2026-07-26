=== Trust Signals for HivePress ===
Contributors: chrisb
Tags: hivepress, marketplace, trust, reviews, bookings
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Surfaces verifiable trust and activity data in a sidebar block on HivePress listing and vendor pages.

== Description ==

Adds a configurable "Overview" block to HivePress listing and vendor sidebars, built entirely from data HivePress already stores: verified badge, member since, active listings, rating and review count, favourites, completed bookings, typical response time, response rate, and last active.

Signals hide automatically when the relevant extension is inactive or there is not enough data - the block omits rather than estimates. Site admins see an HTML comment in the page source explaining exactly why any enabled signal is hidden.

Configure under HivePress > Settings > Trust Signals: display locations, the block's sidebar position (set separately for listing and vendor pages), list-row or pill-chip style, card styling (border and shadow), optional Font Awesome icons, custom colours, enabled signals, and the response-statistics thresholds (grace period, minimum rate to display, slowest response time to display, and minimum conversation sample).

All data stays in your WordPress database - nothing is sent externally. The optional last-active signal stores a single timestamp per user (updated on login, on sending a message, and at most hourly while browsing logged in); the plugin also keeps a per-vendor completed-bookings counter and short-lived cached statistics. Deleting the plugin removes all of this data.

Translation-ready: all strings use the hivepress-trust-signals text domain, with a POT template in /languages for Loco Translate or Poedit.

Updates are delivered straight from the plugin's public GitHub repository: when a new release is published there, the update appears in Dashboard > Updates and on the Plugins screen just like any other plugin, and can be installed with one click.

== Changelog ==

= 1.7.4 =
* Improved: the "Check for updates" link now reports separately when GitHub cannot be reached (for example a private repository) instead of showing "up to date", making update problems easier to diagnose.

= 1.7.3 =
* Added: a "Check for updates" link on the Plugins screen that clears the cached lookup and checks GitHub immediately, instead of waiting for WordPress's twice-daily check.

= 1.7.2 =
* Added: automatic updates from GitHub. New releases published on the plugin's GitHub repository now appear as one-click updates in the WordPress dashboard, so the plugin no longer has to be reinstalled by hand.

= 1.7.1 =
* Changed: the sidebar order is now set separately for listing pages and vendor pages (two settings instead of one), so the block can sit in a different position on each. Any value saved under the previous single setting is carried over to both on upgrade.

= 1.7.0 =
* Added: a "Sidebar order" setting to control where the block appears in the sidebar - a lower number places it higher on the page, a higher number lower down (default 35, applied to both listing and vendor pages).
* Changed: the default block title is now "Overview" (existing sites keep whatever title they have already saved).
* Changed: the reply-speed wording now scales truthfully with the vendor's actual median - within an hour, a few hours, a day, a few days, a week, two weeks, or a month. The "Slowest response time to display" setting now only controls whether the signal is shown at all, so whatever is displayed always reads true at any cap.
* Improved: the completed-bookings counter is now updated as soon as a booking completes instead of on the next page view, so short booking storage periods can no longer delete a booking before it is counted (verified against the Bookings extension source).
* Fixed: in the list-rows style, a custom icon colour now renders at full strength - the muted row-label text no longer fades the icon along with it (pill chips were already correct).
* Fixed: uninstall now also removes the three response-statistics settings added in 1.6.0 (grace period, minimum rate, slowest response time to display).
* Fixed: removed a leftover extension-directory registration that referenced the Color field class deleted in 1.5.3.
* Fixed: the response-statistics query can no longer cause a PHP 8 fatal error in the rare case the database query fails.
* Changed: plugin headers now declare Requires at least, Requires PHP and Domain Path, and the version option is autoloaded (one less database query per page load).
* Changed: refreshed the translation template (correct file references and UTF-8 charset) and clarified the colour-picker setting description.


= 1.6.1 =
* Fixed: the admin diagnostics comment now quotes the configured response-time cap and response-rate threshold instead of the old hardcoded defaults.


= 1.6.0 =
* Added: three response-statistics settings so site admins can tune what was previously hardcoded - the response-rate grace period (hours a new conversation is excluded from the rate before counting as unanswered, default 48), the minimum response rate to display (default 80%, set 0 to always show), and the slowest response time to display (default 3 days). Changes take effect immediately - the stats cache is keyed to these values.
* Added: tooltips on every setting that lacked one, so each option explains what it does.


= 1.5.3 =
* Fixed: removed the plugin's own Color field class. HivePress 1.7.26 ships its own \HivePress\Fields\Color, and two classes with the same fully-qualified name collide. Colour settings now use the core field on 1.7.26+ (with a plain text fallback on older versions), and the Iris colour picker binds by field name with explicit defaults.


= 1.5.2 =
* Code quality release: zero violations against the HivePress coding-standards ruleset (full docblock coverage, comment style, reserved parameter rename) and zero PHPStan level 5 errors (removed a provably dead debug branch and redundant null checks; corrected docblock types). No behaviour changes.


= 1.5.1 =
* Fixed: message-driven cache invalidation and activity tracking now use the raw wp_insert_comment hook (the HivePress model-specific create hook only fires when the model registry resolves the type). Response-time display was unaffected as it reads the database directly.
* Added: raw transition_post_status fallback for booking cache invalidation.


= 1.5.0 =
* Added: proper colour picker (core WordPress Iris) replacing the native browser input, with per-field Default reset buttons.
* Changed: new default palette - icons #b5becf, pill text #4a5568, pill background #eaecf0 (existing installs still on the old defaults are migrated automatically; custom colours are untouched).
* Added: pill layout setting - stacked (one per line, new default) or inline (wrapped).
* Changed: uniform pill thickness regardless of icons (fixed icon metrics and min-height).
* Changed: verified pill now uses the same styling as all other pills.
* Added: uninstall.php removing all plugin options, counters, activity meta and transients on deletion.
* Added: Author URI.


= 1.4.0 =
* Changed: block title is now an h5, centred (content-title--center), for closer visual parity with sidebar headings.
* Added: native browser colour pickers for icon, pill background and pill text (custom HivePress Color field, registered via the official extensions filter).
* Added: opt-in LocalBusiness AggregateRating JSON-LD on vendor pages (off by default to avoid duplicate structured data).
* Changed: response stats now use the full message history (newest 20,000 messages), not just 90 days.
* Fixed: verified badge on listing pages now also honours vendor-level verification.
* Changed: "Member since" uses abbreviated months (e.g. Sep 2024) to prevent wrapping.
* Added: logged-in page visits now count towards "last active" (throttled to one write per hour).


= 1.3.0 =
* Fixed: block now renders on vendor profile pages (core replaces the main query there for listing pagination, so context is now read from HivePress request contexts, the same mechanism core uses).
* Added: card style toggle (border, shadow, padding matching HivePress theme sidebar widgets).
* Added: custom colour settings for icons, pill background and pill text.
* Changed: block title now uses native HivePress markup (h2.hp-section__title with content-title accent bar).
* Changed: verified accent now uses the core HivePress badge green; icons default to the muted style HivePress uses.
* Added: admin-only diagnostics comment in page source explaining hidden signals.
* Added: load_plugin_textdomain and POT template for translations.

= 1.2.1 =
* Added Settings quick link on the Plugins screen.

= 1.2.0 =
* Bookings logic verified against extension source; completed-bookings count survives the storage-period deletion cron via a monotonic counter.

= 1.1.0 =
* Display style setting (rows or pills) and optional Font Awesome icons.

= 1.0.0 =
* Initial release.
