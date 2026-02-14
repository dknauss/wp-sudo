<?php
/**
 * Tests for Modal.
 *
 * @package WP_Sudo
 */

namespace WP_Sudo\Tests\Unit;

use WP_Sudo\Challenge;
use WP_Sudo\Modal;
use WP_Sudo\Sudo_Session;
use WP_Sudo\Tests\TestCase;
use Brain\Monkey\Functions;
use Brain\Monkey\Actions;

/**
 * @covers \WP_Sudo\Modal
 */
class ModalTest extends TestCase {

	/**
	 * Modal instance under test.
	 *
	 * @var Modal
	 */
	private Modal $modal;

	protected function setUp(): void {
		parent::setUp();
		$this->modal = new Modal();
	}

	/**
	 * Test register hooks the correct actions.
	 */
	public function test_register_hooks(): void {
		Actions\expectAdded( 'admin_footer' )
			->once()
			->with( array( $this->modal, 'render_modal' ), \Mockery::any() );

		Actions\expectAdded( 'admin_enqueue_scripts' )
			->once()
			->with( array( $this->modal, 'enqueue_assets' ), \Mockery::any() );

		Actions\expectAdded( 'admin_notices' )
			->once()
			->with( array( $this->modal, 'render_fallback_notice' ), \Mockery::any() );

		$this->modal->register();
	}

	/**
	 * Test render_modal skips for anonymous users.
	 */
	public function test_render_modal_skips_anonymous(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 0 );

		ob_start();
		$this->modal->render_modal();
		$output = ob_get_clean();

