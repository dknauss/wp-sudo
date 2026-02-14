<?php
/**
 * Tests for Gate.
 *
 * @package WP_Sudo
 */

namespace WP_Sudo\Tests\Unit;

use WP_Sudo\Gate;
use WP_Sudo\Sudo_Session;
use WP_Sudo\Request_Stash;
use WP_Sudo\Action_Registry;
use WP_Sudo\Tests\TestCase;
use Brain\Monkey\Functions;
use Brain\Monkey\Actions;

/**
 * @covers \WP_Sudo\Gate
 */
class GateTest extends TestCase {

	/**
	 * Gate instance under test.
	 *
	 * @var Gate
	 */
	private Gate $gate;

	/**
	 * Mock session.
	 *
	 * @var Sudo_Session|\Mockery\MockInterface
	 */
	private $session;

	/**
	 * Mock stash.
	 *
	 * @var Request_Stash|\Mockery\MockInterface
	 */
	private $stash;

	protected function setUp(): void {
		parent::setUp();
		$this->session = \Mockery::mock( Sudo_Session::class );
		$this->stash   = \Mockery::mock( Request_Stash::class );
		$this->gate    = new Gate( $this->session, $this->stash );
	}

	protected function tearDown(): void {
		unset(
			$_REQUEST['action'],
			$_REQUEST['changeit'],
			$_REQUEST['new_role'],
			$_POST['role'],
			$_POST['approve'],
			$_POST['super_admin'],
			$_POST['slug'],
			$_POST['plugin'],
			$_GET['download'],
			$_SERVER['REQUEST_METHOD'],
			$GLOBALS['pagenow']
		);
		parent::tearDown();
	}

	// ── Surface detection ─────────────────────────────────────────────

	/**
	 * Test detect_surface returns 'ajax' when doing AJAX.
	 */
	public function test_detect_surface_ajax(): void {
		Functions\when( 'wp_doing_ajax' )->justReturn( true );
		Functions\when( 'wp_doing_cron' )->justReturn( false );
		Functions\when( 'is_admin' )->justReturn( true );

		$this->assertSame( 'ajax', $this->gate->detect_surface() );
	}

	/**
	 * Test detect_surface returns 'admin' for normal admin page.
	 */
	public function test_detect_surface_admin(): void {
		Functions\when( 'wp_doing_ajax' )->justReturn( false );
		Functions\when( 'wp_doing_cron' )->justReturn( false );
		Functions\when( 'is_admin' )->justReturn( true );

		$this->assertSame( 'admin', $this->gate->detect_surface() );
	}

	/**
	 * Test detect_surface returns 'cron' during cron.
	 */
	public function test_detect_surface_cron(): void {
		Functions\when( 'wp_doing_ajax' )->justReturn( false );
		Functions\when( 'wp_doing_cron' )->justReturn( true );
		Functions\when( 'is_admin' )->justReturn( false );

		$this->assertSame( 'cron', $this->gate->detect_surface() );
	}

	/**
	 * Test detect_surface returns 'unknown' for front-end.
	 */
	public function test_detect_surface_frontend(): void {
		Functions\when( 'wp_doing_ajax' )->justReturn( false );
		Functions\when( 'wp_doing_cron' )->justReturn( false );
		Functions\when( 'is_admin' )->justReturn( false );

		$this->assertSame( 'unknown', $this->gate->detect_surface() );
	}

	// ── Admin UI matching ─────────────────────────────────────────────

	/**
	 * Test match_request matches a plugin activation on admin surface.
	 */
	public function test_match_request_matches_plugin_activate(): void {
		Functions\when( '__' )->returnArg();
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$GLOBALS['pagenow']        = 'plugins.php';
		$_REQUEST['action']        = 'activate';
		$_SERVER['REQUEST_METHOD'] = 'GET';

		$rule = $this->gate->match_request( 'admin' );

		$this->assertNotNull( $rule );
		$this->assertSame( 'plugin.activate', $rule['id'] );
	}

	/**
	 * Test match_request matches plugin deactivation.
	 */
	public function test_match_request_matches_plugin_deactivate(): void {
		Functions\when( '__' )->returnArg();
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$GLOBALS['pagenow']        = 'plugins.php';
		$_REQUEST['action']        = 'deactivate';
		$_SERVER['REQUEST_METHOD'] = 'GET';

		$rule = $this->gate->match_request( 'admin' );

		$this->assertNotNull( $rule );
		$this->assertSame( 'plugin.deactivate', $rule['id'] );
	}

	/**
	 * Test match_request matches plugin deletion.
	 */
	public function test_match_request_matches_plugin_delete(): void {
		Functions\when( '__' )->returnArg();
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$GLOBALS['pagenow']        = 'plugins.php';
		$_REQUEST['action']        = 'delete-selected';
		$_SERVER['REQUEST_METHOD'] = 'POST';

		$rule = $this->gate->match_request( 'admin' );

		$this->assertNotNull( $rule );
		$this->assertSame( 'plugin.delete', $rule['id'] );
	}

	/**
	 * Test match_request does not match wrong pagenow.
	 */
	public function test_match_request_rejects_wrong_pagenow(): void {
		Functions\when( '__' )->returnArg();
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$GLOBALS['pagenow']        = 'edit.php';
		$_REQUEST['action']        = 'activate';
		$_SERVER['REQUEST_METHOD'] = 'GET';

		$this->assertNull( $this->gate->match_request( 'admin' ) );
	}

	/**
	 * Test match_request does not match wrong action.
	 */
	public function test_match_request_rejects_wrong_action(): void {
		Functions\when( '__' )->returnArg();
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$GLOBALS['pagenow']        = 'plugins.php';
		$_REQUEST['action']        = 'do-something-benign';
		$_SERVER['REQUEST_METHOD'] = 'GET';

		$this->assertNull( $this->gate->match_request( 'admin' ) );
	}

	/**
	 * Test match_request enforces HTTP method.
	 */
	public function test_match_request_enforces_http_method(): void {
		Functions\when( '__' )->returnArg();
		Functions\when( 'apply_filters' )->returnArg( 2 );

		// plugin.delete requires POST, try with GET.
		$GLOBALS['pagenow']        = 'plugins.php';
		$_REQUEST['action']        = 'delete-selected';
		$_SERVER['REQUEST_METHOD'] = 'GET';

		$this->assertNull( $this->gate->match_request( 'admin' ) );
	}

	/**
	 * Test match_request matches theme switch.
	 */
	public function test_match_request_matches_theme_switch(): void {
		Functions\when( '__' )->returnArg();
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$GLOBALS['pagenow']        = 'themes.php';
		$_REQUEST['action']        = 'activate';
		$_SERVER['REQUEST_METHOD'] = 'GET';

		$rule = $this->gate->match_request( 'admin' );

		$this->assertNotNull( $rule );
		$this->assertSame( 'theme.switch', $rule['id'] );
	}

	/**
	 * Test match_request matches user deletion.
	 */
	public function test_match_request_matches_user_delete(): void {
		Functions\when( '__' )->returnArg();
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$GLOBALS['pagenow']        = 'users.php';
		$_REQUEST['action']        = 'delete';
		$_SERVER['REQUEST_METHOD'] = 'POST';

		$rule = $this->gate->match_request( 'admin' );

		$this->assertNotNull( $rule );
		$this->assertSame( 'user.delete', $rule['id'] );
	}

