<?php
/**
 * Lightweight GitHub Releases updater.
 *
 * Surfaces plugin updates in the WordPress dashboard when a newer version is
 * published as a GitHub release, without needing wordpress.org and without any
 * external libraries. The repository (or at least its releases) must be public
 * so sites can download the update package.
 *
 * Publishing an update: bump the plugin version, then create a GitHub release
 * whose tag is the new version (e.g. "1.7.3" or "v1.7.3"). Sites on an older
 * version are then offered the update within about a day (or immediately via
 * Dashboard > Updates > Check again).
 *
 * @package HivePress\Trust_Signals
 */

defined( 'ABSPATH' ) || exit;

/**
 * Checks GitHub releases and feeds them into the core plugin-update system.
 */
class HPTS_GitHub_Updater {

	/**
	 * Absolute path to the main plugin file.
	 *
	 * @var string
	 */
	private $file;

	/**
	 * Plugin basename, e.g. hivepress-trust-signals/hivepress-trust-signals.php.
	 *
	 * @var string
	 */
	private $basename;

	/**
	 * Plugin slug (containing directory), e.g. hivepress-trust-signals.
	 *
	 * @var string
	 */
	private $slug;

	/**
	 * Installed version.
	 *
	 * @var string
	 */
	private $version;

	/**
	 * GitHub repository owner.
	 *
	 * @var string
	 */
	private $owner;

	/**
	 * GitHub repository name.
	 *
	 * @var string
	 */
	private $repo;

	/**
	 * Transient key caching the latest-release lookup.
	 *
	 * @var string
	 */
	private $cache_key;

	/**
	 * Cache lifetime in seconds.
	 *
	 * @var int
	 */
	private $cache_ttl;

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $args {
	 *     Configuration.
	 *
	 *     @type string $file      Absolute path to the main plugin file.
	 *     @type string $version   Installed version.
	 *     @type string $owner     GitHub repository owner.
	 *     @type string $repo      GitHub repository name.
	 *     @type int    $cache_ttl Optional cache lifetime in seconds.
	 * }
	 */
	public function __construct( $args ) {
		$this->file      = (string) $args['file'];
		$this->basename  = plugin_basename( $args['file'] );
		$this->slug      = dirname( $this->basename );
		$this->version   = (string) $args['version'];
		$this->owner     = (string) $args['owner'];
		$this->repo      = (string) $args['repo'];
		$this->cache_key = 'hpts_gh_release_' . md5( $this->owner . '/' . $this->repo );
		$this->cache_ttl = isset( $args['cache_ttl'] ) ? (int) $args['cache_ttl'] : 6 * HOUR_IN_SECONDS;

		add_filter( 'pre_set_site_transient_update_plugins', [ $this, 'inject_update' ] );
		add_filter( 'plugins_api', [ $this, 'plugin_info' ], 10, 3 );
		add_filter( 'upgrader_source_selection', [ $this, 'fix_source_dir' ], 10, 4 );
		add_action( 'upgrader_process_complete', [ $this, 'flush_cache' ], 10, 2 );
	}

	/**
	 * Fetches (and caches) the latest published release from GitHub.
	 *
	 * @return array<string, string>|false Release data, or false when unavailable.
	 */
	private function get_release() {
		$cached = get_transient( $this->cache_key );

		if ( false !== $cached ) {
			// An empty string is the "checked recently, nothing usable" sentinel.
			return is_array( $cached ) ? $cached : false;
		}

		$response = wp_remote_get(
			sprintf(
				'https://api.github.com/repos/%s/%s/releases/latest',
				rawurlencode( $this->owner ),
				rawurlencode( $this->repo )
			),
			[
				'timeout' => 10,
				'headers' => [
					'Accept'     => 'application/vnd.github+json',
					'User-Agent' => 'hivepress-trust-signals',
				],
			]
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			// Cache the miss briefly so a rate-limited or offline API does not
			// slow every admin request with a fresh 10s attempt.
			set_transient( $this->cache_key, '', 30 * MINUTE_IN_SECONDS );
			return false;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $data ) || empty( $data['tag_name'] ) ) {
			set_transient( $this->cache_key, '', 30 * MINUTE_IN_SECONDS );
			return false;
		}

		$release = [
			'version'      => ltrim( (string) $data['tag_name'], 'vV' ),
			'download'     => $this->pick_download( $data ),
			'html_url'     => isset( $data['html_url'] ) ? (string) $data['html_url'] : '',
			'body'         => isset( $data['body'] ) ? (string) $data['body'] : '',
			'published_at' => isset( $data['published_at'] ) ? (string) $data['published_at'] : '',
		];

		set_transient( $this->cache_key, $release, $this->cache_ttl );

