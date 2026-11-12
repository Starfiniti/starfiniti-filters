<?php
/**
 * Updates from the public GitHub releases of this plugin.
 *
 * @package StarfinitiFilters
 */

namespace Starfiniti\Filters;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tells WordPress about new releases and checks each package against its published SHA-256 checksum
 * before WordPress installs it. Only the public release data is read, nothing about the store is sent.
 */
final class Updater {

	/**
	 * Must match the `Update URI` header of the plugin.
	 */
	public const UPDATE_URI = 'https://github.com/Starfiniti/starfiniti-filters';

	public const CACHE_KEY = 'sffilters_release';

	private const RELEASE_API = 'https://api.github.com/repos/Starfiniti/starfiniti-filters/releases/latest';

	private const REPOSITORY_PATH = '/Starfiniti/starfiniti-filters';

	private const REQUIRES_WP = '6.6';

	private const REQUIRES_PHP = '8.1';

	private const MAX_RESPONSE_BYTES = 262144;

	private const MAX_PACKAGE_BYTES = 20971520;

	public function register(): void {
		add_filter( 'update_plugins_github.com', array( $this, 'update' ), 10, 3 );
		add_filter( 'plugins_api', array( $this, 'information' ), 10, 3 );
		add_filter( 'upgrader_pre_download', array( $this, 'download' ), 10, 2 );
		add_action( 'upgrader_process_complete', array( $this, 'forget' ), 10, 2 );
	}

	/**
	 * Answers the update check of WordPress for this plugin only.
	 *
	 * @param array|false $update      Update found so far.
	 * @param array       $plugin_data Plugin headers.
	 * @param string      $plugin_file Plugin file relative to the plugins folder.
	 * @return array|false
	 */
	public function update( $update, $plugin_data, $plugin_file ) {
		if ( self::file() !== $plugin_file || ! is_array( $plugin_data ) || self::UPDATE_URI !== ( $plugin_data['UpdateURI'] ?? '' ) ) {
			return $update;
		}

		$release = $this->release();

		if ( null === $release || version_compare( $release['version'], SFFILTERS_VERSION, '<=' ) ) {
			return false;
		}

		return array(
			'id'           => self::UPDATE_URI,
			'slug'         => self::slug(),
			'plugin'       => self::file(),
			'version'      => $release['version'],
			'url'          => $release['url'],
			'package'      => $release['package'],
			'requires'     => self::REQUIRES_WP,
			'requires_php' => self::REQUIRES_PHP,
		);
	}

	/**
	 * Fills the "View details" window of WordPress.
	 *
	 * @param mixed  $result Result so far.
	 * @param string $action Requested action.
	 * @param object $args   Request arguments.
	 */
	public function information( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || ! is_object( $args ) || self::slug() !== ( $args->slug ?? '' ) ) {
			return $result;
		}

		$release = $this->release();

		if ( null === $release ) {
			return $result;
		}