	/**
	 * Test match_request matches user promote via "Change role to…" dropdown.
	 *
	 * WordPress sends action=-1, changeit=Change, new_role=editor when
	 * the admin uses the role dropdown on users.php instead of bulk actions.
	 */
	public function test_match_request_matches_user_promote_via_changeit(): void {
		Functions\when( '__' )->returnArg();
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();

		$GLOBALS['pagenow']        = 'users.php';
		$_REQUEST['action']        = '-1';
		$_REQUEST['changeit']      = 'Change';
		$_REQUEST['new_role']      = 'editor';
		$_SERVER['REQUEST_METHOD'] = 'GET';

		$rule = $this->gate->match_request( 'admin' );

		$this->assertNotNull( $rule );
		$this->assertSame( 'user.promote', $rule['id'] );
	}

	/**
	 * Test match_request matches user promote via direct action=promote.
	 */
	public function test_match_request_matches_user_promote_via_action(): void {
		Functions\when( '__' )->returnArg();
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();

		$GLOBALS['pagenow']        = 'users.php';
		$_REQUEST['action']        = 'promote';
		$_SERVER['REQUEST_METHOD'] = 'GET';

		$rule = $this->gate->match_request( 'admin' );

		$this->assertNotNull( $rule );
		$this->assertSame( 'user.promote', $rule['id'] );
	}

	/**
	 * Test match_request rejects action=-1 without changeit parameters.
	 *
	 * Ensures we don't false-positive on any users.php GET with action=-1.
	 */
	public function test_match_request_rejects_bare_negative_one_on_users(): void {
		Functions\when( '__' )->returnArg();
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();

		$GLOBALS['pagenow']        = 'users.php';
		$_REQUEST['action']        = '-1';
		$_SERVER['REQUEST_METHOD'] = 'GET';

		$this->assertNull( $this->gate->match_request( 'admin' ) );
	}

	/**
	 * Test match_request matches user role change on user-edit.php.
	 *
	 * WordPress user-edit.php submits action=update via POST, with a "role"
	 * field when editing another user's profile.
	 */
	public function test_match_request_matches_user_promote_profile(): void {
		Functions\when( '__' )->returnArg();
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();

		$GLOBALS['pagenow']        = 'user-edit.php';
		$_REQUEST['action']        = 'update';
		$_POST['role']             = 'editor';
		$_SERVER['REQUEST_METHOD'] = 'POST';

		$rule = $this->gate->match_request( 'admin' );

		$this->assertNotNull( $rule );
		$this->assertSame( 'user.promote_profile', $rule['id'] );
	}

	/**
	 * Test match_request does not match user-edit.php when no role field is present.
	 *
	 * Profile updates without a role change (e.g. editing display name) should
	 * not trigger the sudo gate.
	 */
	public function test_match_request_skips_user_edit_without_role(): void {
		Functions\when( '__' )->returnArg();
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();

		$GLOBALS['pagenow']        = 'user-edit.php';
		$_REQUEST['action']        = 'update';
		$_SERVER['REQUEST_METHOD'] = 'POST';
		// No $_POST['role'] — just a normal profile update.

		$rule = $this->gate->match_request( 'admin' );

		$this->assertNull( $rule );
	}

	// ── User creation matching ────────────────────────────────────────

	/**
	 * Test match_request matches admin user creation via createuser action.
	 */
	public function test_match_request_matches_admin_user_create(): void {
		Functions\when( '__' )->returnArg();
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$GLOBALS['pagenow']        = 'user-new.php';
		$_REQUEST['action']        = 'createuser';
		$_SERVER['REQUEST_METHOD'] = 'POST';

		$rule = $this->gate->match_request( 'admin' );

		$this->assertNotNull( $rule );
		$this->assertSame( 'user.create', $rule['id'] );
	}

	/**
	 * Test match_request matches REST user creation (POST to /wp/v2/users).
	 */
	public function test_match_request_matches_rest_user_create(): void {
		Functions\when( '__' )->returnArg();
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$request = new \WP_REST_Request( 'POST', '/wp/v2/users', array( 'roles' => array( 'editor' ) ) );

		$rule = $this->gate->match_request( 'rest', $request );

		$this->assertNotNull( $rule );
		$this->assertSame( 'user.create', $rule['id'] );
	}

	/**
	 * Test match_request matches REST user creation without roles param.
	 */
	public function test_match_request_matches_rest_user_create_no_roles(): void {
		Functions\when( '__' )->returnArg();
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$request = new \WP_REST_Request( 'POST', '/wp/v2/users', array( 'username' => 'newuser' ) );

		$rule = $this->gate->match_request( 'rest', $request );

		$this->assertNotNull( $rule );
		$this->assertSame( 'user.create', $rule['id'] );
	}

	/**
	 * Test match_request skips GET to /wp/v2/users (listing, not creation).
	 */
	public function test_match_request_skips_rest_get_users(): void {
		Functions\when( '__' )->returnArg();
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$request = new \WP_REST_Request( 'GET', '/wp/v2/users' );

		$this->assertNull( $this->gate->match_request( 'rest', $request ) );
	}

	// ── Application password matching ────────────────────────────────

	/**
	 * Test match_request matches admin app-password approval.
	 */
	public function test_match_request_matches_admin_app_password_approve(): void {
		Functions\when( '__' )->returnArg();
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$GLOBALS['pagenow']        = 'authorize-application.php';
		$_REQUEST['action']        = 'authorize_application_password';
		$_POST['approve']          = '1';
		$_SERVER['REQUEST_METHOD'] = 'POST';

		$rule = $this->gate->match_request( 'admin' );

		$this->assertNotNull( $rule );
		$this->assertSame( 'auth.app_password', $rule['id'] );
	}

	/**
	 * Test match_request skips admin app-password rejection (no approve param).
	 */
	public function test_match_request_skips_admin_app_password_reject(): void {
		Functions\when( '__' )->returnArg();
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$GLOBALS['pagenow']        = 'authorize-application.php';
		$_REQUEST['action']        = 'authorize_application_password';
		$_SERVER['REQUEST_METHOD'] = 'POST';
		// No $_POST['approve'] — user is rejecting.

		$this->assertNull( $this->gate->match_request( 'admin' ) );
	}

	/**
	 * Test match_request matches REST app-password creation (POST).
	 */
	public function test_match_request_matches_rest_app_password_create(): void {
		Functions\when( '__' )->returnArg();
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$request = new \WP_REST_Request( 'POST', '/wp/v2/users/42/application-passwords' );

		$rule = $this->gate->match_request( 'rest', $request );

		$this->assertNotNull( $rule );
		$this->assertSame( 'auth.app_password', $rule['id'] );
	}

	/**
	 * Test match_request matches REST app-password creation for "me" endpoint.
	 */
	public function test_match_request_matches_rest_app_password_create_me(): void {
		Functions\when( '__' )->returnArg();
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$request = new \WP_REST_Request( 'POST', '/wp/v2/users/me/application-passwords' );

		$rule = $this->gate->match_request( 'rest', $request );

		$this->assertNotNull( $rule );
		$this->assertSame( 'auth.app_password', $rule['id'] );
	}

	// ── Core update matching ─────────────────────────────────────────

	/**
	 * Test match_request matches core upgrade action.
	 */
	public function test_match_request_matches_core_upgrade(): void {
		Functions\when( '__' )->returnArg();
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$GLOBALS['pagenow']        = 'update-core.php';
		$_REQUEST['action']        = 'do-core-upgrade';
		$_SERVER['REQUEST_METHOD'] = 'POST';

		$rule = $this->gate->match_request( 'admin' );

		$this->assertNotNull( $rule );
		$this->assertSame( 'core.update', $rule['id'] );
	}

