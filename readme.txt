=== Trust Signals for HivePress ===
Contributors: chrisb
Tags: hivepress, marketplace, trust, reviews, bookings
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.5.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Surfaces verifiable trust and activity data in a sidebar block on HivePress listing and vendor pages.

== Description ==

Adds a configurable "Trust & activity" block to HivePress listing and vendor sidebars, built entirely from data HivePress already stores: verified badge, member since, active listings, rating and review count, favourites, completed bookings, typical response time, response rate, and last active.

Signals hide automatically when the relevant extension is inactive or there is not enough data - the block omits rather than estimates. Site admins see an HTML comment in the page source explaining exactly why any enabled signal is hidden.

Configure under HivePress > Settings > Trust Signals: display locations, list-row or pill-chip style, card styling (border and shadow), optional Font Awesome icons, custom colours, enabled signals, and the minimum conversation sample for response stats.

Translation-ready: all strings use the hivepress-trust-signals text domain, with a POT template in /languages for Loco Translate or Poedit.

== Changelog ==

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