		return $release;
	}

	/**
	 * Chooses the download URL: a packaged .zip release asset if one is
	 * attached, otherwise the auto-generated source zipball.
	 *
	 * @param array<string, mixed> $data Decoded release payload.
	 * @return string
	 */
	private function pick_download( $data ) {
		if ( ! empty( $data['assets'] ) && is_array( $data['assets'] ) ) {
			$fallback_zip = '';

			foreach ( $data['assets'] as $asset ) {
				if ( empty( $asset['browser_download_url'] ) || ! preg_match( '/\.zip$/i', (string) $asset['name'] ) ) {
					continue;
				}

				// Prefer an asset named after the plugin (e.g.
				// hivepress-trust-signals.zip) over any other attached zip.
				if ( 0 === strpos( (string) $asset['name'], $this->slug ) ) {
					return (string) $asset['browser_download_url'];
				}

				if ( '' === $fallback_zip ) {
					$fallback_zip = (string) $asset['browser_download_url'];
				}
			}

			if ( '' !== $fallback_zip ) {
				return $fallback_zip;
			}
		}

		return isset( $data['zipball_url'] ) ? (string) $data['zipball_url'] : '';
	}

	/**
	 * Injects an available update into the update_plugins transient.
	 *
	 * @param mixed $transient Update transient (object) or other.
	 * @return mixed
	 */
	public function inject_update( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}

		$release = $this->get_release();

		if ( ! $release || empty( $release['download'] ) ) {
			return $transient;
		}

		$has_update = version_compare( $release['version'], $this->version, '>' );

		$item = (object) [
			'id'          => 'github.com/' . $this->owner . '/' . $this->repo,
			'slug'        => $this->slug,
			'plugin'      => $this->basename,
			'new_version' => $release['version'],
			'url'         => $release['html_url'],
			'package'     => $has_update ? $release['download'] : '',
			'icons'       => [],
			'banners'     => [],
			'tested'      => '',
		];

		if ( $has_update ) {
			$transient->response[ $this->basename ] = $item;
			unset( $transient->no_update[ $this->basename ] );
		} else {
			// Advertise that the plugin is managed here even when up to date, so
			// core does not flag it as having no update source.
			if ( ! isset( $transient->no_update ) ) {
				$transient->no_update = [];
			}
			$transient->no_update[ $this->basename ] = $item;
			unset( $transient->response[ $this->basename ] );
		}

		return $transient;
	}

	/**
	 * Supplies the "View version details" popup content.
	 *
	 * @param mixed  $result Default result.
	 * @param string $action Requested action.
	 * @param object $args   Request arguments.
	 * @return mixed
	 */
	public function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || $args->slug !== $this->slug ) {
			return $result;
		}

		$release = $this->get_release();

		if ( ! $release ) {
			return $result;
		}

		$headers = get_file_data(
			$this->file,
			[
				'RequiresWP'  => 'Requires at least',
				'RequiresPHP' => 'Requires PHP',
			]
		);

		return (object) [
			'name'          => 'Trust Signals for HivePress',
			'slug'          => $this->slug,
			'version'       => $release['version'],
			'author'        => '<a href="https://community.hivepress.io/u/chrisb">ChrisB @ HivePress Community</a>',
			'homepage'      => $release['html_url'],
			'requires'      => $headers['RequiresWP'],
			'requires_php'  => $headers['RequiresPHP'],
			'download_link' => $release['download'],
			'trunk'         => $release['download'],
			'last_updated'  => $release['published_at'],
			'sections'      => [
				'description' => esc_html__( 'Surfaces verifiable trust and activity data in a sidebar block on HivePress listing and vendor pages.', 'hivepress-trust-signals' ),
				'changelog'   => $this->format_changelog( $release['body'] ),
			],
		];
	}

	/**
	 * Renders the release notes for the details popup.
	 *
	 * @param string $body Raw release body (Markdown-ish).
	 * @return string
	 */
	private function format_changelog( $body ) {
		$body = trim( (string) $body );

		if ( '' === $body ) {
			return esc_html__( 'See the GitHub releases page for details.', 'hivepress-trust-signals' );
		}

		return wpautop( wp_kses_post( $body ) );
	}

	/**
	 * GitHub zips extract to a versioned directory; rename it back to the
	 * plugin slug so the update lands in the right folder. Scoped to this
	 * plugin's own update via the hook's plugin argument.
	 *
	 * @param string $source        Extracted source directory.
	 * @param string $remote_source Parent temporary directory.
	 * @param object $upgrader      Upgrader instance.
	 * @param array  $hook_extra    Extra data about what is being upgraded.
	 * @return string|\WP_Error
	 */
	public function fix_source_dir( $source, $remote_source, $upgrader, $hook_extra = [] ) {
		global $wp_filesystem;

		if ( empty( $hook_extra['plugin'] ) || $hook_extra['plugin'] !== $this->basename || ! $wp_filesystem ) {
			return $source;
		}

		$desired = trailingslashit( $remote_source ) . $this->slug;

		if ( untrailingslashit( $source ) === $desired ) {
			return $source;
		}

		if ( $wp_filesystem->move( untrailingslashit( $source ), $desired ) ) {
			return trailingslashit( $desired );
		}

		// Abort with a visible error rather than let the update install into the
		// wrong (versioned) folder and silently leave the plugin on old code.
		return new \WP_Error(
			'hpts_rename_failed',
			esc_html__( 'Trust Signals could not prepare the update folder. Please try again or update manually.', 'hivepress-trust-signals' )
		);
	}

	/**
	 * Clears the cached release after this plugin updates, so the "up to date"
	 * state is recalculated against the just-installed version.
	 *
	 * @param object $upgrader Upgrader instance.
	 * @param array  $data     Process data.
	 * @return void
	 */
	public function flush_cache( $upgrader, $data ) {
		if ( ! isset( $data['action'], $data['type'] ) || 'update' !== $data['action'] || 'plugin' !== $data['type'] ) {
			return;
		}

		// Bulk updates pass 'plugins' (array); single "Update now" and automatic
		// updates pass 'plugin' (string). Handle both.
		$plugins = array_merge(
			isset( $data['plugins'] ) ? (array) $data['plugins'] : [],
			isset( $data['plugin'] ) ? (array) $data['plugin'] : []
		);

		if ( in_array( $this->basename, $plugins, true ) ) {
			delete_transient( $this->cache_key );
		}
	}
}