	/**
	 * Test match_request matches core reinstall action.
	 */
	public function test_match_request_matches_core_reinstall(): void {
		Functions\when( '__' )->returnArg();
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$GLOBALS['pagenow']        = 'update-core.php';
		$_REQUEST['action']        = 'do-core-reinstall';
		$_SERVER['REQUEST_METHOD'] = 'POST';

		$rule = $this->gate->match_request( 'admin' );

		$this->assertNotNull( $rule );
		$this->assertSame( 'core.update', $rule['id'] );
	}

	/**
	 * Test match_request skips core update page view (not an upgrade action).
	 */
	public function test_match_request_skips_core_update_page_load(): void {
		Functions\when( '__' )->returnArg();
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$GLOBALS['pagenow']        = 'update-core.php';
		$_REQUEST['action']        = 'upgrade-core';
		$_SERVER['REQUEST_METHOD'] = 'GET';

		$this->assertNull( $this->gate->match_request( 'admin' ) );
	}

	// ── Export matching ──────────────────────────────────────────────

	/**
	 * Test match_request matches WXR export download.
	 */
	public function test_match_request_matches_export_download(): void {
		Functions\when( '__' )->returnArg();
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$GLOBALS['pagenow']        = 'export.php';
		$_REQUEST['action']        = '';
		$_GET['download']          = 'true';
		$_SERVER['REQUEST_METHOD'] = 'GET';

		$rule = $this->gate->match_request( 'admin' );

		$this->assertNotNull( $rule );
		$this->assertSame( 'tools.export', $rule['id'] );
	}

	/**
	 * Test match_request skips export page view (no download param).
	 */
	public function test_match_request_skips_export_page_view(): void {
		Functions\when( '__' )->returnArg();
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$GLOBALS['pagenow']        = 'export.php';
		$_REQUEST['action']        = '';
		$_SERVER['REQUEST_METHOD'] = 'GET';
		// No $_GET['download'] — just viewing the export page.

		$this->assertNull( $this->gate->match_request( 'admin' ) );
	}

	// ── AJAX matching ─────────────────────────────────────────────────

	/**
	 * Test match_request matches AJAX plugin delete action.
	 */
	public function test_match_request_matches_ajax_delete_plugin(): void {
		Functions\when( '__' )->returnArg();
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$_REQUEST['action'] = 'delete-plugin';

		$rule = $this->gate->match_request( 'ajax' );

		$this->assertNotNull( $rule );
		$this->assertSame( 'plugin.delete', $rule['id'] );
	}

	/**
	 * Test match_request matches AJAX install-plugin action.
	 */
	public function test_match_request_matches_ajax_install_plugin(): void {
		Functions\when( '__' )->returnArg();
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$_REQUEST['action'] = 'install-plugin';

		$rule = $this->gate->match_request( 'ajax' );

		$this->assertNotNull( $rule );
		$this->assertSame( 'plugin.install', $rule['id'] );
	}

	/**
	 * Test match_request rejects non-gated AJAX action.
	 */
	public function test_match_request_rejects_non_gated_ajax(): void {
		Functions\when( '__' )->returnArg();
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$_REQUEST['action'] = 'heartbeat';

		$this->assertNull( $this->gate->match_request( 'ajax' ) );
	}

	// ── REST matching ─────────────────────────────────────────────────

	/**
	 * Test match_request matches REST plugin activation (PUT).
	 */
	public function test_match_request_matches_rest_plugin_activate(): void {
		Functions\when( '__' )->returnArg();
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$request = new \WP_REST_Request( 'PUT', '/wp/v2/plugins/hello-dolly' );

		$rule = $this->gate->match_request( 'rest', $request );

		$this->assertNotNull( $rule );
		$this->assertSame( 'plugin.activate', $rule['id'] );
	}

	/**
	 * Test match_request matches REST plugin deletion (DELETE).
	 */
	public function test_match_request_matches_rest_plugin_delete(): void {
		Functions\when( '__' )->returnArg();
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$request = new \WP_REST_Request( 'DELETE', '/wp/v2/plugins/hello-dolly' );

		$rule = $this->gate->match_request( 'rest', $request );

		$this->assertNotNull( $rule );
		$this->assertSame( 'plugin.delete', $rule['id'] );
	}

	/**
	 * Test match_request matches REST plugin install (POST to collection).
	 */
	public function test_match_request_matches_rest_plugin_install(): void {
		Functions\when( '__' )->returnArg();
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$request = new \WP_REST_Request( 'POST', '/wp/v2/plugins' );

		$rule = $this->gate->match_request( 'rest', $request );

		$this->assertNotNull( $rule );
		$this->assertSame( 'plugin.install', $rule['id'] );
	}

	/**
	 * Test match_request matches REST user delete.
	 */
	public function test_match_request_matches_rest_user_delete(): void {
		Functions\when( '__' )->returnArg();
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$request = new \WP_REST_Request( 'DELETE', '/wp/v2/users/42' );

		$rule = $this->gate->match_request( 'rest', $request );

		$this->assertNotNull( $rule );
		$this->assertSame( 'user.delete', $rule['id'] );
	}

	/**
	 * Test match_request matches REST settings update with critical option.
	 */
	public function test_match_request_matches_rest_critical_settings(): void {
		Functions\when( '__' )->returnArg();
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$request = new \WP_REST_Request( 'PUT', '/wp/v2/settings', array( 'siteurl' => 'https://new.example.com' ) );

		$rule = $this->gate->match_request( 'rest', $request );

		$this->assertNotNull( $rule );
		$this->assertSame( 'options.critical', $rule['id'] );
	}

	/**
	 * Test match_request rejects REST GET to plugins (read is not gated).
	 */
	public function test_match_request_rejects_rest_get_plugins(): void {
		Functions\when( '__' )->returnArg();
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$request = new \WP_REST_Request( 'GET', '/wp/v2/plugins/hello-dolly' );

		$this->assertNull( $this->gate->match_request( 'rest', $request ) );
	}

	/**
	 * Test match_request rejects non-matching REST route.
	 */
	public function test_match_request_rejects_non_matching_rest_route(): void {
		Functions\when( '__' )->returnArg();
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$request = new \WP_REST_Request( 'POST', '/wp/v2/posts' );

		$this->assertNull( $this->gate->match_request( 'rest', $request ) );
	}

	/**
	 * Test match_request matches REST user role change (PUT with roles param).
	 *
	 * The user.promote rule covers PUT/PATCH to /wp/v2/users/{id} when the
	 * request includes a "roles" parameter.
	 */
	public function test_match_request_matches_rest_user_role_change(): void {
		Functions\when( '__' )->returnArg();
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$request = new \WP_REST_Request( 'PUT', '/wp/v2/users/42', array( 'roles' => array( 'editor' ) ) );

		$rule = $this->gate->match_request( 'rest', $request );

		$this->assertNotNull( $rule );
		$this->assertSame( 'user.promote', $rule['id'] );
	}

	/**
	 * Test match_request matches REST user role change via PATCH.
	 */
	public function test_match_request_matches_rest_user_role_change_patch(): void {
		Functions\when( '__' )->returnArg();
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$request = new \WP_REST_Request( 'PATCH', '/wp/v2/users/7', array( 'roles' => array( 'administrator' ) ) );

		$rule = $this->gate->match_request( 'rest', $request );

		$this->assertNotNull( $rule );
		$this->assertSame( 'user.promote', $rule['id'] );
	}

