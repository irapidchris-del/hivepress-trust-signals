<?php
/**
 * Plugin Name: Trust Signals for HivePress
 * Description: Surfaces verifiable trust and activity data (response time, completed bookings, reviews, favourites and more) in a sidebar block on HivePress listing and vendor pages.
 * Version: 1.7.0
 * Author: ChrisB @ HivePress Community
 * Author URI: https://community.hivepress.io/u/chrisb
 * Requires Plugins: hivepress
 * License: GPLv2 or later
 * Text Domain: hivepress-trust-signals
 *
 * @package HivePress\Trust_Signals
 *
 * DATA PROVENANCE (verified against HivePress source, July 2026):
 * - Messages:  wp_comments rows, comment_type 'hp_message'; sender = user_id,
 *   recipient = comment_karma, sent date = comment_date(_gmt).
 * - Favorites: wp_comments rows, comment_type 'hp_favorite'; listing = comment_post_ID.
 * - Reviews:   aggregate cached by the Reviews extension as post meta 'hp_rating'
 *   and 'hp_rating_count' on both listings and vendors.
 * - Listings:  post type 'hp_listing'; vendor relation = post_parent.
 * - Vendors:   post type 'hp_vendor'; user relation = post_author.
 * - Verified:  post meta 'hp_verified' on listings and vendors (core field).
 * - Bookings (premium, verified from source July 2026): post type 'hp_booking';
 *   listing = post_parent, client = post_author, hp_start_time/hp_end_time are
 *   unix-timestamp meta; statuses: publish=Confirmed, draft=Unpaid, pending=Pending,
 *   trash=Canceled. A monotonic vendor-meta counter protects the completed count
 *   against the extension's optional storage-period deletion cron.
 */

defined( 'ABSPATH' ) || exit;

define( 'HPTS_VERSION', '1.7.0' );

// Register this plugin directory with HivePress so core autoloads our classes
// (includes/fields/class-color.php) - the official third-party extension
// pattern via the hivepress/v1/extensions filter (verified in core).
add_filter(
	'hivepress/v1/extensions',
	/**
	 * @param array<int, string> $extensions Extension directories.
	 * @return array<int, string>
	 */
	function ( $extensions ) {
		$extensions[] = __DIR__;

		return $extensions;
	}
);
define( 'HPTS_CACHE_TTL', 12 * HOUR_IN_SECONDS );
define( 'HPTS_MSG_ROW_LIMIT', 20000 );

/*
--------------------------------------------------------------------------
Bootstrap.
--------------------------------------------------------------------------
*/

add_action( 'plugins_loaded', 'hpts_init' );

/**
 * Bootstraps the plugin once all plugins are loaded.
 *
 * @return void
 */
function hpts_init() {
	load_plugin_textdomain( 'hivepress-trust-signals', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

	if ( ! function_exists( 'hivepress' ) ) {
		add_action(
			'admin_notices',
			function () {
				echo '<div class="notice notice-error"><p>' . esc_html__( 'Trust Signals for HivePress requires the HivePress plugin to be active.', 'hivepress-trust-signals' ) . '</p></div>';
			}
		);
		return;
	}

	hpts_maybe_upgrade();

	// Settings tab under HivePress > Settings.
	add_filter( 'hivepress/v1/settings', 'hpts_register_settings' );

	// WordPress (Iris) colour picker on the HivePress settings screen.
	add_action( 'admin_enqueue_scripts', 'hpts_admin_scripts' );

	// Sidebar block injection.
	add_filter( 'hivepress/v1/templates/listing_view_page', 'hpts_inject_listing_block' );
	add_filter( 'hivepress/v1/templates/vendor_view_page', 'hpts_inject_vendor_block' );

	// Settings quick link on the Plugins screen. HivePress resolves the
	// current settings tab from the 'tab' query arg (verified in core).
	add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'hpts_plugin_action_links' );

	// Cache invalidation (hooks fire only if the relevant extension is active;
	// registering them unconditionally is harmless).
	// Raw core hook: verified in the core Hook component, model-specific
	// create hooks fire only when the model registry resolves the comment
	// type, so wp_insert_comment with the verified hp_message schema is
	// strictly more reliable. {model}/update_status for post models fires
	// with ( $id, $new_status, $old_status, $object ).
	add_action( 'wp_insert_comment', 'hpts_on_comment_insert', 10, 2 );
	add_action( 'transition_post_status', 'hpts_on_booking_transition', 10, 3 );
	add_action( 'hivepress/v1/models/booking/update_status', 'hpts_on_booking_change', 10, 4 );
	add_action( 'hivepress/v1/models/booking/create', 'hpts_on_booking_change', 10, 4 );
	add_action( 'hivepress/v1/models/listing/update_status', 'hpts_on_listing_change', 10, 1 );

	// Fires ~12h after a confirmed booking's end time (scheduled by the
	// Bookings extension); refresh the vendor's cached stats promptly.
	add_action( 'hivepress/v1/models/booking/complete', 'hpts_on_booking_complete' );

	// "Last active" tracking (self-collected from install time onwards):
	// logins, sent messages, and throttled logged-in page visits, so current
	// browsing counts as activity.
	add_action( 'wp_login', 'hpts_track_login', 10, 2 );
	add_action( 'wp', 'hpts_track_visit' );
}

/*
--------------------------------------------------------------------------
Settings.
--------------------------------------------------------------------------
*/

/**
 * One-time upgrade routine. 1.5.0 changed the colour defaults; installs still
 * on the old defaults are migrated so the new palette applies, while any
 * custom-picked colours are left untouched.
 *
 * @return void
 */
function hpts_maybe_upgrade() {
	$stored = get_option( 'hpts_version' );

	if ( HPTS_VERSION === $stored ) {
		return;
	}

	if ( ! $stored || version_compare( (string) $stored, '1.5.0', '<' ) ) {
		$map = [
			'hp_trust_signals_color_icon'      => [ '#888888', '#b5becf' ],
			'hp_trust_signals_color_pill_bg'   => [ '#f5f5f5', '#eaecf0' ],
			'hp_trust_signals_color_pill_text' => [ '#313a47', '#4a5568' ],
		];

		foreach ( $map as $option => $colors ) {
			if ( get_option( $option ) === $colors[0] ) {
				update_option( $option, $colors[1] );
			}
		}
	}

	update_option( 'hpts_version', HPTS_VERSION, false );
}

/**
 * Enqueues the core WordPress (Iris) colour picker on the HivePress settings
 * screen and binds it to our colour fields.
 *
 * @return void
 */
function hpts_admin_scripts() {
	$page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

	if ( 'hp_settings' !== $page ) {
		return;
	}

	wp_enqueue_style( 'wp-color-picker' );
	wp_enqueue_script( 'wp-color-picker' );
	wp_add_inline_script(
		'wp-color-picker',
		'jQuery(function($){' .
		'$("input[name=hp_trust_signals_color_icon]").wpColorPicker({defaultColor:"#b5becf"});' .
		'$("input[name=hp_trust_signals_color_pill_bg]").wpColorPicker({defaultColor:"#eaecf0"});' .
		'$("input[name=hp_trust_signals_color_pill_text]").wpColorPicker({defaultColor:"#4a5568"});' .
		'});'
	);
}

