<?php
/**
 * GitHub-based auto-updater for Hostlinks Zoom.
 *
 * On each WordPress update check this calls the GitHub Releases API for
 * spkldbrd/hostlinks-zoom and caches the response for 12 hours. A newer
 * release tag shows the normal Plugins update notice. Opening a Hostlinks
 * Zoom admin screen clears that cache.
 *
 * Ship a version by bumping HLZ_VERSION and pushing to main. GitHub Actions
 * builds hostlinks-zoom.zip and attaches it to the release for that version.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class HLZ_Updater {

	private static $instance = null;

	private $plugin_slug;
	private $plugin_file;
	private $github_user;
	private $github_repo;
	private $api_url;
	private $transient_key;

	public static function init( string $plugin_file, string $github_user, string $github_repo ): self {
		if ( null === self::$instance ) {
			self::$instance = new self( $plugin_file, $github_user, $github_repo );
		}
		return self::$instance;
	}

	public static function instance(): ?self {
		return self::$instance;
	}

	public function __construct( string $plugin_file, string $github_user, string $github_repo ) {
		$this->plugin_file   = $plugin_file;
		$this->plugin_slug   = plugin_basename( $plugin_file );
		$this->github_user   = $github_user;
		$this->github_repo   = $github_repo;
		$this->api_url       = "https://api.github.com/repos/{$github_user}/{$github_repo}/releases/latest";
		$this->transient_key = 'hlz_github_update_' . md5( $this->api_url );

		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'check_for_update' ) );
		add_filter( 'plugins_api', array( $this, 'plugin_info' ), 10, 3 );
		add_filter( 'upgrader_source_selection', array( $this, 'fix_source_dir' ), 10, 4 );
		add_action( 'upgrader_process_complete', array( $this, 'clear_cache' ), 10, 2 );

		add_action( 'load-toplevel_page_hostlinks-zoom', array( $this, 'bust_cache_on_page_load' ) );
		add_action( 'load-hostlinks-zoom_page_hostlinks-zoom-settings', array( $this, 'bust_cache_on_page_load' ) );
		add_action( 'load-hostlinks-zoom_page_hostlinks-zoom-templates', array( $this, 'bust_cache_on_page_load' ) );
	}

	public function bust_cache_on_page_load(): void {
		delete_transient( $this->transient_key );
	}

	private function get_release() {
		$cached = get_transient( $this->transient_key );
		if ( $cached !== false ) {
			return $cached;
		}

		$response = wp_remote_get(
			$this->api_url,
			array(
				'timeout' => 10,
				'headers' => array(
					'Accept'     => 'application/vnd.github.v3+json',
					'User-Agent' => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . get_bloginfo( 'url' ),
				),
			)
		);

		if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
			return false;
		}

		$release = json_decode( wp_remote_retrieve_body( $response ) );
		if ( empty( $release->tag_name ) ) {
			return false;
		}

		set_transient( $this->transient_key, $release, 12 * HOUR_IN_SECONDS );
		return $release;
	}

	private function clean_version( string $tag ): string {
		return ltrim( $tag, 'vV' );
	}

	public function check_for_update( $transient ) {
		if ( empty( $transient->checked ) ) {
			return $transient;
		}

		$release = $this->get_release();
		if ( ! $release ) {
			return $transient;
		}

		$remote_version  = $this->clean_version( $release->tag_name );
		$current_version = $transient->checked[ $this->plugin_slug ] ?? HLZ_VERSION;

		if ( version_compare( $remote_version, $current_version, '>' ) ) {
			$package = $this->get_package_url( $release, $remote_version );

			$transient->response[ $this->plugin_slug ] = (object) array(
				'id'           => $this->plugin_slug,
				'slug'         => dirname( $this->plugin_slug ),
				'plugin'       => $this->plugin_slug,
				'new_version'  => $remote_version,
				'url'          => 'https://digitalsolution.com',
				'package'      => $package,
				'icons'        => array(),
				'banners'      => array(),
				'tested'       => '',
				'requires_php' => '8.0',
			);
		}

		return $transient;
	}

	public function plugin_info( $result, $action, $args ) {
		if ( $action !== 'plugin_information' ) {
			return $result;
		}
		if ( ! isset( $args->slug ) || $args->slug !== dirname( $this->plugin_slug ) ) {
			return $result;
		}

		$release = $this->get_release();
		if ( ! $release ) {
			return $result;
		}

		$version       = $this->clean_version( $release->tag_name );
		$download_link = $this->get_package_url( $release, $version );

		return (object) array(
			'name'              => 'Hostlinks Zoom',
			'slug'              => dirname( $this->plugin_slug ),
			'version'           => $version,
			'author'            => '<a href="https://digitalsolution.com">Digital Solution</a>',
			'homepage'          => 'https://digitalsolution.com',
			'requires'          => '6.0',
			'requires_php'      => '8.0',
			'tested'            => '',
			'last_updated'      => '',
			'short_description' => 'Match past Zoom classes to cloud recordings and email replay links to active registrants.',
			'download_link'     => $download_link,
			'banners'           => array(),
			'icons'             => array(),
			'sections'          => array(
				'description' => 'Match past Zoom classes to cloud recordings and email replay links to active registrants.',
				'changelog'   => nl2br( esc_html( $release->body ?? '' ) ),
			),
		);
	}

	private function get_package_url( $release, string $version ): string {
		if ( ! empty( $release->assets ) ) {
			foreach ( $release->assets as $asset ) {
				if (
					isset( $asset->name, $asset->browser_download_url ) &&
					$asset->name === $this->github_repo . '.zip' &&
					$asset->state === 'uploaded'
				) {
					return $asset->browser_download_url;
				}
			}
		}

		$tag = $release->tag_name ?? $version;
		return "https://github.com/{$this->github_user}/{$this->github_repo}/archive/refs/tags/{$tag}.zip";
	}

	public function fix_source_dir( $source, $remote_source, $upgrader, $hook_extra ) {
		global $wp_filesystem;

		$correct_slug     = dirname( $this->plugin_slug );
		$main_plugin_file = $correct_slug . '.php';
		$correct_dir      = trailingslashit( $remote_source ) . $correct_slug;

		if ( untrailingslashit( $source ) === untrailingslashit( $correct_dir ) ) {
			return $source;
		}

		if ( ! $wp_filesystem->exists( trailingslashit( $source ) . $main_plugin_file ) ) {
			return $source;
		}

		if ( $wp_filesystem->exists( $correct_dir ) ) {
			$wp_filesystem->delete( $correct_dir, true );
		}

		if ( $wp_filesystem->move( $source, $correct_dir ) ) {
			return trailingslashit( $correct_dir );
		}

		return $source;
	}

	public function clear_cache( $upgrader, $options ): void {
		if (
			isset( $options['action'], $options['type'], $options['plugins'] ) &&
			$options['action'] === 'update' &&
			$options['type'] === 'plugin' &&
			in_array( $this->plugin_slug, $options['plugins'], true )
		) {
			delete_transient( $this->transient_key );
		}
	}

	public function get_latest_release() {
		return $this->get_release();
	}
}