	/**
	 * Test match_request skips REST user update without roles param.
	 *
	 * Updating a user's display name or email (no "roles" param) should
	 * NOT trigger the sudo gate.
	 */
	public function test_match_request_skips_rest_user_update_without_roles(): void {
		Functions\when( '__' )->returnArg();
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$request = new \WP_REST_Request( 'PUT', '/wp/v2/users/42', array( 'name' => 'New Name' ) );

		$rule = $this->gate->match_request( 'rest', $request );

		// Should NOT match user.promote (no roles param) — may match user.delete
		// or nothing. The key assertion is that it does NOT match user.promote.
		if ( null !== $rule ) {
			$this->assertNotSame( 'user.promote', $rule['id'] );
		} else {
			$this->assertNull( $rule );
		}
	}

	/**
	 * Test user.promote_profile has no REST surface defined.
	 *
	 * The individual profile page rule (user.promote_profile) is admin-only.
	 * REST role changes are covered by user.promote's REST criteria.
	 */
	public function test_user_promote_profile_has_no_rest_surface(): void {
		Functions\when( '__' )->returnArg();
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$rule = \WP_Sudo\Action_Registry::find( 'user.promote_profile' );

		$this->assertNotNull( $rule );
		$this->assertNull( $rule['rest'] );
		$this->assertNull( $rule['ajax'] );
	}

	// ── intercept_rest() — user role changes ─────────────────────────

	/**
	 * Test intercept_rest blocks user role change via app-password when policy is block.
	 */
	public function test_intercept_rest_blocks_user_role_change_app_password(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( '__' )->returnArg();
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'get_user_meta' )->justReturn( 0 );

		// No nonce = app-password auth. Policy = block (default).
		Functions\when( 'get_option' )->justReturn( array() );

		$request = new \WP_REST_Request( 'PUT', '/wp/v2/users/42', array( 'roles' => array( 'administrator' ) ) );
		// No X-WP-Nonce header — app-password/bearer auth.
		$handler = array();

		Actions\expectDone( 'wp_sudo_action_blocked' )
			->once()
			->with( 1, 'user.promote', 'rest_app_password' );