/**
 * Gets the available signal options.
 *
 * @return array<string, string>
 */
function hpts_signal_options() {
	return [
		'verified'           => __( 'Verified badge', 'hivepress-trust-signals' ),
		'member_since'       => __( 'Member since', 'hivepress-trust-signals' ),
		'listings_count'     => __( 'Active listings count', 'hivepress-trust-signals' ),
		'rating'             => __( 'Rating & review count (requires Reviews)', 'hivepress-trust-signals' ),
		'favorites'          => __( 'Favourites count (requires Favorites)', 'hivepress-trust-signals' ),
		'completed_bookings' => __( 'Completed bookings (requires Bookings)', 'hivepress-trust-signals' ),
		'response_time'      => __( 'Typical response time (requires Messages)', 'hivepress-trust-signals' ),
		'response_rate'      => __( 'Response rate (requires Messages)', 'hivepress-trust-signals' ),
		'last_active'        => __( 'Last active (tracked from plugin activation)', 'hivepress-trust-signals' ),
	];
}

/**
 * Gets the default enabled signals.
 *
 * @return array<int, string>
 */
function hpts_default_signals() {
	return [ 'verified', 'member_since', 'listings_count', 'rating', 'favorites', 'completed_bookings', 'response_time' ];
}

/**
 * Registers the settings tab.
 *
 * @param array<string, mixed> $settings Settings configuration.
 * @return array<string, mixed>
 */
function hpts_register_settings( $settings ) {
	// Core ships its own Fields\Color from HivePress 1.7.26 (verified); use it
	// natively and fall back to a plain text field on older versions. Our own
	// Color class was removed in 1.5.3 to avoid the FQCN collision with core.
	$color_type = class_exists( '\HivePress\Fields\Color' ) ? 'color' : 'text';

	$settings['trust_signals'] = [
		'title'    => __( 'Trust Signals', 'hivepress-trust-signals' ),
		'_order'   => 900,

		'sections' => [
			'display' => [
				'title'  => __( 'Display', 'hivepress-trust-signals' ),
				'_order' => 10,

				'fields' => [
					'trust_signals_title'           => [
						'label'      => __( 'Block title', 'hivepress-trust-signals' ),
						'type'       => 'text',
						'default'    => __( 'Trust & activity', 'hivepress-trust-signals' ),
						'max_length' => 64,
						'_order'     => 10,
					],

					'trust_signals_locations'       => [
						'label'   => __( 'Show on', 'hivepress-trust-signals' ),
						'type'    => 'checkboxes',
						'default' => [ 'listing_page', 'vendor_page' ],
						'_order'  => 20,

						'options' => [
							'listing_page' => __( 'Listing pages (sidebar)', 'hivepress-trust-signals' ),
							'vendor_page'  => __( 'Vendor profile pages (sidebar)', 'hivepress-trust-signals' ),
						],
					],

					'trust_signals_style'           => [
						'label'       => __( 'Display style', 'hivepress-trust-signals' ),
						'description' => __( 'Rows show one signal per line; pill chips show compact rounded labels.', 'hivepress-trust-signals' ),
						'type'        => 'select',
						'default'     => 'rows',
						'_order'      => 30,

						'options'     => [
							'rows'  => __( 'List rows (label and value)', 'hivepress-trust-signals' ),
							'pills' => __( 'Pill chips', 'hivepress-trust-signals' ),
						],
					],

					'trust_signals_pill_layout'     => [
						'label'       => __( 'Pill layout', 'hivepress-trust-signals' ),
						'description' => __( 'Applies when the display style is pill chips.', 'hivepress-trust-signals' ),
						'type'        => 'select',
						'default'     => 'stacked',
						'_order'      => 35,

						'options'     => [
							'stacked' => __( 'Stacked (one per line)', 'hivepress-trust-signals' ),
							'inline'  => __( 'Inline (wrapped)', 'hivepress-trust-signals' ),
						],
					],

					'trust_signals_icons'           => [
						'label'       => __( 'Show icons', 'hivepress-trust-signals' ),
						'description' => __( 'Uses the Font Awesome 5 solid icons bundled with HivePress core. If your site subsets or replaces Font Awesome, make sure the required glyphs are included.', 'hivepress-trust-signals' ),
						'type'        => 'checkbox',
						'_order'      => 40,
					],

					'trust_signals_card'            => [
						'label'   => __( 'Card style', 'hivepress-trust-signals' ),
						'caption' => __( 'Add border, shadow and padding (matches HivePress theme sidebar widgets)', 'hivepress-trust-signals' ),
						'type'    => 'checkbox',
						'default' => true,
						'_order'  => 50,
					],

					'trust_signals_color_icon'      => [
						'label'       => __( 'Icon colour', 'hivepress-trust-signals' ),
						'description' => __( 'Colours use the HivePress grey palette by default. Click a swatch to change it or use the reset link to restore the default.', 'hivepress-trust-signals' ),
						'type'        => $color_type,
						'default'     => '#b5becf',
						'attributes'  => [ 'data-default-color' => '#b5becf' ],
						'_order'      => 60,
					],

					'trust_signals_color_pill_bg'   => [
						'label'      => __( 'Pill background colour', 'hivepress-trust-signals' ),
						'type'       => $color_type,
						'default'    => '#eaecf0',
						'attributes' => [ 'data-default-color' => '#eaecf0' ],
						'_order'     => 70,
					],

					'trust_signals_color_pill_text' => [
						'label'      => __( 'Pill text colour', 'hivepress-trust-signals' ),
						'type'       => $color_type,
						'default'    => '#4a5568',
						'attributes' => [ 'data-default-color' => '#4a5568' ],
						'_order'     => 80,
					],

					'trust_signals_schema'          => [
						'label'       => __( 'Rating schema markup', 'hivepress-trust-signals' ),
						'caption'     => __( 'Output LocalBusiness AggregateRating JSON-LD on vendor pages', 'hivepress-trust-signals' ),
						'description' => __( 'Leave disabled if another plugin already outputs rating structured data for vendors - the HivePress SEO extension does this automatically - as duplicates can cause Search Console warnings.', 'hivepress-trust-signals' ),
						'type'        => 'checkbox',
						'_order'      => 90,
					],
				],
			],

			'signals' => [
				'title'  => __( 'Signals', 'hivepress-trust-signals' ),
				'_order' => 20,

				'fields' => [
					'trust_signals_items'             => [
						'label'       => __( 'Enabled signals', 'hivepress-trust-signals' ),
						'description' => __( 'Signals that depend on an extension are hidden automatically if that extension is inactive or there is not enough data. Response time and rate thresholds are configurable below. Signals are omitted rather than estimated.', 'hivepress-trust-signals' ),
						'type'        => 'checkboxes',
						'default'     => hpts_default_signals(),
						'options'     => hpts_signal_options(),
						'_order'      => 10,
					],

					'trust_signals_grace_hours'       => [
						'label'       => __( 'Response rate grace period (hours)', 'hivepress-trust-signals' ),
						'description' => __( 'Conversations opened within this many hours are left out of the response rate, so a brand-new unanswered message does not lower a vendor\'s rate before they have had a fair chance to reply. Default: 48.', 'hivepress-trust-signals' ),
						'type'        => 'number',
						'min_value'   => 0,
						'max_value'   => 720,
						'default'     => 48,
						'_order'      => 30,
					],

					'trust_signals_rate_min'          => [
						'label'       => __( 'Minimum response rate to display (%)', 'hivepress-trust-signals' ),
						'description' => __( 'The response rate is only shown when it is at least this percentage, so a low rate is not advertised on the vendor\'s own page. Set to 0 to always show it. Default: 80.', 'hivepress-trust-signals' ),
						'type'        => 'number',
						'min_value'   => 0,
						'max_value'   => 100,
						'default'     => 80,
						'_order'      => 40,
					],

					'trust_signals_response_max_days' => [
						'label'       => __( 'Slowest response time to display (days)', 'hivepress-trust-signals' ),
						'description' => __( 'The response time signal is hidden entirely when a vendor\'s typical first reply is slower than this many days. The displayed wording always comes from the true value (within an hour, a day, a few days, a week, two weeks, a month), so whatever is shown reads true at any setting. Default: 3.', 'hivepress-trust-signals' ),
						'type'        => 'number',
						'min_value'   => 1,
						'max_value'   => 30,
						'default'     => 3,
						'_order'      => 50,
					],

					'trust_signals_min_samples'       => [
						'label'       => __( 'Minimum conversations', 'hivepress-trust-signals' ),
						'description' => __( 'Response time and rate are only shown once a vendor has received at least this many conversations.', 'hivepress-trust-signals' ),
						'type'        => 'number',
						'min_value'   => 1,
						'max_value'   => 50,
						'default'     => 3,
						'_order'      => 20,
					],
				],
			],
		],
	];

	return $settings;
}

