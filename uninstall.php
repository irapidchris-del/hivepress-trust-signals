<?php
/**
 * Uninstall routine.
 *
 * Settings and accumulated activity data are kept unless the site owner asked
 * for them to go. Deleting a plugin is often a reinstall in disguise - clearing
 * a problem, or swapping a broken copy for a clean one - so destruction is
 * opt-in via the "Delete All Data" setting and never the default. WordPress
 * prints its own "will also delete its data" warning on the delete screen
 * whenever an uninstall.php exists, whatever that file actually does, so the
 * setting's description says plainly that the warning does not apply here
 * unless the box is ticked.
 *
 * @package HivePress\Trust_Signals
 */

// Exit if not called by WordPress during uninstall.
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

/*
--------------------------------------------------------------------------
Regenerable runtime junk, cleared either way.

Cached vendor stats and the updater's release lookup are rebuilt on demand,
so there is nothing to lose by clearing them and orphaned rows to gain by
leaving them. Transients need their own queries: each one is stored as
_transient_{name} plus a separate _transient_timeout_{name} row, so a sweep
anchored on the plugin's own prefix never matches either.
--------------------------------------------------------------------------
*/

delete_site_transient( 'hpts_github_release' );

$wpdb->query( // phpcs:ignore WordPress.DB -- wildcard transient names cannot be placeholders; the table name derives from $wpdb and the pattern is a fixed literal.
	"DELETE FROM {$wpdb->options}
	 WHERE option_name LIKE '\_transient\_hpts\_v\_%'
	 OR option_name LIKE '\_transient\_timeout\_hpts\_v\_%'
	 OR option_name LIKE '\_transient\_hpts\_gh\_release\_%'
	 OR option_name LIKE '\_transient\_timeout\_hpts\_gh\_release\_%'
	 OR option_name LIKE '\_site\_transient\_hpts\_github\_release'
	 OR option_name LIKE '\_site\_transient\_timeout\_hpts\_github\_release'"
);

// Everything below is the owner's own configuration and accumulated data, so it
// only goes on request.
if ( ! get_option( 'hp_trust_signals_delete_data' ) ) {
	return;
}

// Plugin options (HivePress saves settings fields with the hp_ prefix).
$hpts_options = [
	'hp_trust_signals_title',
	'hp_trust_signals_locations',
	'hp_trust_signals_order',
	'hp_trust_signals_order_listing',
	'hp_trust_signals_order_vendor',
	'hp_trust_signals_style',
	'hp_trust_signals_pill_layout',
	'hp_trust_signals_icons',
	'hp_trust_signals_card',
	'hp_trust_signals_color_icon',
	'hp_trust_signals_color_pill_bg',
	'hp_trust_signals_color_pill_text',
	'hp_trust_signals_schema',
	'hp_trust_signals_items',
	'hp_trust_signals_grace_hours',
	'hp_trust_signals_rate_min',
	'hp_trust_signals_response_max_days',
	'hp_trust_signals_min_samples',
	'hpts_version',
];

foreach ( $hpts_options as $hpts_option ) {
	delete_option( $hpts_option );
}

// Monotonic completed-bookings counters (vendor post meta).
delete_metadata( 'post', 0, 'hpts_completed_bookings', '', true );

// Activity timestamps (user meta).
delete_metadata( 'user', 0, 'hpts_last_active', '', true );

/*
--------------------------------------------------------------------------
The flag itself goes last, deliberately.

If anything above fails part-way through, the flag is still set, so deleting
the plugin a second time finishes the job. Clearing it first would silently
flip the site back to "retain" with half the data already gone. It is also
kept out of the options list above for the same reason.
--------------------------------------------------------------------------
*/

delete_option( 'hp_trust_signals_delete_data' );