		return (object) array(
			'name'          => 'Starfiniti Filters for WooCommerce',
			'slug'          => self::slug(),
			'version'       => $release['version'],
			'author'        => '<a href="https://starfiniti.com/">Starfiniti</a>',
			'homepage'      => self::UPDATE_URI,
			'requires'      => self::REQUIRES_WP,
			'requires_php'  => self::REQUIRES_PHP,
			'download_link' => $release['package'],
			'sections'      => array(
				'description' => esc_html__( 'Product filters for the WooCommerce shop your theme already renders.', 'starfiniti-filters' ),
				'changelog'   => self::notes( $release['notes'] ),
			),
		);
	}

	/**
	 * Downloads a release of this plugin and refuses it unless it matches its published checksum.
	 *
	 * @param mixed  $reply   Result so far. False lets WordPress download the package itself.
	 * @param string $package Package URL.
	 * @return mixed A file path, an error, or the unchanged reply for packages of other plugins.
	 */
	public function download( $reply, $package ) {
		if ( false !== $reply || ! is_string( $package ) || ! str_starts_with( $package, self::UPDATE_URI . '/releases/download/' ) ) {
			return $reply;
		}

		$release  = $this->release();
		$checksum = null !== $release && $package === $release['package'] ? $this->checksum( $release ) : '';

		if ( '' === $checksum ) {
			return new WP_Error( 'sffilters_update_unverified', __( 'The update was not installed, because its checksum could not be read from GitHub. Try again later.', 'starfiniti-filters' ) );
		}

		if ( ! function_exists( 'download_url' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$file = download_url( $package, 300 );

		if ( is_wp_error( $file ) ) {
			return $file;
		}

		$size = (int) filesize( $file );

		if ( $size <= 0 || $size > self::MAX_PACKAGE_BYTES || ! hash_equals( $checksum, (string) hash_file( 'sha256', $file ) ) ) {
			wp_delete_file( $file );

			return new WP_Error( 'sffilters_update_mismatch', __( 'The update was not installed, because the downloaded file does not match its published checksum.', 'starfiniti-filters' ) );
		}

		return $file;
	}

	/**
	 * Drops the cached release once this plugin has been updated.
	 *
	 * @param mixed $upgrader   Upgrader instance.
	 * @param array $hook_extra What was updated.
	 */
	public function forget( $upgrader, $hook_extra ): void {
		if ( ! is_array( $hook_extra ) || 'plugin' !== ( $hook_extra['type'] ?? '' ) ) {
			return;
		}

		$plugins   = (array) ( $hook_extra['plugins'] ?? array() );
		$plugins[] = $hook_extra['plugin'] ?? '';

		if ( in_array( self::file(), $plugins, true ) ) {
			delete_site_transient( self::CACHE_KEY );
		}
	}

	private static function file(): string {
		return plugin_basename( SFFILTERS_PLUGIN_FILE );
	}

	private static function slug(): string {
		return dirname( self::file() );
	}

	/**
	 * Returns the latest stable release, read from GitHub at most twice a day.
	 *
	 * A failed read is remembered for an hour, so an unreachable GitHub does not slow the admin down.
	 *
	 * @return array{version: string, package: string, checksum: string, checksum_url: string, url: string, notes: string}|null
	 */
	private function release(): ?array {
		$cached = get_site_transient( self::CACHE_KEY );

		if ( is_array( $cached ) && array_key_exists( 'release', $cached ) ) {
			return is_array( $cached['release'] ) ? $cached['release'] : null;
		}

		$body    = $this->get( self::RELEASE_API );
		$data    = null !== $body ? json_decode( $body, true ) : null;
		$release = is_array( $data ) ? self::read( $data ) : null;

		set_site_transient( self::CACHE_KEY, array( 'release' => $release ), null === $release ? HOUR_IN_SECONDS : 12 * HOUR_IN_SECONDS );

		return $release;
	}

	/**
	 * Picks the version, the package and its checksum out of a GitHub release.
	 *
	 * Drafts, pre-releases, tags that are not a plain version and files outside this repository are refused.
	 *
	 * @param array $data Release as returned by the GitHub API.
	 */
	private static function read( array $data ): ?array {
		$tag = is_string( $data['tag_name'] ?? null ) ? $data['tag_name'] : '';

		if ( ! empty( $data['draft'] ) || ! empty( $data['prerelease'] ) || 1 !== preg_match( '/^v?(\d+\.\d+\.\d+)$/', $tag, $match ) ) {
			return null;
		}

		$zip     = 'starfiniti-filters-' . $match[1] . '.zip';
		$release = array(
			'version'      => $match[1],
			'package'      => '',
			'checksum'     => '',
			'checksum_url' => '',
			'url'          => self::UPDATE_URI . '/releases/tag/' . $tag,
			'notes'        => substr( is_string( $data['body'] ?? null ) ? $data['body'] : '', 0, 20000 ),
		);

		foreach ( (array) ( $data['assets'] ?? array() ) as $asset ) {
			$name = is_array( $asset ) && is_string( $asset['name'] ?? null ) ? $asset['name'] : '';
			$url  = is_array( $asset ) && is_string( $asset['browser_download_url'] ?? null ) ? $asset['browser_download_url'] : '';

			if ( self::UPDATE_URI . '/releases/download/' . $tag . '/' . $name !== $url ) {
				continue;
			}

			if ( $zip === $name ) {
				$size = (int) ( $asset['size'] ?? 0 );

				if ( $size <= 0 || $size > self::MAX_PACKAGE_BYTES ) {
					return null;
				}

				$release['package'] = $url;

				if ( 1 === preg_match( '/^sha256:([a-f0-9]{64})$/', strtolower( (string) ( $asset['digest'] ?? '' ) ), $digest ) ) {
					$release['checksum'] = $digest[1];
				}
			} elseif ( $zip . '.sha256' === $name ) {
				$release['checksum_url'] = $url;
			}
		}

		if ( '' === $release['package'] || ( '' === $release['checksum'] && '' === $release['checksum_url'] ) ) {
			return null;
		}

		return $release;
	}

	/**
	 * Returns the SHA-256 checksum of the package, or an empty string when it cannot be read.
	 *
	 * @param array $release Release from read().
	 */
	private function checksum( array $release ): string {
		if ( '' !== $release['checksum'] ) {
			return $release['checksum'];
		}

		$body = $this->get( $release['checksum_url'] );

		return null !== $body && 1 === preg_match( '/^([a-f0-9]{64})(\s|$)/i', trim( $body ), $match ) ? strtolower( $match[1] ) : '';
	}

	/**
	 * Reads a small public file from GitHub.
	 */
	private function get( string $url ): ?string {
		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'             => 10,
				'redirection'         => 3,
				'limit_response_size' => self::MAX_RESPONSE_BYTES,
				'headers'             => array(
					'Accept'     => 'application/vnd.github+json',
					'User-Agent' => 'Starfiniti-Filters/' . SFFILTERS_VERSION,
				),
			)
		);

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		return (string) wp_remote_retrieve_body( $response );
	}

	/**
	 * Turns release notes written as a Markdown list into HTML.
	 */
	private static function notes( string $notes ): string {
		$html = '';
		$list = '';

		foreach ( preg_split( '/\R/', trim( $notes ) ) as $line ) {
			$line = trim( $line );

			if ( 1 === preg_match( '/^[-*]\s+(.+)$/', $line, $match ) ) {
				$list .= '<li>' . esc_html( $match[1] ) . '</li>';
				continue;
			}

			if ( '' !== $list ) {
				$html .= '<ul>' . $list . '</ul>';
				$list  = '';
			}

			if ( '' !== $line ) {
				$html .= '<p>' . esc_html( ltrim( $line, '# ' ) ) . '</p>';
			}
		}

		if ( '' !== $list ) {
			$html .= '<ul>' . $list . '</ul>';
		}

		return '' !== $html ? $html : esc_html__( 'See the release on GitHub for details.', 'starfiniti-filters' );
	}
}