/**
 * Reads a HivePress-prefixed option. Falls back to the default only when the
 * option does not exist at all - an admin deliberately clearing a checkbox
 * group (saved as an empty value) must be respected, not overridden.
 *
 * @param string $name Option name without the hp_ prefix.
 * @param mixed  $fallback Value when the option is absent.
 * @return mixed
 */
function hpts_get_option( $name, $fallback ) {
	$value = get_option( 'hp_' . $name, null );

	if ( null === $value || false === $value ) {
		return $fallback;
	}

	if ( is_array( $fallback ) && ! is_array( $value ) ) {
		return '' === $value ? [] : (array) $value;
	}

	return $value;
}

/**
 * Adds the Settings link on the Plugins screen.
 *
 * @param array<int|string, string> $links Action links.
 * @return array<int|string, string>
 */
function hpts_plugin_action_links( $links ) {
	array_unshift(
		$links,
		'<a href="' . esc_url( admin_url( 'admin.php?page=hp_settings&tab=trust_signals' ) ) . '">' . esc_html__( 'Settings', 'hivepress-trust-signals' ) . '</a>'
	);

	return $links;
}

/*
--------------------------------------------------------------------------
Template injection.
--------------------------------------------------------------------------
*/

/**
 * Gets the enabled display locations.
 *
 * @return array<int, string>
 */
function hpts_locations() {
	$locations = hpts_get_option( 'trust_signals_locations', [ 'listing_page', 'vendor_page' ] );
	return is_array( $locations ) ? $locations : [];
}

/**
 * Gets the trust signals block arguments.
 *
 * @param int $order Block order.
 * @return array<string, mixed>
 */
function hpts_block_args( $order ) {
	return [
		'type'     => 'callback',
		'callback' => 'hpts_render_block',
		'params'   => [],
		'return'   => true,
		'_order'   => $order,
	];
}

/**
 * Injects the block into the listing page sidebar.
 *
 * @param array<string, mixed> $template Template arguments.
 * @return array<string, mixed>
 */
function hpts_inject_listing_block( $template ) {
	if ( ! in_array( 'listing_page', hpts_locations(), true ) ) {
		return $template;
	}

	// Sidebar order on listing pages: attributes (10), actions (20), vendor card (30).
	// 35 places trust signals directly beneath the vendor card, where they read as
	// credentials for the person you are about to book.
	return hivepress()->helper->merge_trees(
		$template,
		[
			'blocks' => [
				'page_sidebar' => [
					'blocks' => [
						'trust_signals' => hpts_block_args( 35 ),
					],
				],
			],
		]
	);
}

/**
 * Injects the block into the vendor page sidebar.
 *
 * @param array<string, mixed> $template Template arguments.
 * @return array<string, mixed>
 */
function hpts_inject_vendor_block( $template ) {
	if ( ! in_array( 'vendor_page', hpts_locations(), true ) ) {
		return $template;
	}

	// Vendor sidebar: summary container is order 10; 15 slots us straight after it.
	return hivepress()->helper->merge_trees(
		$template,
		[
			'blocks' => [
				'page_sidebar' => [
					'blocks' => [
						'trust_signals' => hpts_block_args( 15 ),
					],
				],
			],
		]
	);
}

/*
--------------------------------------------------------------------------
Rendering.
--------------------------------------------------------------------------
*/

/**
 * Named function (not a method) because HivePress's Callback block validates
 * callbacks with function_exists().
 *
 * @return string
 */
function hpts_render_block() {
	$context = hpts_get_context();

	if ( ! $context ) {
		return '';
	}

	$collected = hpts_collect_signals( $context['vendor_id'], $context['listing_id'] );
	$signals   = $collected['signals'];

	// Admin-only diagnostics (HTML comment, invisible to visitors) so hidden
	// signals can be understood without guesswork.
	$debug = '';

	if ( current_user_can( 'manage_options' ) && $collected['debug'] ) {
		$debug = "\n<!-- Trust Signals debug (admins only):\n- " . implode( "\n- ", array_map( 'esc_html', $collected['debug'] ) ) . "\n-->\n";
	}

	if ( ! $signals ) {
		return $debug;
	}

	$title  = hpts_get_option( 'trust_signals_title', __( 'Trust & activity', 'hivepress-trust-signals' ) );
	$style  = 'pills' === hpts_get_option( 'trust_signals_style', 'rows' ) ? 'pills' : 'rows';
	$layout = 'inline' === hpts_get_option( 'trust_signals_pill_layout', 'stacked' ) ? 'inline' : 'stacked';
	$icons  = (bool) hpts_get_option( 'trust_signals_icons', false );
	$card   = (bool) hpts_get_option( 'trust_signals_card', true );

	$classes = 'hpts-block hpts-block--' . $style . ' hp-widget widget';

	if ( 'pills' === $style ) {
		$classes .= ' hpts-pills--' . $layout;
	}

	if ( $card ) {
		// widget--sidebar is the HivePress theme card class (border, shadow,
		// padding); hpts-block--card provides the same values as a fallback.
		$classes .= ' widget--sidebar hpts-block--card';
	}

	$output  = hpts_inline_css();
	$output .= '<div class="' . esc_attr( $classes ) . '">';

	if ( $title ) {
		// h5 for sidebar-appropriate sizing; content-title provides the theme
		// accent bar, and content-title--center centres both text and bar.
		$output .= '<h5 class="hpts-block__title content-title content-title--center">' . esc_html( $title ) . '</h5>';
	}

	$output .= '<ul class="hpts-list">';

	foreach ( $signals as $signal ) {
		$icon = '';

		if ( $icons && ! empty( $signal['icon'] ) ) {
			// Icon classes verified against the Font Awesome 5.13.1 solid set
			// bundled and enqueued site-wide by HivePress core.
			$icon = '<i class="fas fa-' . esc_attr( $signal['icon'] ) . '" aria-hidden="true"></i>';
		}

		$output .= '<li class="hpts-item hpts-item--' . esc_attr( $signal['key'] ) . '">';

		if ( 'pills' === $style ) {
			$output .= $icon . '<span class="hpts-item__text">' . esc_html( $signal['pill'] ) . '</span>';
		} else {
			$output .= '<span class="hpts-item__label">' . $icon . esc_html( $signal['label'] ) . '</span>';
			$output .= '<span class="hpts-item__value">' . esc_html( $signal['value'] ) . '</span>';
		}

		$output .= '</li>';
	}

	$output .= '</ul></div>';

	// Opt-in AggregateRating structured data, vendor pages only.
	if ( ! $context['listing_id'] ) {
		$output .= hpts_schema_jsonld( $context['vendor_id'] );
	}

	return $output . $debug;
}