		$result = $this->gate->intercept_rest( null, $handler, $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'sudo_blocked', $result->get_error_code() );
	}

	/**
	 * Test intercept_rest allows user role change via app-password when policy is allow.
	 */
	public function test_intercept_rest_allows_user_role_change_app_password(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( '__' )->returnArg();
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'get_user_meta' )->justReturn( 0 );

		// No nonce = app-password auth. Policy = allow.
		Functions\when( 'get_option' )->justReturn( array( 'rest_app_password_policy' => 'allow' ) );

		$request = new \WP_REST_Request( 'PUT', '/wp/v2/users/42', array( 'roles' => array( 'editor' ) ) );
		$handler = array();

		Actions\expectDone( 'wp_sudo_action_allowed' )
			->once()
			->with( 1, 'user.promote', 'rest_app_password' );

		$result = $this->gate->intercept_rest( null, $handler, $request );

		$this->assertNull( $result );
	}

	/**
	 * Test intercept_rest returns sudo_required for cookie-auth user role change.
	 */
	public function test_intercept_rest_gates_user_role_change_cookie_auth(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( '__' )->returnArg();
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'get_user_meta' )->justReturn( 0 );
		Functions\when( 'set_transient' )->justReturn( true );

		// Cookie-auth: nonce present and valid.
		Functions\when( 'wp_verify_nonce' )->justReturn( true );

		$request = new \WP_REST_Request( 'PUT', '/wp/v2/users/42', array( 'roles' => array( 'editor' ) ) );
		$request->set_header( 'X-WP-Nonce', 'valid-nonce' );
		$handler = array();

		Actions\expectDone( 'wp_sudo_action_gated' )
			->once()
			->with( 1, 'user.promote', 'rest' );

		$result = $this->gate->intercept_rest( null, $handler, $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'sudo_required', $result->get_error_code() );
	}

	/**
	 * Test intercept_rest passes user update without roles (not gated).
	 */
	public function test_intercept_rest_passes_user_update_without_roles(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( '__' )->returnArg();
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'is_wp_error' )->justReturn( false );

		$request = new \WP_REST_Request( 'PUT', '/wp/v2/users/42', array( 'name' => 'New Name' ) );
		$handler = array();

		$result = $this->gate->intercept_rest( null, $handler, $request );

		$this->assertNull( $result );
	}

	// ── intercept_rest() ──────────────────────────────────────────────

	/**
	 * Test intercept_rest returns sudo_required for cookie-auth gated request.
	 */
	public function test_intercept_rest_blocks_gated_request(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( '__' )->returnArg();
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );

		// Sudo_Session::is_active() returns false.
		Functions\when( 'get_user_meta' )->justReturn( 0 );

		// Simulate cookie-auth: X-WP-Nonce header present and valid.
		Functions\when( 'wp_verify_nonce' )->justReturn( true );

		$request = new \WP_REST_Request( 'DELETE', '/wp/v2/plugins/hello-dolly' );
		$request->set_header( 'X-WP-Nonce', 'valid-nonce' );
		$handler = array();

		$result = $this->gate->intercept_rest( null, $handler, $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'sudo_required', $result->get_error_code() );
	}

	/**
	 * Test intercept_rest blocks app-password request when policy is block.
	 */
	public function test_intercept_rest_blocks_app_password_when_policy_blocks(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( '__' )->returnArg();
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'get_user_meta' )->justReturn( 0 );

		// No nonce = app-password auth. Policy = block (default).
		Functions\when( 'get_option' )->justReturn( array() );

		$request = new \WP_REST_Request( 'DELETE', '/wp/v2/plugins/hello-dolly' );
		// No X-WP-Nonce header — app-password/bearer auth.
		$handler = array();

		Actions\expectDone( 'wp_sudo_action_blocked' )
			->once()
			->with( 1, 'plugin.delete', 'rest_app_password' );

		$result = $this->gate->intercept_rest( null, $handler, $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'sudo_blocked', $result->get_error_code() );
	}

	/**
	 * Test intercept_rest allows app-password request when policy is allow.
	 */
	public function test_intercept_rest_allows_app_password_when_policy_allows(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( '__' )->returnArg();
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'get_user_meta' )->justReturn( 0 );

		// No nonce = app-password auth. Policy = allow.
		Functions\when( 'get_option' )->justReturn( array( 'rest_app_password_policy' => 'allow' ) );

		$request = new \WP_REST_Request( 'DELETE', '/wp/v2/plugins/hello-dolly' );
		// No X-WP-Nonce header — app-password/bearer auth.
		$handler = array();

		Actions\expectDone( 'wp_sudo_action_allowed' )
			->once()
			->with( 1, 'plugin.delete', 'rest_app_password' );

		$result = $this->gate->intercept_rest( null, $handler, $request );

		$this->assertNull( $result );
	}

	/**
	 * Test intercept_rest shows sudo_required for cookie-auth (not app-password policy).
	 */
	public function test_intercept_rest_shows_sudo_required_for_cookie_auth(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( '__' )->returnArg();
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'get_user_meta' )->justReturn( 0 );
		Functions\when( 'set_transient' )->justReturn( true );

		// Cookie-auth: nonce present and valid.
		Functions\when( 'wp_verify_nonce' )->justReturn( true );

		$request = new \WP_REST_Request( 'PUT', '/wp/v2/plugins/hello-dolly' );
		$request->set_header( 'X-WP-Nonce', 'valid-nonce' );
		$handler = array();

		Actions\expectDone( 'wp_sudo_action_gated' )
			->once()
			->with( 1, 'plugin.activate', 'rest' );

		$result = $this->gate->intercept_rest( null, $handler, $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'sudo_required', $result->get_error_code() );
	}

	/**
	 * Test intercept_rest passes through for non-gated routes.
	 */
	public function test_intercept_rest_passes_non_gated(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( '__' )->returnArg();
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'is_wp_error' )->justReturn( false );

		$request = new \WP_REST_Request( 'GET', '/wp/v2/posts' );
		$handler = array();

		$result = $this->gate->intercept_rest( null, $handler, $request );

		$this->assertNull( $result );
	}

	/**
	 * Test intercept_rest passes through for anonymous users.
	 */
	public function test_intercept_rest_passes_anonymous(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 0 );
		Functions\when( 'is_wp_error' )->justReturn( false );

		$request = new \WP_REST_Request( 'DELETE', '/wp/v2/plugins/hello-dolly' );
		$handler = array();

		$result = $this->gate->intercept_rest( null, $handler, $request );

		$this->assertNull( $result );
	}

	/**
	 * Test intercept_rest passes through when already an error.
	 */
	public function test_intercept_rest_passes_existing_error(): void {
		Functions\when( 'is_wp_error' )->justReturn( true );

		$existing_error = new \WP_Error( 'some_error', 'Something went wrong' );
		$request        = new \WP_REST_Request( 'DELETE', '/wp/v2/plugins/hello-dolly' );
		$handler        = array();

		$result = $this->gate->intercept_rest( $existing_error, $handler, $request );

		$this->assertSame( $existing_error, $result );
	}

	// ── register() ────────────────────────────────────────────────────

	/**
	 * Test register hooks admin_init and rest_request_before_callbacks.
	 */
	public function test_register_hooks(): void {
		Actions\expectAdded( 'admin_init' )
			->once()
			->with( array( $this->gate, 'intercept' ), 1 );

		$this->gate->register();

		// The rest filter is also added (verified by no error from register()).
		$this->assertTrue( true );
	}

	// ── intercept() — admin UI path ───────────────────────────────────

	/**
	 * Test intercept does nothing for anonymous users.
	 */
	public function test_intercept_skips_anonymous_users(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 0 );
		Functions\when( 'wp_doing_ajax' )->justReturn( false );
		Functions\when( 'wp_doing_cron' )->justReturn( false );
		Functions\when( 'is_admin' )->justReturn( true );

		// If intercept tried to match or stash, these would fail.
		// No mocks set for stash/session = implicit "never called".
		$this->gate->intercept();

		// If we get here without error, the test passes.
		$this->assertTrue( true );
	}

	/**
	 * Test intercept skips non-matching requests.
	 */
	public function test_intercept_passes_through_non_matching(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'wp_doing_ajax' )->justReturn( false );
		Functions\when( 'wp_doing_cron' )->justReturn( false );
		Functions\when( 'is_admin' )->justReturn( true );
		Functions\when( '__' )->returnArg();
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$GLOBALS['pagenow']        = 'edit.php';
		$_REQUEST['action']        = 'trash';
		$_SERVER['REQUEST_METHOD'] = 'POST';

		// Should not call stash or redirect.
		$this->gate->intercept();
		$this->assertTrue( true );
	}

	/**
	 * Test intercept lets active session through without challenging.
	 */
	public function test_intercept_allows_active_session(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		Functions\when( 'wp_doing_ajax' )->justReturn( false );
		Functions\when( 'wp_doing_cron' )->justReturn( false );
		Functions\when( 'is_admin' )->justReturn( true );
		Functions\when( '__' )->returnArg();
		Functions\when( 'apply_filters' )->returnArg( 2 );

		// Simulate: Sudo_Session::is_active( 5 ) returns true.
		// v2: is_active() checks expiry + token only. No role check.
		$token = 'test-gate-token';

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

		$GLOBALS['pagenow']        = 'plugins.php';
		$_REQUEST['action']        = 'activate';
		$_SERVER['REQUEST_METHOD'] = 'GET';

		// intercept() should return without calling stash->save().
		$this->stash->shouldNotReceive( 'save' );

		$this->gate->intercept();

		unset( $_COOKIE[ Sudo_Session::TOKEN_COOKIE ] );
		$this->assertTrue( true );
	}

	/**
	 * Test intercept on unknown surface does nothing.
	 */
	public function test_intercept_skips_unknown_surface(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'wp_doing_ajax' )->justReturn( false );
		Functions\when( 'wp_doing_cron' )->justReturn( false );
		Functions\when( 'is_admin' )->justReturn( false );

		$this->gate->intercept();
		$this->assertTrue( true );
	}

	/**
	 * Test intercept triggers stash and redirect for gated admin request.
	 *
	 * Verifies that when a gated admin request arrives without an active
	 * sudo session, the Gate stashes the request and redirects to the
	 * challenge page. Uses an exception to prevent `exit` from killing
	 * the test process.
	 */
	public function test_intercept_stashes_and_redirects_for_admin(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		Functions\when( 'wp_doing_ajax' )->justReturn( false );
		Functions\when( 'wp_doing_cron' )->justReturn( false );
		Functions\when( 'is_admin' )->justReturn( true );
		Functions\when( '__' )->returnArg();
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'admin_url' )->justReturn( 'https://example.com/wp-admin/admin.php' );
		Functions\when( 'add_query_arg' )->justReturn( 'https://example.com/wp-admin/admin.php?page=wp-sudo-challenge&stash_key=abc123' );

		// No active session.
		Functions\when( 'get_user_meta' )->justReturn( 0 );

		$GLOBALS['pagenow']        = 'plugins.php';
		$_REQUEST['action']        = 'activate';
		$_SERVER['REQUEST_METHOD'] = 'GET';

		// Expect stash->save() to be called with user ID and matched rule.
		$this->stash->shouldReceive( 'save' )
			->once()
			->with( 5, \Mockery::type( 'array' ) )
			->andReturn( 'abc123' );

		// wp_safe_redirect throws to prevent exit from killing the test.
		Functions\expect( 'wp_safe_redirect' )
			->once()
			->andReturnUsing( function () {
				throw new \RuntimeException( 'redirect' );
			} );

		Actions\expectDone( 'wp_sudo_action_gated' )
			->once()
			->with( 5, 'plugin.activate', 'admin' );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'redirect' );

		$this->gate->intercept();
	}

	/**
	 * Test intercept sends JSON error for gated AJAX request.
	 *
	 * Verifies that when a gated AJAX request arrives without an active
	 * sudo session, the Gate returns a sudo_required JSON error and
	 * sets a blocked-action transient for the admin notice.
	 */
	public function test_intercept_blocks_ajax_with_json_error(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 3 );
		Functions\when( 'wp_doing_ajax' )->justReturn( true );
		Functions\when( 'wp_doing_cron' )->justReturn( false );
		Functions\when( 'is_admin' )->justReturn( true );
		Functions\when( '__' )->returnArg();
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();

		// No active session.
		Functions\when( 'get_user_meta' )->justReturn( 0 );

		$_REQUEST['action'] = 'delete-plugin';

		// No HTTP status param — wp_send_json_error() defaults to 200 so
		// wp.ajax.send() parses the response through .done() instead of .fail().
		Functions\expect( 'wp_send_json_error' )
			->once()
			->with(
				\Mockery::on( function ( $data ) {
					return is_array( $data )
						&& 'sudo_required' === $data['code']
						&& 'plugin.delete' === $data['rule_id']
						&& 'sudo_required' === $data['errorCode']
						// Includes keyboard shortcut hint and no HTML.
						&& ( false !== strpos( $data['errorMessage'], 'Ctrl+Shift+S' )
							|| false !== strpos( $data['errorMessage'], 'Cmd+Shift+S' ) )
						&& false === strpos( $data['errorMessage'], '<a ' );
				} )
			);

		Actions\expectDone( 'wp_sudo_action_gated' )
			->once()
			->with( 3, 'plugin.delete', 'ajax' );

		$this->gate->intercept();
	}

	/**
	 * Test block_ajax includes slug and plugin from $_POST.
	 *
	 * WordPress core's wp.updates error handlers (installThemeError,
	 * updatePluginError, etc.) use response.slug to locate the DOM
	 * element and reset the button/spinner state. Without slug, the
	 * spinner spins forever.
	 */
	public function test_intercept_ajax_includes_slug_and_plugin(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 3 );
		Functions\when( 'wp_doing_ajax' )->justReturn( true );
		Functions\when( 'wp_doing_cron' )->justReturn( false );
		Functions\when( 'is_admin' )->justReturn( true );
		Functions\when( '__' )->returnArg();
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'sanitize_key' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();

		// No active session.
		Functions\when( 'get_user_meta' )->justReturn( 0 );

		$_REQUEST['action'] = 'delete-plugin';
		$_POST['slug']      = 'my-plugin';
		$_POST['plugin']    = 'my-plugin/my-plugin.php';

		Functions\expect( 'wp_send_json_error' )
			->once()
			->with(
				\Mockery::on( function ( $data ) {
					return is_array( $data )
						&& 'sudo_required' === $data['code']
						&& 'my-plugin' === $data['slug']
						&& 'my-plugin/my-plugin.php' === $data['plugin']
						&& ! empty( $data['errorMessage'] );
				} )
			);

		Actions\expectDone( 'wp_sudo_action_gated' )
			->once()
			->with( 3, 'plugin.delete', 'ajax' );

		$this->gate->intercept();
	}

	/**
	 * Test intercept_rest passes through when sudo session is active.
	 *
	 * A gated REST request from a user with an active sudo session should
	 * be allowed through without any error.
	 */
	public function test_intercept_rest_passes_with_active_session(): void {
		$user_id = 7;
		$token   = 'rest-session-token';

		Functions\when( 'get_current_user_id' )->justReturn( $user_id );
		Functions\when( '__' )->returnArg();
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'is_wp_error' )->justReturn( false );

		// Sudo session IS active.
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

		$request = new \WP_REST_Request( 'DELETE', '/wp/v2/plugins/hello-dolly' );
		$request->set_header( 'X-WP-Nonce', 'valid-nonce' );
		$handler = array();

		$result = $this->gate->intercept_rest( null, $handler, $request );

		$this->assertNull( $result );

		unset( $_COOKIE[ Sudo_Session::TOKEN_COOKIE ] );
	}

	// ── Policy constants ─────────────────────────────────────────────

	/**
	 * Test policy constants are defined.
	 */
	public function test_policy_constants(): void {
		$this->assertSame( 'block', Gate::POLICY_BLOCK );
		$this->assertSame( 'allow', Gate::POLICY_ALLOW );
	}

	/**
	 * Test setting key constants are defined.
	 */
	public function test_setting_key_constants(): void {
		$this->assertSame( 'cli_policy', Gate::SETTING_CLI_POLICY );
		$this->assertSame( 'cron_policy', Gate::SETTING_CRON_POLICY );
		$this->assertSame( 'xmlrpc_policy', Gate::SETTING_XMLRPC_POLICY );
		$this->assertSame( 'rest_app_password_policy', Gate::SETTING_REST_APP_PASS_POLICY );
	}

	// ── register_early() ─────────────────────────────────────────────

	/**
	 * Test register_early hooks init at priority 0.
	 */
	public function test_register_early_hooks_init(): void {
		Actions\expectAdded( 'init' )
			->once()
			->with( array( $this->gate, 'gate_non_interactive' ), 0 );

		$this->gate->register_early();
	}

	// ── get_policy() ─────────────────────────────────────────────────

	/**
	 * Test get_policy returns 'block' by default.
	 */
	public function test_get_policy_defaults_to_block(): void {
		Functions\when( 'get_option' )->justReturn( array() );

		$this->assertSame( 'block', $this->gate->get_policy( Gate::SETTING_CLI_POLICY ) );
	}

	/**
	 * Test get_policy returns 'allow' when configured.
	 */
	public function test_get_policy_returns_allow_when_set(): void {
		Functions\when( 'get_option' )->justReturn( array( 'cli_policy' => 'allow' ) );

		$this->assertSame( 'allow', $this->gate->get_policy( Gate::SETTING_CLI_POLICY ) );
	}

	/**
	 * Test get_policy normalizes invalid values to 'block'.
	 */
	public function test_get_policy_normalizes_invalid_to_block(): void {
		Functions\when( 'get_option' )->justReturn( array( 'cli_policy' => 'invalid_value' ) );

		$this->assertSame( 'block', $this->gate->get_policy( Gate::SETTING_CLI_POLICY ) );
	}

	// ── gate_non_interactive() dispatch ──────────────────────────────

	/**
	 * Test gate_non_interactive dispatches to gate_cron for Cron surface.
	 *
	 * Note: We cannot test CLI dispatch because define('WP_CLI', true) leaks
	 * to other tests in the same process. gate_cli() is tested directly below.
	 */
	public function test_gate_non_interactive_dispatches_cron(): void {
		Functions\when( 'wp_doing_ajax' )->justReturn( false );
		Functions\when( 'wp_doing_cron' )->justReturn( true );
		Functions\when( 'is_admin' )->justReturn( false );

		// Policy = block → gate_cron adds admin_init hook.
		Functions\when( 'get_option' )->justReturn( array() );

		Actions\expectAdded( 'admin_init' )->once();

		$this->gate->gate_non_interactive();
	}

	/**
	 * Test gate_non_interactive does nothing for admin surface.
	 *
	 * Admin/AJAX/REST are handled by register(), not register_early().
	 * gate_non_interactive should only fire for CLI, Cron, XML-RPC.
	 */
	public function test_gate_non_interactive_skips_admin(): void {
		Functions\when( 'wp_doing_ajax' )->justReturn( false );
		Functions\when( 'wp_doing_cron' )->justReturn( false );
		Functions\when( 'is_admin' )->justReturn( true );

		// Should not add any hooks.
		Actions\expectAdded( 'admin_init' )->never();

		$this->gate->gate_non_interactive();
	}

	// ── gate_cli() ───────────────────────────────────────────────────

	/**
	 * Test gate_cli with block policy blocks all WP-CLI operations immediately.
	 */
	public function test_gate_cli_block_dies_immediately(): void {
		Functions\when( 'get_option' )->justReturn( array() );

		// Mock translation function.
		Functions\when( 'esc_html__' )->returnArg();

		// Should fire the wp_sudo_action_blocked hook before dying.
		Actions\expectDone( 'wp_sudo_action_blocked' )
			->once()
			->with( 0, '', 'cli' );

		// Mock wp_die to prevent actual exit.
		Functions\expect( 'wp_die' )
			->once()
			->with(
				\Mockery::type( 'string' ),
				'',
				array( 'response' => 403 )
			);

		$this->gate->gate_cli();
	}

	/**
	 * Test gate_cli with allow policy and --sudo flag fires allowed hook.
	 */
	public function test_gate_cli_allow_with_sudo_flag(): void {
		Functions\when( 'get_option' )->justReturn( array( 'cli_policy' => 'allow' ) );

		$_SERVER['argv'] = array( 'wp', 'plugin', 'activate', 'hello', '--sudo' );

		// Should fire the wp_sudo_action_allowed hook.
		Actions\expectDone( 'wp_sudo_action_allowed' )
			->once()
			->with( 0, '', 'cli' );

		$this->gate->gate_cli();

		unset( $_SERVER['argv'] );
	}

	/**
	 * Test gate_cli with allow policy but NO --sudo flag registers block hook.
	 */
	public function test_gate_cli_allow_without_sudo_flag_blocks(): void {
		Functions\when( 'get_option' )->justReturn( array( 'cli_policy' => 'allow' ) );

		$_SERVER['argv'] = array( 'wp', 'plugin', 'activate', 'hello' );

		// Without --sudo, it should register the blocking hook.
		Actions\expectAdded( 'admin_init' )
			->once()
			->with( \Mockery::type( 'Closure' ), 0 );

		$this->gate->gate_cli();

		unset( $_SERVER['argv'] );
	}

	// ── gate_cron() ──────────────────────────────────────────────────

	/**
	 * Test gate_cron with block policy registers admin_init hook.
	 */
	public function test_gate_cron_block_registers_hook(): void {
		Functions\when( 'get_option' )->justReturn( array() );

		Actions\expectAdded( 'admin_init' )
			->once()
			->with( \Mockery::type( 'Closure' ), 0 );

		$this->gate->gate_cron();
	}

	/**
	 * Test gate_cron with allow policy fires allowed hook.
	 */
	public function test_gate_cron_allow_fires_hook(): void {
		Functions\when( 'get_option' )->justReturn( array( 'cron_policy' => 'allow' ) );

		Actions\expectDone( 'wp_sudo_action_allowed' )
			->once()
			->with( 0, '', 'cron' );

		$this->gate->gate_cron();
	}

	// ── gate_xmlrpc() ────────────────────────────────────────────────

	/**
	 * Test gate_xmlrpc with block policy registers admin_init hook.
	 */
	public function test_gate_xmlrpc_block_registers_hook(): void {
		Functions\when( 'get_option' )->justReturn( array() );

		Actions\expectAdded( 'admin_init' )
			->once()
			->with( \Mockery::type( 'Closure' ), 0 );

		$this->gate->gate_xmlrpc();
	}

	/**
	 * Test gate_xmlrpc with allow policy fires allowed hook.
	 */
	public function test_gate_xmlrpc_allow_fires_hook(): void {
		Functions\when( 'get_option' )->justReturn( array( 'xmlrpc_policy' => 'allow' ) );

		Actions\expectDone( 'wp_sudo_action_allowed' )
			->once()
			->with( 0, '', 'xmlrpc' );

		$this->gate->gate_xmlrpc();
	}

	// ── Multisite: match_request for network rules ───────────────────

	/**
	 * Test match_request matches network theme enable in network admin.
	 */
	public function test_match_request_matches_network_theme_enable(): void {
		Functions\when( '__' )->returnArg();
		Functions\when( 'apply_filters' )->alias( fn( $hook, $value ) => $value );
		Functions\when( 'is_multisite' )->justReturn( true );
		Functions\when( 'is_network_admin' )->justReturn( true );

		$GLOBALS['pagenow']        = 'themes.php';
		$_REQUEST['action']        = 'enable';
		$_SERVER['REQUEST_METHOD'] = 'GET';

		$rule = $this->gate->match_request( 'admin' );

		$this->assertNotNull( $rule );
		$this->assertSame( 'network.theme_enable', $rule['id'] );
	}

	/**
	 * Test match_request matches network site delete.
	 */
	public function test_match_request_matches_network_site_delete(): void {
		Functions\when( '__' )->returnArg();
		Functions\when( 'apply_filters' )->alias( fn( $hook, $value ) => $value );
		Functions\when( 'is_multisite' )->justReturn( true );

		$GLOBALS['pagenow']        = 'sites.php';
		$_REQUEST['action']        = 'deleteblog';
		$_SERVER['REQUEST_METHOD'] = 'GET';

		$rule = $this->gate->match_request( 'admin' );

		$this->assertNotNull( $rule );
		$this->assertSame( 'network.site_delete', $rule['id'] );
	}

	/**
	 * Test match_request matches network super admin grant.
	 */
	public function test_match_request_matches_network_super_admin_grant(): void {
		Functions\when( '__' )->returnArg();
		Functions\when( 'apply_filters' )->alias( fn( $hook, $value ) => $value );
		Functions\when( 'is_multisite' )->justReturn( true );
		Functions\when( 'is_network_admin' )->justReturn( true );

		$GLOBALS['pagenow']        = 'user-edit.php';
		$_REQUEST['action']        = 'update';
		$_POST['super_admin']      = '1';
		$_SERVER['REQUEST_METHOD'] = 'POST';

		$rule = $this->gate->match_request( 'admin' );

		$this->assertNotNull( $rule );
		$this->assertSame( 'network.super_admin', $rule['id'] );
	}

	/**
	 * Test network rules are not registered on single-site.
	 */
	public function test_match_request_skips_network_rules_on_single_site(): void {
		Functions\when( '__' )->returnArg();
		Functions\when( 'apply_filters' )->alias( fn( $hook, $value ) => $value );

		$GLOBALS['pagenow']        = 'sites.php';
		$_REQUEST['action']        = 'deleteblog';
		$_SERVER['REQUEST_METHOD'] = 'GET';

		$rule = $this->gate->match_request( 'admin' );

		$this->assertNull( $rule );
	}

	/**
	 * Test match_request matches network settings change.
	 */
	public function test_match_request_matches_network_settings(): void {
		Functions\when( '__' )->returnArg();
		Functions\when( 'apply_filters' )->alias( fn( $hook, $value ) => $value );
		Functions\when( 'is_multisite' )->justReturn( true );
		Functions\when( 'is_network_admin' )->justReturn( true );

		$GLOBALS['pagenow']        = 'settings.php';
		$_REQUEST['action']        = 'update';
		$_SERVER['REQUEST_METHOD'] = 'POST';

		$rule = $this->gate->match_request( 'admin' );

		$this->assertNotNull( $rule );
		$this->assertSame( 'network.settings', $rule['id'] );
	}

	// ── render_blocked_notice() ──────────────────────────────────────

	/**
	 * Test blocked notice skips anonymous users.
	 */
	public function test_blocked_notice_skips_anonymous(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 0 );

		// Should not call get_transient.
		Functions\expect( 'get_transient' )->never();

		$this->gate->render_blocked_notice();
		$this->assertTrue( true );
	}

	/**
	 * Test blocked notice skips when sudo is active.
	 */
	public function test_blocked_notice_skips_active_session(): void {
		$user_id = 5;
		$token   = 'notice-test-token';

		Functions\when( 'get_current_user_id' )->justReturn( $user_id );
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

		$this->gate->render_blocked_notice();
		$this->assertTrue( true );

		unset( $_COOKIE[ Sudo_Session::TOKEN_COOKIE ] );
	}

	/**
	 * Test blocked notice skips without transient.
	 */
	public function test_blocked_notice_skips_without_transient(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'get_user_meta' )->justReturn( 0 );
		Functions\when( 'get_transient' )->justReturn( false );

		// Should not call delete_transient.
		Functions\expect( 'delete_transient' )->never();

		$this->gate->render_blocked_notice();
		$this->assertTrue( true );
	}

	/**
	 * Test blocked notice consumes transient.
	 */
	public function test_blocked_notice_consumes_transient(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'get_user_meta' )->justReturn( 0 );
		Functions\when( 'get_transient' )->justReturn( array( 'label' => 'Install theme' ) );
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'admin_url' )->justReturn( 'https://example.com/wp-admin/admin.php' );
		Functions\when( 'add_query_arg' )->justReturn( 'https://example.com/wp-admin/admin.php?page=wp-sudo-challenge' );

		Functions\expect( 'delete_transient' )
			->once()
			->with( Gate::BLOCKED_TRANSIENT_PREFIX . '1' );

		$this->expectOutputRegex( '/Install theme/' );

		$this->gate->render_blocked_notice();
	}

	/**
	 * Test blocked notice renders with challenge link.
	 */
	public function test_blocked_notice_renders_with_challenge_link(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'get_user_meta' )->justReturn( 0 );
		Functions\when( 'get_transient' )->justReturn( array( 'label' => 'Delete plugin' ) );
		Functions\when( 'delete_transient' )->justReturn( true );
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'admin_url' )->justReturn( 'https://example.com/wp-admin/admin.php' );
		Functions\when( 'add_query_arg' )->justReturn( 'https://example.com/wp-admin/admin.php?page=wp-sudo-challenge' );

		$this->expectOutputRegex( '/wp-sudo-challenge/' );

		$this->gate->render_blocked_notice();
	}

	// ── render_gate_notice() ─────────────────────────────────────────

	/**
	 * Test gate notice skips anonymous users.
	 */
	public function test_gate_notice_skips_anonymous(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 0 );

		$this->gate->render_gate_notice();
		$this->expectOutputString( '' );
	}

	/**
	 * Test gate notice skips when sudo is active.
	 */
	public function test_gate_notice_skips_active_session(): void {
		$user_id = 5;
		$token   = 'gate-notice-token';

		Functions\when( 'get_current_user_id' )->justReturn( $user_id );
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

		$this->gate->render_gate_notice();
		$this->expectOutputString( '' );

		unset( $_COOKIE[ Sudo_Session::TOKEN_COOKIE ] );
	}

	/**
	 * Test gate notice skips non-gated pages.
	 */
	public function test_gate_notice_skips_non_gated_page(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'get_user_meta' )->justReturn( 0 );

		$GLOBALS['pagenow'] = 'edit.php';

		$this->gate->render_gate_notice();
		$this->expectOutputString( '' );
	}

	/**
	 * Test gate notice renders on plugins.php.
	 */
	public function test_gate_notice_renders_on_plugins_page(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'get_user_meta' )->justReturn( 0 );
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'admin_url' )->justReturn( 'https://example.com/wp-admin/admin.php' );
		Functions\when( 'add_query_arg' )->justReturn( 'https://example.com/wp-admin/admin.php?page=wp-sudo-challenge' );

		$GLOBALS['pagenow'] = 'plugins.php';

		$this->expectOutputRegex( '/wp-sudo-challenge/' );

		$this->gate->render_gate_notice();
	}

	/**
	 * Test gate notice renders on theme-install.php.
	 */
	public function test_gate_notice_renders_on_theme_install_page(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'get_user_meta' )->justReturn( 0 );
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'admin_url' )->justReturn( 'https://example.com/wp-admin/admin.php' );
		Functions\when( 'add_query_arg' )->justReturn( 'https://example.com/wp-admin/admin.php?page=wp-sudo-challenge' );

		$GLOBALS['pagenow'] = 'theme-install.php';

		$this->expectOutputRegex( '/Confirm your identity/' );

		$this->gate->render_gate_notice();
	}

	/**
	 * Test gate notice is not dismissible.
	 */
	public function test_gate_notice_is_not_dismissible(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'get_user_meta' )->justReturn( 0 );
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'admin_url' )->justReturn( 'https://example.com/wp-admin/admin.php' );
		Functions\when( 'add_query_arg' )->justReturn( 'https://example.com/wp-admin/admin.php?page=wp-sudo-challenge' );

		$GLOBALS['pagenow'] = 'themes.php';

		ob_start();
		$this->gate->render_gate_notice();
		$output = ob_get_clean();

		// Non-dismissible: no "is-dismissible" class.
		$this->assertStringNotContainsString( 'is-dismissible', $output );
		$this->assertStringContainsString( 'notice notice-warning', $output );
	}

	// ── filter_plugin_action_links() ─────────────────────────────────

	/**
	 * Test plugin action links are disabled when no sudo session.
	 */
	public function test_filter_plugin_action_links_disables_gated(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'get_user_meta' )->justReturn( 0 );
		Functions\when( 'wp_strip_all_tags' )->alias( 'strip_tags' );
		Functions\when( 'esc_html' )->returnArg();

		$actions = array(
			'activate'   => '<a href="#">Activate</a>',
			'deactivate' => '<a href="#">Deactivate</a>',
			'delete'     => '<a href="#">Delete</a>',
			'details'    => '<a href="#">View details</a>',
		);

		$result = $this->gate->filter_plugin_action_links( $actions, 'test/test.php' );

		// Gated actions should be replaced with disabled spans.
		$this->assertStringContainsString( 'wp-sudo-disabled', $result['activate'] );
		$this->assertStringContainsString( 'wp-sudo-disabled', $result['deactivate'] );
		$this->assertStringContainsString( 'wp-sudo-disabled', $result['delete'] );
		$this->assertStringContainsString( 'aria-disabled="true"', $result['activate'] );

		// Non-gated "details" should be untouched.
		$this->assertStringContainsString( '<a href', $result['details'] );
	}

	/**
	 * Test plugin action links pass through with active session.
	 */
	public function test_filter_plugin_action_links_passes_with_session(): void {
		$user_id = 5;
		$token   = 'filter-test-token';

		Functions\when( 'get_current_user_id' )->justReturn( $user_id );
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

		$actions = array(
			'activate' => '<a href="#">Activate</a>',
			'delete'   => '<a href="#">Delete</a>',
		);

		$result = $this->gate->filter_plugin_action_links( $actions, 'test/test.php' );

		// Actions should be unchanged.
		$this->assertSame( $actions, $result );

		unset( $_COOKIE[ Sudo_Session::TOKEN_COOKIE ] );
	}

	/**
	 * Test plugin action links pass through for anonymous users.
	 */
	public function test_filter_plugin_action_links_passes_anonymous(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 0 );

		$actions = array(
			'activate' => '<a href="#">Activate</a>',
		);

		$result = $this->gate->filter_plugin_action_links( $actions, 'test/test.php' );

		$this->assertSame( $actions, $result );
	}

	// ── filter_theme_action_links() ──────────────────────────────────

	/**
	 * Test theme action links are disabled when no sudo session.
	 */
	public function test_filter_theme_action_links_disables_gated(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'get_user_meta' )->justReturn( 0 );
		Functions\when( 'wp_strip_all_tags' )->alias( 'strip_tags' );
		Functions\when( 'esc_html' )->returnArg();

		$theme = new \stdClass();

		$actions = array(
			'activate' => '<a href="#">Activate</a>',
			'delete'   => '<a href="#">Delete</a>',
			'preview'  => '<a href="#">Live Preview</a>',
		);

		$result = $this->gate->filter_theme_action_links( $actions, $theme );

		$this->assertStringContainsString( 'wp-sudo-disabled', $result['activate'] );
		$this->assertStringContainsString( 'wp-sudo-disabled', $result['delete'] );

		// Preview should be untouched.
		$this->assertStringContainsString( '<a href', $result['preview'] );
	}

	/**
	 * Test theme action links pass through with active session.
	 */
	public function test_filter_theme_action_links_passes_with_session(): void {
		$user_id = 5;
		$token   = 'theme-filter-token';

		Functions\when( 'get_current_user_id' )->justReturn( $user_id );
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

		$theme = new \stdClass();

		$actions = array(
			'activate' => '<a href="#">Activate</a>',
			'delete'   => '<a href="#">Delete</a>',
		);

		$result = $this->gate->filter_theme_action_links( $actions, $theme );

		$this->assertSame( $actions, $result );

		unset( $_COOKIE[ Sudo_Session::TOKEN_COOKIE ] );
	}
}