		$this->assertEmpty( $output );
	}

	/**
	 * Test render_modal skips when session is already active.
	 */
	public function test_render_modal_skips_active_session(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 5 );

		// v2: is_active() checks expiry + token only. No role check.
		$token = 'test-modal-token';

		Functions\when( 'get_user_meta' )->alias( function ( $uid, $key, $single ) use ( $token ) {
			if ( Sudo_Session::META_KEY === $key ) {
				return time() + 600;
			}
			if ( Sudo_Session::TOKEN_META_KEY === $key ) {
				return hash( 'sha256', $token );
			}
			return '';
		} );

		$_COOKIE[ Sudo_Session::TOKEN_COOKIE ] = $token;

		ob_start();
		$this->modal->render_modal();
		$output = ob_get_clean();

		$this->assertEmpty( $output );

		unset( $_COOKIE[ Sudo_Session::TOKEN_COOKIE ] );
	}

	/**
	 * Test render_modal outputs dialog on any admin page (no gated-page check).
	 *
	 * The modal renders globally so the keyboard shortcut (Ctrl+Shift+S)
	 * is available on all admin pages.
	 */
	public function test_render_modal_outputs_dialog(): void {
		// No $pagenow needed — modal renders on ALL admin pages.
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		Functions\when( 'get_user_meta' )->justReturn( '' );
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( '__' )->returnArg();
		Functions\when( 'apply_filters' )->returnArg( 2 );

		// Stub functions used in render.
		Functions\when( 'esc_html_e' )->alias(
			function ( $text ) {
				echo $text; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
		);
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'get_userdata' )->justReturn( null );

		ob_start();
		$this->modal->render_modal();
		$output = ob_get_clean();

		$this->assertStringContainsString( '<dialog id="wp-sudo-modal"', $output );
		$this->assertStringContainsString( 'wp-sudo-modal-password-step', $output );
		$this->assertStringContainsString( 'wp-sudo-modal-2fa-step', $output );
		$this->assertStringContainsString( 'wp-sudo-modal-loading', $output );
		$this->assertStringContainsString( 'aria-labelledby="wp-sudo-modal-title"', $output );
		$this->assertStringContainsString( 'wp-sudo-modal-action-label', $output );
	}

	/**
	 * Test enqueue_assets skips for anonymous users.
	 */
	public function test_enqueue_assets_skips_anonymous(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 0 );

		Functions\expect( 'wp_enqueue_style' )->never();
		Functions\expect( 'wp_enqueue_script' )->never();

		$this->modal->enqueue_assets();
	}

	/**
	 * Test enqueue_assets skips when session is active.
	 */
	public function test_enqueue_assets_skips_active_session(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 5 );

		// v2: is_active() checks expiry + token only. No role check.
		$token = 'test-enqueue-token';

		Functions\when( 'get_user_meta' )->alias( function ( $uid, $key, $single ) use ( $token ) {
			if ( Sudo_Session::META_KEY === $key ) {
				return time() + 600;
			}
			if ( Sudo_Session::TOKEN_META_KEY === $key ) {
				return hash( 'sha256', $token );
			}
			return '';
		} );

		$_COOKIE[ Sudo_Session::TOKEN_COOKIE ] = $token;

		Functions\expect( 'wp_enqueue_style' )->never();
		Functions\expect( 'wp_enqueue_script' )->never();

		$this->modal->enqueue_assets();

		unset( $_COOKIE[ Sudo_Session::TOKEN_COOKIE ] );
	}

	/**
	 * Test enqueue_assets loads modal, shortcut, and intercept on gated page.
	 *
	 * On a page with gated AJAX/REST rules (plugins.php), all three scripts
	 * plus the modal CSS should load.
	 */
	public function test_enqueue_assets_loads_all_on_gated_page(): void {
		// Set pagenow to a page with gated AJAX rules.
		global $pagenow;
		$pagenow = 'plugins.php';

		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		Functions\when( 'get_user_meta' )->justReturn( '' );
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( '__' )->returnArg();
		Functions\when( 'apply_filters' )->returnArg( 2 );

		// Expect modal CSS.
		Functions\expect( 'wp_enqueue_style' )
			->once()
			->with( 'wp-sudo-modal', \Mockery::type( 'string' ), array(), WP_SUDO_VERSION );

		// Expect three scripts: modal, shortcut, intercept.
		Functions\expect( 'wp_enqueue_script' )
			->times( 3 );

		Functions\expect( 'admin_url' )
			->once()
			->with( 'admin-ajax.php' )
			->andReturn( 'https://example.com/wp-admin/admin-ajax.php' );

		Functions\expect( 'wp_create_nonce' )
			->once()
			->with( Challenge::NONCE_ACTION )
			->andReturn( 'test-nonce-456' );

		Functions\expect( 'wp_localize_script' )
			->once()
			->with(
				'wp-sudo-modal',
				'wpSudoModal',
				\Mockery::on(
					function ( $data ) {
						return isset( $data['ajaxUrl'] )
							&& isset( $data['nonce'] )
							&& Challenge::AJAX_AUTH_ACTION === $data['authAction']
							&& Challenge::AJAX_2FA_ACTION === $data['tfaAction'];
					}
				)
			);

		$this->modal->enqueue_assets();

		$pagenow = null;
	}

	/**
	 * Test enqueue_assets loads modal and shortcut (but not intercept) on non-gated page.
	 *
	 * The keyboard shortcut requires the modal on all admin pages, but
	 * the intercept script (fetch/jQuery patching) only loads on pages
	 * with gated AJAX/REST operations.
	 */
	public function test_enqueue_assets_loads_shortcut_without_intercept_on_non_gated_page(): void {
		// Set pagenow to a page WITHOUT gated AJAX rules.
		global $pagenow;
		$pagenow = 'index.php';

		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		Functions\when( 'get_user_meta' )->justReturn( '' );
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( '__' )->returnArg();
		Functions\when( 'apply_filters' )->returnArg( 2 );

		// Expect modal CSS.
		Functions\expect( 'wp_enqueue_style' )
			->once()
			->with( 'wp-sudo-modal', \Mockery::type( 'string' ), array(), WP_SUDO_VERSION );

		// Expect modal JS and shortcut JS, but NOT intercept JS.
		$enqueued_scripts = array();
		Functions\expect( 'wp_enqueue_script' )
			->times( 2 )
			->andReturnUsing(
				function ( $handle ) use ( &$enqueued_scripts ) {
					$enqueued_scripts[] = $handle;
				}
			);

		Functions\expect( 'admin_url' )
			->once()
			->with( 'admin-ajax.php' )
			->andReturn( 'https://example.com/wp-admin/admin-ajax.php' );

		Functions\expect( 'wp_create_nonce' )
			->once()
			->with( Challenge::NONCE_ACTION )
			->andReturn( 'test-nonce-789' );

		Functions\expect( 'wp_localize_script' )->once();

		$this->modal->enqueue_assets();

		$this->assertContains( 'wp-sudo-modal', $enqueued_scripts );
		$this->assertContains( 'wp-sudo-shortcut', $enqueued_scripts );
		$this->assertNotContains( 'wp-sudo-intercept', $enqueued_scripts );

		$pagenow = null;
	}

	/**
	 * Test that intercept script loads only on gated AJAX pages.
	 */
	public function test_enqueue_assets_includes_intercept_on_gated_page(): void {
		global $pagenow;
		$pagenow = 'plugins.php';

		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		Functions\when( 'get_user_meta' )->justReturn( '' );
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( '__' )->returnArg();
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$enqueued_scripts = array();
		Functions\expect( 'wp_enqueue_style' )->once();
		Functions\expect( 'wp_enqueue_script' )
			->times( 3 )
			->andReturnUsing(
				function ( $handle ) use ( &$enqueued_scripts ) {
					$enqueued_scripts[] = $handle;
				}
			);

		Functions\expect( 'admin_url' )->once()->andReturn( 'https://example.com/wp-admin/admin-ajax.php' );
		Functions\expect( 'wp_create_nonce' )->once()->andReturn( 'test-nonce' );
		Functions\expect( 'wp_localize_script' )->once();

		$this->modal->enqueue_assets();

		$this->assertContains( 'wp-sudo-modal', $enqueued_scripts );
		$this->assertContains( 'wp-sudo-shortcut', $enqueued_scripts );
		$this->assertContains( 'wp-sudo-intercept', $enqueued_scripts );

		$pagenow = null;
	}

	/**
	 * Test render_modal skips on the challenge page.
	 *
	 * The challenge page has its own reauth UI — loading the modal
	 * there would be redundant and could interfere.
	 */
	public function test_render_modal_skips_on_challenge_page(): void {
		$_GET['page'] = 'wp-sudo-challenge';

		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		Functions\when( 'get_user_meta' )->justReturn( '' );
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();

		ob_start();
		$this->modal->render_modal();
		$output = ob_get_clean();

		$this->assertEmpty( $output );

		unset( $_GET['page'] );
	}

	/**
	 * Test enqueue_assets skips on the challenge page.
	 *
	 * The intercept script patches fetch() and would interfere
	 * with the challenge page's direct AJAX calls.
	 */
	public function test_enqueue_assets_skips_on_challenge_page(): void {
		$_GET['page'] = 'wp-sudo-challenge';

		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		Functions\when( 'get_user_meta' )->justReturn( '' );
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();

		Functions\expect( 'wp_enqueue_style' )->never();
		Functions\expect( 'wp_enqueue_script' )->never();

		$this->modal->enqueue_assets();

		unset( $_GET['page'] );
	}

	// ── Fallback notice ─────────────────────────────────────────────

	/**
	 * Test fallback notice renders when blocked transient exists.
	 */
	public function test_fallback_notice_renders_when_blocked(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		Functions\when( 'get_user_meta' )->justReturn( '' );
		Functions\when( 'get_option' )->justReturn( array() );

		Functions\expect( 'get_transient' )
			->once()
			->with( Modal::BLOCKED_TRANSIENT_PREFIX . '5' )
			->andReturn( array( 'rule_id' => 'plugin.activate', 'label' => 'Activate plugin' ) );

		Functions\expect( 'delete_transient' )
			->once()
			->with( Modal::BLOCKED_TRANSIENT_PREFIX . '5' );

		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();

		ob_start();
		$this->modal->render_fallback_notice();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'notice-warning', $output );
		$this->assertStringContainsString( 'Activate plugin', $output );
	}

	/**
	 * Test fallback notice is skipped for anonymous users.
	 */
	public function test_fallback_notice_skips_anonymous(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 0 );

		Functions\expect( 'get_transient' )->never();

		ob_start();
		$this->modal->render_fallback_notice();
		$output = ob_get_clean();

		$this->assertEmpty( $output );
	}

	/**
	 * Test fallback notice is skipped when sudo is active.
	 */
	public function test_fallback_notice_skips_active_session(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 5 );

		$token = 'test-fallback-token';

		Functions\when( 'get_user_meta' )->alias( function ( $uid, $key, $single ) use ( $token ) {
			if ( Sudo_Session::META_KEY === $key ) {
				return time() + 600;
			}
			if ( Sudo_Session::TOKEN_META_KEY === $key ) {
				return hash( 'sha256', $token );
			}
			return '';
		} );

		$_COOKIE[ Sudo_Session::TOKEN_COOKIE ] = $token;

		Functions\expect( 'get_transient' )->never();

		ob_start();
		$this->modal->render_fallback_notice();
		$output = ob_get_clean();

		$this->assertEmpty( $output );

		unset( $_COOKIE[ Sudo_Session::TOKEN_COOKIE ] );
	}

	/**
	 * Test fallback notice is skipped when no blocked transient exists.
	 */
	public function test_fallback_notice_skips_without_transient(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		Functions\when( 'get_user_meta' )->justReturn( '' );
		Functions\when( 'get_option' )->justReturn( array() );

		Functions\expect( 'get_transient' )
			->once()
			->with( Modal::BLOCKED_TRANSIENT_PREFIX . '5' )
			->andReturn( false );

		Functions\expect( 'delete_transient' )->never();

		ob_start();
		$this->modal->render_fallback_notice();
		$output = ob_get_clean();

		$this->assertEmpty( $output );
	}

	/**
	 * Test blocked transient prefix constant value.
	 */
	public function test_blocked_transient_prefix(): void {
		$this->assertSame( '_wp_sudo_blocked_', Modal::BLOCKED_TRANSIENT_PREFIX );
	}

	/**
	 * Test that Modal does not register its own AJAX handlers.
	 *
	 * The modal reuses Challenge's AJAX auth endpoints. It should
	 * not register wp_ajax_ actions — that's Challenge's job.
	 */
	public function test_modal_does_not_register_ajax_handlers(): void {
		// We only expect admin_footer, admin_enqueue_scripts, and admin_notices.
		Actions\expectAdded( 'admin_footer' )->once();
		Actions\expectAdded( 'admin_enqueue_scripts' )->once();
		Actions\expectAdded( 'admin_notices' )->once();

		// Ensure no wp_ajax_ actions are registered.
		Actions\expectAdded( 'wp_ajax_wp_sudo_challenge_auth' )->never();
		Actions\expectAdded( 'wp_ajax_wp_sudo_challenge_2fa' )->never();

		$this->modal->register();
	}
}