/**
 * LocalBusiness + AggregateRating JSON-LD for a vendor, using the rating the
 * Reviews extension caches in hp_rating / hp_rating_count meta. Off by
 * default: enabling alongside another plugin that outputs rating markup can
 * cause duplicate structured data warnings.
 *
 * @param int $vendor_id Vendor ID.
 * @return string
 */
function hpts_schema_jsonld( $vendor_id ) {
	if ( ! hpts_get_option( 'trust_signals_schema', false ) || ! class_exists( '\\HivePress\\Models\\Review' ) ) {
		return '';
	}

	$rating       = (float) get_post_meta( $vendor_id, 'hp_rating', true );
	$rating_count = (int) get_post_meta( $vendor_id, 'hp_rating_count', true );

	if ( ! $rating || $rating_count < 1 ) {
		return '';
	}

	$data = [
		'@context'        => 'https://schema.org',
		'@type'           => 'LocalBusiness',
		'name'            => get_the_title( $vendor_id ),
		'aggregateRating' => [
			'@type'       => 'AggregateRating',
			'ratingValue' => round( $rating, 1 ),
			'reviewCount' => $rating_count,
			'bestRating'  => 5,
			'worstRating' => 1,
		],
	];

	return '<script type="application/ld+json">' . wp_json_encode( $data ) . '</script>';
}

/**
 * Resolves the current page into a vendor ID (and listing ID where relevant).
 *
 * Primary source: HivePress request contexts, which core controllers set on
 * both page types (verified in core: listing pages set 'listing' AND 'vendor';
 * vendor pages set 'vendor'). The queried object is only a fallback, because
 * on vendor profile pages core replaces the main query with the vendor's
 * listings for pagination, making is_singular('hp_vendor') unreliable - this
 * was why v1.2 rendered on listings but not on profiles.
 *
 * @return array{vendor_id: int, listing_id: int}|null
 */
function hpts_get_context() {

	// Listing page context.
	$listing = hivepress()->request->get_context( 'listing' );

	if ( is_object( $listing ) && $listing->get_id() && $listing->get_vendor__id() ) {
		return [
			'vendor_id'  => (int) $listing->get_vendor__id(),
			'listing_id' => (int) $listing->get_id(),
		];
	}

	// Vendor page context.
	$vendor = hivepress()->request->get_context( 'vendor' );

	if ( is_object( $vendor ) && $vendor->get_id() ) {
		return [
			'vendor_id'  => (int) $vendor->get_id(),
			'listing_id' => 0,
		];
	}

	// Fallback: queried object (covers unusual setups).
	if ( is_singular( 'hp_listing' ) ) {
		$post = get_queried_object();

		if ( $post && $post->post_parent ) {
			$vendor_post = get_post( $post->post_parent );

			if ( $vendor_post && 'hp_vendor' === $vendor_post->post_type ) {
				return [
					'vendor_id'  => (int) $vendor_post->ID,
					'listing_id' => (int) $post->ID,
				];
			}
		}
	} elseif ( is_singular( 'hp_vendor' ) ) {
		$post = get_queried_object();

		if ( $post ) {
			return [
				'vendor_id'  => (int) $post->ID,
				'listing_id' => 0,
			];
		}
	}

	return null;
}

/**
 * Returns a valid #rgb or #rrggbb hex colour, or an empty string.
 *
 * @param mixed $value Raw value.
 * @return string
 */
