<?php
/**
 * Uninstall routine: removes all plugin data (options, cached counters,
 * activity meta, and transients). Runs only when the plugin is deleted via
 * the WordPress admin.
 *
 * @package HivePress\Trust_Signals
 */

// Exit if not called by WordPress during uninstall.
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// Plugin options (HivePress saves settings fields with the hp_ prefix).
$hpts_options = [
	'hp_trust_signals_title',
	'hp_trust_signals_locations',
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

// Cached vendor stats (transients, including timeouts).
global $wpdb;

$wpdb->query( // phpcs:ignore WordPress.DB -- custom analytics tables / source-verified hp_ schema; table names derive from $wpdb->prefix and cannot be placeholders; caching by design at the transient layer.
	"DELETE FROM {$wpdb->options}
	 WHERE option_name LIKE '\_transient\_hpts\_v\_%'
	 OR option_name LIKE '\_transient\_timeout\_hpts\_v\_%'"
);
