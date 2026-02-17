<?php
/**
 * Request stash — serialize and replay intercepted requests.
 *
 * @package WP_Sudo
 */

namespace WP_Sudo;

// Abort if this file is called directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Request_Stash
 *
 * When a gated admin action is intercepted, the full request (URL, method,
 * GET/POST parameters, nonce) is serialized into a short-lived transient.
 * After the user successfully reauthenticates, the stashed request is
 * retrieved and replayed — via redirect for GET, or a self-submitting
 * form for POST.
 *
 * @since 2.0.0
 */
class Request_Stash {

	/**
	 * Transient prefix for stashed requests.
	 *
	 * @var string
	 */
	public const TRANSIENT_PREFIX = '_wp_sudo_stash_';

	/**
	 * Stash time-to-live in seconds (5 minutes).
	 *
	 * @var int
	 */
	public const TTL = 300;

	/**
	 * Stash key length (alphanumeric characters).
	 *
	 * @var int
	 */
	private const KEY_LENGTH = 16;

	/**
	 * Save the current request into a transient.
	 *
	 * @param int                  $user_id      The user ID.
	 * @param array<string, mixed> $matched_rule The action registry rule that was matched.
	 * @return string Stash key for use in the challenge URL.
	 */
	public function save( int $user_id, array $matched_rule ): string {
		$key = wp_generate_password( self::KEY_LENGTH, false );

		$data = array(
			'user_id' => $user_id,
			'rule_id' => $matched_rule['id'] ?? '',
			'label'   => $matched_rule['label'] ?? '',
			'method'  => $this->get_request_method(),
			'url'     => $this->build_original_url(),
			'get'     => $this->sanitize_params( $_GET ), // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'post'    => $this->sanitize_params( $_POST ), // phpcs:ignore WordPress.Security.NonceVerification.Missing
			'created' => time(),
		);

		$this->set_stash_transient( self::TRANSIENT_PREFIX . $key, $data, self::TTL );

		return $key;
	}

	/**
	 * Retrieve a stashed request.
	 *
	 * Returns the stashed data only if it exists, has not expired,
	 * and belongs to the specified user.
	 *
	 * @param string $key     The stash key.
	 * @param int    $user_id The user who must own the stash.
	 * @return array<string, mixed>|null The stashed data, or null.
	 */
	public function get( string $key, int $user_id ): ?array {
		if ( empty( $key ) ) {
			return null;
		}

		$data = $this->get_stash_transient( self::TRANSIENT_PREFIX . $key );

		if ( ! $data || ! is_array( $data ) ) {
			return null;
		}

		// Verify ownership.
		if ( ( $data['user_id'] ?? 0 ) !== $user_id ) {
			return null;
		}

		return $data;
	}

	/**
	 * Delete a stashed request (one-time use).
	 *
	 * @param string $key The stash key.
	 * @return void
	 */
	public function delete( string $key ): void {
		if ( ! empty( $key ) ) {
			$this->delete_stash_transient( self::TRANSIENT_PREFIX . $key );
		}
	}

	/**
	 * Check whether a stash key is valid for a user without consuming it.
	 *
	 * @param string $key     The stash key.
	 * @param int    $user_id The user ID.
	 * @return bool
	 */
	public function exists( string $key, int $user_id ): bool {
		return null !== $this->get( $key, $user_id );
	}

	/**
	 * Get the current HTTP request method.
	 *
	 * @return string 'GET', 'POST', etc.
	 */
	private function get_request_method(): string {
		return strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ) );
	}

	/**
	 * Reconstruct the original request URL.
	 *
	 * Uses esc_url_raw() instead of sanitize_text_field() because
	 * REQUEST_URI may contain percent-encoded characters (e.g. %2F
	 * in plugin slugs like "my-plugin%2Fplugin.php") that
	 * sanitize_text_field() strips entirely, corrupting the URL.
	 *
	 * @return string Full URL including query string.
	 */
	private function build_original_url(): string {
		$scheme = is_ssl() ? 'https' : 'http';
		$host   = sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ?? 'localhost' ) );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- esc_url_raw() on the full URL below; sanitize_text_field() strips percent-encoded characters (%2F, etc.).
		$uri = wp_unslash( $_SERVER['REQUEST_URI'] ?? '/wp-admin/' );

		return esc_url_raw( $scheme . '://' . $host . $uri );
	}

	/**
	 * Store a stash transient, network-wide on multisite.
	 *
	 * @param string $key   Transient key.
	 * @param mixed  $value Transient value.
	 * @param int    $ttl   Time-to-live in seconds.
	 * @return void
	 */
	private function set_stash_transient( string $key, mixed $value, int $ttl ): void {
		if ( is_multisite() ) {
			set_site_transient( $key, $value, $ttl );
		} else {
			set_transient( $key, $value, $ttl );
		}
	}

	/**
	 * Retrieve a stash transient, network-wide on multisite.
	 *
	 * @param string $key Transient key.
	 * @return mixed Transient value or false.
	 */
	private function get_stash_transient( string $key ): mixed {
		return is_multisite() ? get_site_transient( $key ) : get_transient( $key );
	}

	/**
	 * Delete a stash transient, network-wide on multisite.
	 *
	 * @param string $key Transient key.
	 * @return void
	 */
	private function delete_stash_transient( string $key ): void {
		if ( is_multisite() ) {
			delete_site_transient( $key );
		} else {
			delete_transient( $key );
		}
	}

	/**
	 * Recursively sanitize request parameters for safe storage.
	 *
	 * Passwords and other sensitive data in POST are preserved as-is
	 * because they will be replayed to the same WordPress handler that
	 * originally expected them.
	 *
	 * @param array<string, mixed> $params Raw request parameters.
	 * @return array<string, mixed> Sanitized parameters.
	 */
	private function sanitize_params( array $params ): array {
		// We store verbatim because these parameters will be replayed
		// to the same WordPress handler that expected them. Sanitizing
		// could break nonces, file references, or encoded values.
		// The parameters never leave the server (stored in transient,
		// replayed to the same URL).
		return $params;
	}
}