function hpts_sanitize_hex( $value ) {
	$value = trim( (string) $value );

	return preg_match( '/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $value ) ? $value : '';
}

/**
 * Gets the inline CSS, output once per page.
 *
 * @return string
 */
function hpts_inline_css() {
	static $done = false;

	if ( $done ) {
		return '';
	}

	$done = true;

	// Custom colours from the picker settings; invalid values fall back to
	// the plugin defaults.
	$icon_color = hpts_sanitize_hex( hpts_get_option( 'trust_signals_color_icon', '' ) );
	$pill_bg    = hpts_sanitize_hex( hpts_get_option( 'trust_signals_color_pill_bg', '' ) );
	$pill_text  = hpts_sanitize_hex( hpts_get_option( 'trust_signals_color_pill_text', '' ) );

	$icon_color = $icon_color ? $icon_color : '#b5becf';
	$pill_bg    = $pill_bg ? $pill_bg : '#eaecf0';
	$pill_text  = $pill_text ? $pill_text : '#4a5568';

	$css = '.hpts-block .hpts-list{list-style:none;margin:0;padding:0}'
		// Centered title fallback; content-title--center handles HivePress themes.
		. '.hpts-block__title{margin:0 0 1.25rem;text-align:center}'
		// Fixed icon metrics so pills with and without icons match in height.
		. '.hpts-block .fas{color:' . $icon_color . ';font-size:1em;line-height:1;flex:0 0 auto}'
		/*
		Card fallback: exact HivePress theme sidebar-widget values, applied
			only when the theme does not style .widget--sidebar itself. */
		. '.hpts-block--card{padding:2rem;border:1px solid rgba(7,36,86,.075);border-radius:3px;box-shadow:0 2px 4px 0 rgba(7,36,86,.075);background-color:#fff}'
		// Rows style.
		. '.hpts-block--rows .hpts-item{display:flex;justify-content:space-between;align-items:baseline;gap:.75rem;padding:.45rem 0;border-bottom:1px solid rgba(0,0,0,.06);font-size:.9em}'
		. '.hpts-block--rows .hpts-item:last-child{border-bottom:none;padding-bottom:0}'
		. '.hpts-block--rows .hpts-item:first-child{padding-top:0}'
		. '.hpts-block--rows .hpts-item__label{opacity:.7}'
		. '.hpts-block--rows .hpts-item__label .fas{margin-right:.45em}'
		. '.hpts-block--rows .hpts-item__value{font-weight:600;text-align:right}'
		// Pill style: uniform thickness via min-height and fixed line-height.
		. '.hpts-block--pills .hpts-list{display:flex;gap:.5rem}'
		. '.hpts-pills--inline .hpts-list{flex-direction:row;flex-wrap:wrap}'
		. '.hpts-pills--stacked .hpts-list{flex-direction:column}'
		. '.hpts-block--pills .hpts-item{display:inline-flex;align-items:center;gap:.5em;box-sizing:border-box;min-height:2.35em;padding:.45em .95em;border-radius:999px;background-color:' . $pill_bg . ';color:' . $pill_text . ';font-size:.85em;font-weight:500;line-height:1.35}'
		. '.hpts-pills--stacked .hpts-item{width:100%}';

	return '<style id="hpts-css">' . $css . '</style>';
}

/*
--------------------------------------------------------------------------
Signal collection.
--------------------------------------------------------------------------
*/

/**
 * Collects the display signals and hidden-signal diagnostics.
 *
 * @param int $vendor_id Vendor ID.
 * @param int $listing_id Optional listing scope.
 * @return array{signals: array<int, array<string, string>>, debug: array<int, string>}
 */
function hpts_collect_signals( $vendor_id, $listing_id = 0 ) {
	$enabled = hpts_get_option( 'trust_signals_items', hpts_default_signals() );
	$signals = [];
	$debug   = [];

	if ( ! is_array( $enabled ) || ! $enabled ) {
		return [
			'signals' => [],
			'debug'   => [ 'No signals are enabled in HivePress > Settings > Trust Signals.' ],
		];
	}

	$cached = hpts_get_vendor_stats( $vendor_id );

	// 1. Verified badge (vendor-level verification carries to listings).
	if ( in_array( 'verified', $enabled, true ) ) {
		// On listing pages the badge shows if EITHER the listing or its vendor
		// is verified - vendor-level verification carries to their listings.
		$listing_verified = $listing_id ? (bool) get_post_meta( $listing_id, 'hp_verified', true ) : false;
		$vendor_verified  = (bool) get_post_meta( $vendor_id, 'hp_verified', true );
		$verified         = $listing_verified || $vendor_verified;

		if ( ! $verified ) {
			$debug[] = sprintf(
				'verified: hidden (hp_verified meta empty on vendor #%d%s). Tick "Verified" on the vendor in WP admin to enable.',
				$vendor_id,
				$listing_id ? sprintf( ' and on listing #%d', $listing_id ) : ''
			);
		} else {
			$signals[] = [
				'key'   => 'verified',
				'icon'  => 'check-circle',
				'label' => __( 'Verified', 'hivepress-trust-signals' ),
				'value' => __( 'Yes', 'hivepress-trust-signals' ),
				'pill'  => __( 'Verified', 'hivepress-trust-signals' ),
			];
		}
	}

	// 2. Member since (vendor's user account registration date).
	if ( in_array( 'member_since', $enabled, true ) ) {
		$vendor_post = get_post( $vendor_id );
		$user        = $vendor_post ? get_userdata( (int) $vendor_post->post_author ) : null;

		if ( $user && $user->user_registered ) {
			$member_since = date_i18n( 'M Y', strtotime( $user->user_registered ) );

			$signals[] = [
				'key'   => 'member_since',
				'icon'  => 'calendar-alt',
				'label' => __( 'Member since', 'hivepress-trust-signals' ),
				'value' => $member_since,
				'pill'  => sprintf(
					// translators: %s: month and year.
					__( 'Member since %s', 'hivepress-trust-signals' ),
					$member_since
				),
			];
		}
	}

	// 3. Active listings count.
	if ( in_array( 'listings_count', $enabled, true ) && empty( $cached['listings'] ) ) {
		$debug[] = 'listings_count: hidden (no published listings).';
	}

	if ( in_array( 'listings_count', $enabled, true ) && isset( $cached['listings'] ) && $cached['listings'] > 0 ) {
		$signals[] = [
			'key'   => 'listings_count',
			'icon'  => 'th-list',
			'label' => __( 'Active listings', 'hivepress-trust-signals' ),
			'value' => number_format_i18n( $cached['listings'] ),
			'pill'  => sprintf(
				// translators: %s: number of listings.
				_n( '%s active listing', '%s active listings', $cached['listings'], 'hivepress-trust-signals' ),
				number_format_i18n( $cached['listings'] )
			),
		];
	}

	// 4. Rating (cached by the Reviews extension as hp_rating / hp_rating_count meta).
	if ( in_array( 'rating', $enabled, true ) && ! class_exists( '\HivePress\Models\Review' ) ) {
		$debug[] = 'rating: hidden (Reviews extension inactive).';
	}

	if ( in_array( 'rating', $enabled, true ) && class_exists( '\HivePress\Models\Review' ) ) {
		$rating_source = $listing_id ? $listing_id : $vendor_id;
		$rating        = get_post_meta( $rating_source, 'hp_rating', true );
		$rating_count  = (int) get_post_meta( $rating_source, 'hp_rating_count', true );

		if ( ! $rating || $rating_count < 1 ) {
			$debug[] = 'rating: hidden (no approved reviews yet).';
		} else {
			$rating_text = sprintf(
				// translators: 1: average rating, 2: number of reviews.
				_n( '%1$s / 5 (%2$s review)', '%1$s / 5 (%2$s reviews)', $rating_count, 'hivepress-trust-signals' ),
				number_format_i18n( round( (float) $rating, 1 ), 1 ),
				number_format_i18n( $rating_count )
			);

			$signals[] = [
				'key'   => 'rating',
				'icon'  => 'star',
				'label' => __( 'Rating', 'hivepress-trust-signals' ),
				'value' => $rating_text,
				'pill'  => $rating_text,
			];
		}
	}

	// 5. Favourites.
	if ( in_array( 'favorites', $enabled, true ) && ! class_exists( '\HivePress\Models\Favorite' ) ) {
		$debug[] = 'favorites: hidden (Favorites extension inactive).';
	}

	if ( in_array( 'favorites', $enabled, true ) && class_exists( '\HivePress\Models\Favorite' ) ) {
		$favorites = $listing_id
			? hpts_count_favorites( [ $listing_id ] )
			: hpts_count_favorites( hpts_get_vendor_listing_ids( $vendor_id ) );

		if ( $favorites < 1 ) {
			$debug[] = 'favorites: hidden (none received yet).';
		} else {
			$signals[] = [
				'key'   => 'favorites',
				'icon'  => 'heart',
				'label' => $listing_id
					? __( 'Saved as favourite', 'hivepress-trust-signals' )
					: __( 'Favourites received', 'hivepress-trust-signals' ),
				'value' => sprintf(
					// translators: %s: number of users.
					_n( '%s time', '%s times', $favorites, 'hivepress-trust-signals' ),
					number_format_i18n( $favorites )
				),
				'pill'  => sprintf(
					// translators: %s: number of users.
					_n( 'Favourited %s time', 'Favourited %s times', $favorites, 'hivepress-trust-signals' ),
					number_format_i18n( $favorites )
				),
			];
		}
	}

	// 6. Completed bookings.
	if ( in_array( 'completed_bookings', $enabled, true ) ) {
		if ( ! isset( $cached['bookings'] ) ) {
			$debug[] = 'completed_bookings: hidden (Bookings extension inactive).';
		} elseif ( $cached['bookings'] < 1 ) {
			$debug[] = 'completed_bookings: hidden (no confirmed bookings with a past end time yet).';
		}
	}

	if ( in_array( 'completed_bookings', $enabled, true ) && isset( $cached['bookings'] ) && $cached['bookings'] > 0 ) {
		$signals[] = [
			'key'   => 'completed_bookings',
			'icon'  => 'calendar-check',
			'label' => __( 'Completed bookings', 'hivepress-trust-signals' ),
			'value' => number_format_i18n( $cached['bookings'] ),
			'pill'  => sprintf(
				// translators: %s: number of bookings.
				_n( '%s completed booking', '%s completed bookings', $cached['bookings'], 'hivepress-trust-signals' ),
				number_format_i18n( $cached['bookings'] )
			),
		];
	}

	// 7 & 8. Response time / rate.
	$min_samples = max( 1, (int) hpts_get_option( 'trust_signals_min_samples', 3 ) );
	$response    = isset( $cached['response'] ) ? $cached['response'] : null;
	$wants_msgs  = in_array( 'response_time', $enabled, true ) || in_array( 'response_rate', $enabled, true );

	if ( $wants_msgs && null === $response ) {
		$debug[] = 'response stats: hidden (Messages extension inactive).';
	} elseif ( $wants_msgs && $response['samples'] < $min_samples ) {
		$debug[] = sprintf(
			'response stats: hidden (%d answered conversation(s) opened by clients in message history; minimum is %d - lower it in settings to test).',
			$response['samples'],
			$min_samples
		);
	}

	if ( $response && $response['samples'] >= $min_samples ) {

		if ( in_array( 'response_time', $enabled, true ) && null !== $response['median'] ) {
			$bucket = hpts_response_bucket( $response['median'] );

			if ( ! $bucket ) {
				$debug[] = sprintf(
					'response_time: hidden (median response is slower than the %d-day display cap; raise "Slowest response time to display" in settings to show it).',
					max( 1, (int) hpts_get_option( 'trust_signals_response_max_days', 3 ) )
				);
			} else {
				$signals[] = [
					'key'   => 'response_time',
					'icon'  => 'clock',
					'label' => __( 'Typically replies', 'hivepress-trust-signals' ),
					'value' => $bucket,
					'pill'  => sprintf(
						// translators: %s: response time bucket, e.g. "within an hour".
						__( 'Replies %s', 'hivepress-trust-signals' ),
						$bucket
					),
				];
			}
		}

		// Shown only at 80%+ so the block never actively harms a vendor;
		// below that we omit rather than display (documented in settings).
		if ( in_array( 'response_rate', $enabled, true ) && null !== $response['rate'] ) {
			$rate_min = (int) hpts_get_option( 'trust_signals_rate_min', 80 );

			if ( $rate_min > 0 && $response['rate'] < $rate_min ) {
				$debug[] = sprintf( 'response_rate: hidden (%d%%; shown only at %d%%+ per the settings threshold).', $response['rate'], $rate_min );
			} else {
				$signals[] = [
					'key'   => 'response_rate',
					'icon'  => 'reply',
					'label' => __( 'Response rate', 'hivepress-trust-signals' ),
					'value' => $response['rate'] . '%',
					'pill'  => sprintf(
						// translators: %s: percentage.
						__( '%s%% response rate', 'hivepress-trust-signals' ),
						$response['rate']
					),
				];
			}
		}
	}

	// 9. Last active (self-tracked; shown only when data exists and is recent).
	if ( in_array( 'last_active', $enabled, true ) ) {
		$vendor_post = isset( $vendor_post ) ? $vendor_post : get_post( $vendor_id );
		$last_active = $vendor_post ? (int) get_user_meta( (int) $vendor_post->post_author, 'hpts_last_active', true ) : 0;

		if ( ! $last_active ) {
			$debug[] = 'last_active: hidden (no activity recorded yet; tracked from logins, sent messages and logged-in page visits since plugin activation).';
		} else {
			$age = time() - $last_active;

			if ( $age < DAY_IN_SECONDS ) {
				$label = __( 'Active today', 'hivepress-trust-signals' );
			} elseif ( $age < WEEK_IN_SECONDS ) {
				$label = __( 'Active this week', 'hivepress-trust-signals' );
			} elseif ( $age < 30 * DAY_IN_SECONDS ) {
				$label = __( 'Active this month', 'hivepress-trust-signals' );
			} else {
				$label   = null;
				$debug[] = 'last_active: hidden (last recorded activity is older than 30 days; omitted by design).';
			}

			if ( $label ) {
				$signals[] = [
					'key'   => 'last_active',
					'icon'  => 'bolt',
					'label' => __( 'Activity', 'hivepress-trust-signals' ),
					'value' => $label,
					'pill'  => $label,
				];
			}
		}
	}

	return [
		'signals' => $signals,
		'debug'   => $debug,
	];
}

/**
 * Maps a median response time to a truthful display label. The label ladder
 * (hour / few hours / day / few days / week / two weeks / month) is chosen
 * from the actual value, while the "slowest response time" setting only
 * decides whether the signal is shown at all - so any displayed label is
 * accurate at any cap.
 *
 * @param int $seconds Median response time in seconds.
 * @return string|null
 */
function hpts_response_bucket( $seconds ) {
	$cap = max( 1, (int) hpts_get_option( 'trust_signals_response_max_days', 3 ) ) * DAY_IN_SECONDS;

	// The setting only controls visibility; the label always comes from the
	// true value, so whatever is displayed reads true at any cap.
	if ( $seconds > $cap ) {
		return null;
	}

	$ladder = [
		[ HOUR_IN_SECONDS, __( 'within an hour', 'hivepress-trust-signals' ) ],
		[ 6 * HOUR_IN_SECONDS, __( 'within a few hours', 'hivepress-trust-signals' ) ],
		[ DAY_IN_SECONDS, __( 'within a day', 'hivepress-trust-signals' ) ],
		[ 3 * DAY_IN_SECONDS, __( 'within a few days', 'hivepress-trust-signals' ) ],
		[ 7 * DAY_IN_SECONDS, __( 'within a week', 'hivepress-trust-signals' ) ],
		[ 14 * DAY_IN_SECONDS, __( 'within two weeks', 'hivepress-trust-signals' ) ],
		[ 31 * DAY_IN_SECONDS, __( 'within a month', 'hivepress-trust-signals' ) ],
	];

	foreach ( $ladder as $step ) {
		if ( $seconds <= $step[0] ) {
			return $step[1];
		}
	}

	return null;
}

/*
--------------------------------------------------------------------------
Cached vendor stats (the expensive ones).
--------------------------------------------------------------------------
*/

/**
 * Gets the cache validity stamp: plugin version plus the response tunables,
 * so changing any of those settings takes effect immediately instead of
 * waiting for the 12h cache to expire.
 *
 * @return string
 */
function hpts_stats_cache_stamp() {
	return HPTS_VERSION . ':' . substr(
		md5(
			implode(
				':',
				[
					(string) hpts_get_option( 'trust_signals_min_samples', 3 ),
					(string) hpts_get_option( 'trust_signals_grace_hours', 48 ),
					(string) hpts_get_option( 'trust_signals_rate_min', 80 ),
					(string) hpts_get_option( 'trust_signals_response_max_days', 3 ),
				]
			)
		),
		0,
		8
	);
}

/**
 * Gets the cached heavy stats bundle for a vendor.
 *
 * @param int $vendor_id Vendor ID.
 * @return array<string, mixed>
 */
function hpts_get_vendor_stats( $vendor_id ) {
	$key   = 'hpts_v_' . (int) $vendor_id;
	$stats = get_transient( $key );

	if ( is_array( $stats ) && isset( $stats['v'] ) && hpts_stats_cache_stamp() === $stats['v'] ) {
		return $stats;
	}

	$vendor_post = get_post( $vendor_id );
	$user_id     = $vendor_post ? (int) $vendor_post->post_author : 0;

	$stats = [
		'v'        => hpts_stats_cache_stamp(),
		'listings' => hpts_count_vendor_listings( $vendor_id ),
		'bookings' => hpts_count_completed_bookings( $vendor_id ),
		'response' => $user_id ? hpts_compute_response_stats( $user_id ) : null,
	];

	set_transient( $key, $stats, HPTS_CACHE_TTL );

	return $stats;
}

/**
 * Deletes the cached stats for a vendor.
 *
 * @param int $vendor_id Vendor ID.
 * @return void
 */
function hpts_flush_vendor_stats( $vendor_id ) {
	if ( $vendor_id ) {
		delete_transient( 'hpts_v_' . (int) $vendor_id );
	}
}

/**
 * Published listings belonging to a vendor, via the core model query
 * (vendor field is aliased to post_parent in the Listing model).
 *
 * @param int $vendor_id Vendor ID.
 * @return int
 */
function hpts_count_vendor_listings( $vendor_id ) {
	return (int) \HivePress\Models\Listing::query()->filter(
		[
			'status' => 'publish',
			'vendor' => (int) $vendor_id,
		]
	)->get_count();
}

/**
 * All non-trashed listing IDs for a vendor (any status - bookings and
 * favourites on since-hidden listings are still real history).
 *
 * @param int $vendor_id Vendor ID.
 * @return array<int, int>
 */
function hpts_get_vendor_listing_ids( $vendor_id ) {
	global $wpdb;

	$ids = $wpdb->get_col( // phpcs:ignore WordPress.DB -- custom analytics tables / source-verified hp_ schema; table names derive from $wpdb->prefix and cannot be placeholders; caching by design at the transient layer.
		$wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts}
			 WHERE post_type = 'hp_listing'
			 AND post_parent = %d
			 AND post_status NOT IN ( 'trash', 'auto-draft' )",
			(int) $vendor_id
		)
	);

	return array_map( 'intval', $ids );
}

/**
 * Counts favourites for the given listings.
 *
 * @param array<int, int> $listing_ids Listing IDs.
 * @return int
 */
function hpts_count_favorites( $listing_ids ) {
	global $wpdb;

	$listing_ids = array_filter( array_map( 'intval', (array) $listing_ids ) );

	if ( ! $listing_ids ) {
		return 0;
	}

	$placeholders = implode( ',', array_fill( 0, count( $listing_ids ), '%d' ) );

	return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB -- custom analytics tables / source-verified hp_ schema; table names derive from $wpdb->prefix and cannot be placeholders; caching by design at the transient layer.
		$wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->comments}
			 WHERE comment_type = 'hp_favorite'
			 AND comment_post_ID IN ( $placeholders )", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$listing_ids
		)
	);
}

/**
 * Completed bookings = confirmed ('publish') bookings whose end time has passed.
 *
 * Verified against Bookings extension source: booking statuses are
 * publish = Confirmed, draft = Unpaid, pending = Pending, trash = Canceled;
 * 'listing' is aliased to post_parent; start_time / end_time are unix
 * timestamps in hp_start_time / hp_end_time meta, and the extension itself
 * filters with 'end_time__lt', confirming meta comparison filters work.
 *
 * IMPORTANT: if the site sets a booking storage period (hp_booking_storage_period),
 * an hourly cron PERMANENTLY DELETES confirmed bookings after that many days.
 * A live count would therefore silently shrink over time. To stay accurate we
 * keep a monotonic counter in vendor meta: whenever the live count exceeds the
 * stored counter, the counter is raised to match. Deletions can then never
 * reduce a vendor's completed-bookings history.
 *
 * @param int $vendor_id Vendor ID.
 * @return int|null
 */
function hpts_count_completed_bookings( $vendor_id ) {
	if ( ! class_exists( '\HivePress\Models\Booking' ) ) {
		return null;
	}

	$listing_ids = hpts_get_vendor_listing_ids( $vendor_id );
	$live        = 0;

	if ( $listing_ids ) {
		$live = (int) \HivePress\Models\Booking::query()->filter(
			[
				'status'        => 'publish',
				'listing__in'   => $listing_ids,
				'end_time__lte' => time(),
			]
		)->get_count();
	}

	$counter = (int) get_post_meta( $vendor_id, 'hpts_completed_bookings', true );

	if ( $live > $counter ) {
		update_post_meta( $vendor_id, 'hpts_completed_bookings', $live );
		$counter = $live;
	}

	return $counter;
}

/*
--------------------------------------------------------------------------
Response time & rate (Messages).
--------------------------------------------------------------------------
*/

/**
 * Median first-response time and response rate over the full message history.
 *
 * Algorithm (per conversation partner, chronological):
 * - The first message in a pair-thread defines who opened it. Threads opened
 *   BY the vendor are excluded (outbound outreach measures nothing).
 * - First response = vendor's first message after the opener.
 * - Response rate denominator only includes threads opened 48+ hours ago,
 *   so brand-new unanswered enquiries don't unfairly drag the rate down.
 *
 * @param int $vendor_user_id Vendor user ID.
 * @return array{median: int|null, rate: int|null, samples: int}|null
 */
function hpts_compute_response_stats( $vendor_user_id ) {
	global $wpdb;

	if ( ! class_exists( '\HivePress\Models\Message' ) ) {
		return null;
	}

	$vendor_user_id = (int) $vendor_user_id;

	// Verified schema: sender = user_id, recipient = comment_karma.
	// All message history is considered; DESC + reverse means that if a vendor
	// ever exceeds the safety cap, the OLDEST messages are dropped, not the
	// newest.
	$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB -- custom analytics tables / source-verified hp_ schema; table names derive from $wpdb->prefix and cannot be placeholders; caching by design at the transient layer.
		$wpdb->prepare(
			"SELECT user_id AS sender, comment_karma AS recipient, comment_date_gmt AS t
			 FROM {$wpdb->comments}
			 WHERE comment_type = 'hp_message'
			 AND ( user_id = %d OR comment_karma = %d )
			 ORDER BY comment_date_gmt DESC
			 LIMIT %d",
			$vendor_user_id,
			$vendor_user_id,
			HPTS_MSG_ROW_LIMIT
		)
	);

	$rows = array_reverse( $rows );

	if ( ! $rows ) {
		return [
			'median'  => null,
			'rate'    => null,
			'samples' => 0,
		];
	}

	$threads = [];

	foreach ( $rows as $row ) {
		$sender    = (int) $row->sender;
		$recipient = (int) $row->recipient;
		$other     = ( $sender === $vendor_user_id ) ? $recipient : $sender;

		if ( ! $other || $other === $vendor_user_id ) {
			continue;
		}

		if ( ! isset( $threads[ $other ] ) ) {
			$threads[ $other ] = [
				'opened_by_vendor' => ( $sender === $vendor_user_id ),
				'opened_at'        => strtotime( $row->t . ' UTC' ),
				'replied_at'       => null,
			];
			continue;
		}

		if ( ! $threads[ $other ]['opened_by_vendor'] && null === $threads[ $other ]['replied_at'] && $sender === $vendor_user_id ) {
			$threads[ $other ]['replied_at'] = strtotime( $row->t . ' UTC' );
		}
	}

	$durations   = [];
	$rate_total  = 0;
	$rate_hits   = 0;
	$grace_limit = time() - max( 0, (int) hpts_get_option( 'trust_signals_grace_hours', 48 ) ) * HOUR_IN_SECONDS;

	foreach ( $threads as $thread ) {
		if ( $thread['opened_by_vendor'] ) {
			continue;
		}

		if ( null !== $thread['replied_at'] ) {
			$durations[] = max( 0, $thread['replied_at'] - $thread['opened_at'] );
		}

		if ( $thread['opened_at'] <= $grace_limit ) {
			++$rate_total;

			if ( null !== $thread['replied_at'] ) {
				++$rate_hits;
			}
		}
	}

	sort( $durations );
	$count  = count( $durations );
	$median = null;

	if ( $count ) {
		$mid    = (int) floor( ( $count - 1 ) / 2 );
		$median = ( $count % 2 ) ? $durations[ $mid ] : (int) ( ( $durations[ $mid ] + $durations[ $mid + 1 ] ) / 2 );
	}

	return [
		'median'  => $median,
		'rate'    => $rate_total ? (int) round( 100 * $rate_hits / $rate_total ) : null,
		'samples' => $count,
	];
}

/*
--------------------------------------------------------------------------
Cache invalidation & activity tracking.
--------------------------------------------------------------------------
*/

/**
 * Resolves a user ID to their vendor ID.
 *
 * @param int $user_id User ID.
 * @return int
 */
function hpts_vendor_id_from_user( $user_id ) {
	if ( ! $user_id ) {
		return 0;
	}

	return (int) \HivePress\Models\Vendor::query()->filter( [ 'user' => (int) $user_id ] )->get_first_id();
}

/**
 * On any new hp_message comment (verified schema: sender = user_id,
 * recipient = comment_karma): flush both parties' cached stats and record
 * the sender's activity.
 *
 * @param int             $comment_id Comment ID.
 * @param WP_Comment|null $comment Comment object.
 * @return void
 */
function hpts_on_comment_insert( $comment_id, $comment = null ) {
	if ( ! $comment ) {
		$comment = get_comment( $comment_id );
	}

	if ( ! $comment || 'hp_message' !== $comment->comment_type ) {
		return;
	}

	hpts_flush_vendor_stats( hpts_vendor_id_from_user( (int) $comment->user_id ) );
	hpts_flush_vendor_stats( hpts_vendor_id_from_user( (int) $comment->comment_karma ) );

	if ( (int) $comment->user_id ) {
		update_user_meta( (int) $comment->user_id, 'hpts_last_active', time() );
	}
}

/**
 * Raw fallback for booking status changes (listing = post_parent, verified),
 * so cache invalidation never depends on the model registry.
 *
 * @param string       $new_status New status.
 * @param string       $old_status Old status.
 * @param WP_Post|null $post Post object.
 * @return void
 */
function hpts_on_booking_transition( $new_status, $old_status, $post ) {
	if ( $post && 'hp_booking' === $post->post_type && $new_status !== $old_status && $post->post_parent ) {
		$listing = get_post( (int) $post->post_parent );

		if ( $listing && $listing->post_parent ) {
			hpts_flush_vendor_stats( (int) $listing->post_parent );
		}
	}
}

/**
 * Handles both booking/create ( $id, $object ) and booking/update_status
 * ( $id, $new_status, $old_status, $object ) by locating the object among
 * the arguments.
 *
 * @param int   $booking_id Booking ID.
 * @param mixed $arg2 Second hook argument.
 * @param mixed $arg3 Third hook argument.
 * @param mixed $arg4 Fourth hook argument.
 * @return void
 */
function hpts_on_booking_change( $booking_id, $arg2 = null, $arg3 = null, $arg4 = null ) {
	$booking = is_object( $arg2 ) ? $arg2 : ( is_object( $arg4 ) ? $arg4 : null );

	if ( $booking && method_exists( $booking, 'get_listing__id' ) ) {
		$listing = get_post( (int) $booking->get_listing__id() );

		if ( $listing && $listing->post_parent ) {
			hpts_flush_vendor_stats( (int) $listing->post_parent );
		}
	}
}

/**
 * Refreshes vendor stats when a booking completes.
 *
 * @param int $booking_id Booking ID.
 * @return void
 */
function hpts_on_booking_complete( $booking_id ) {
	if ( ! class_exists( '\HivePress\Models\Booking' ) ) {
		return;
	}

	$booking = \HivePress\Models\Booking::query()->get_by_id( $booking_id );

	if ( $booking && $booking->get_listing__id() ) {
		$listing = get_post( (int) $booking->get_listing__id() );

		if ( $listing && $listing->post_parent ) {
			hpts_flush_vendor_stats( (int) $listing->post_parent );
		}
	}
}

/**
 * Refreshes vendor stats when a listing status changes.
 *
 * @param int $listing_id Listing ID.
 * @return void
 */
function hpts_on_listing_change( $listing_id ) {
	$post = get_post( (int) $listing_id );

	if ( $post && $post->post_parent ) {
		hpts_flush_vendor_stats( (int) $post->post_parent );
	}
}

/**
 * Records login time as vendor activity.
 *
 * @param string       $user_login Username.
 * @param WP_User|null $user User object.
 * @return void
 */
function hpts_track_login( $user_login, $user ) {
	if ( $user instanceof WP_User ) {
		update_user_meta( $user->ID, 'hpts_last_active', time() );
	}
}

/**
 * Records logged-in page visits as activity, throttled to one meta write per
 * hour (the read is object-cached by WordPress, so this costs ~nothing).
 *
 * @return void
 */
function hpts_track_visit() {
	if ( ! is_user_logged_in() ) {
		return;
	}

	$user_id = get_current_user_id();
	$last    = (int) get_user_meta( $user_id, 'hpts_last_active', true );

	if ( time() - $last > HOUR_IN_SECONDS ) {
		update_user_meta( $user_id, 'hpts_last_active', time() );
	}
}
