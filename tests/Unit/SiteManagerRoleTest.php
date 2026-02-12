<?php
/**
 * Tests for WP_Sudo\Site_Manager_Role.
 *
 * @package WP_Sudo\Tests\Unit
 */

namespace WP_Sudo\Tests\Unit;

use WP_Sudo\Site_Manager_Role;
use WP_Sudo\Tests\TestCase;
use Brain\Monkey\Functions;

class SiteManagerRoleTest extends TestCase {

	private array $caps;

	protected function setUp(): void {
		parent::setUp();

		$editor_role               = new \stdClass();
		$editor_role->capabilities = [
			'moderate_comments'      => true,
			'manage_categories'      => true,
			'upload_files'           => true,
			'unfiltered_html'        => true,
			'edit_posts'             => true,
			'edit_others_posts'      => true,
			'edit_published_posts'   => true,
			'publish_posts'          => true,
			'edit_pages'             => true,
			'read'                   => true,
			'edit_others_pages'      => true,
			'edit_published_pages'   => true,
			'publish_pages'          => true,
			'delete_pages'           => true,
			'delete_others_pages'    => true,
			'delete_published_pages' => true,
			'delete_posts'           => true,
			'delete_others_posts'    => true,
			'delete_published_posts' => true,
		];

		Functions\when( 'get_role' )->alias( function ( $slug ) use ( $editor_role ) {
			return 'editor' === $slug ? $editor_role : null;
		} );

		$this->caps = Site_Manager_Role::capabilities();
	}

	public function test_inherits_editor_capabilities(): void {
		$this->assertTrue( $this->caps['edit_posts'] );
		$this->assertTrue( $this->caps['edit_others_posts'] );
		$this->assertTrue( $this->caps['publish_posts'] );
		$this->assertTrue( $this->caps['manage_categories'] );
		$this->assertTrue( $this->caps['read'] );
	}

	public function test_sets_unfiltered_html_to_false(): void {
		$this->assertFalse( $this->caps['unfiltered_html'] );
	}

	public function test_adds_theme_management_caps(): void {
		$this->assertTrue( $this->caps['switch_themes'] );
		$this->assertTrue( $this->caps['edit_theme_options'] );
	}

	public function test_adds_plugin_activation_cap(): void {
		$this->assertTrue( $this->caps['activate_plugins'] );
	}

	public function test_adds_user_listing_cap(): void {
		$this->assertTrue( $this->caps['list_users'] );
	}

	public function test_adds_update_caps(): void {
		$this->assertTrue( $this->caps['update_core'] );
		$this->assertTrue( $this->caps['update_plugins'] );
		$this->assertTrue( $this->caps['update_themes'] );
	}

	public function test_does_not_include_dangerous_caps(): void {
		$this->assertArrayNotHasKey( 'edit_users', $this->caps );
		$this->assertArrayNotHasKey( 'promote_users', $this->caps );
		$this->assertArrayNotHasKey( 'manage_options', $this->caps );
		$this->assertArrayNotHasKey( 'install_plugins', $this->caps );
		$this->assertArrayNotHasKey( 'install_themes', $this->caps );
		$this->assertArrayNotHasKey( 'edit_plugins', $this->caps );
		$this->assertArrayNotHasKey( 'edit_themes', $this->caps );
		$this->assertArrayNotHasKey( 'delete_users', $this->caps );
		$this->assertArrayNotHasKey( 'create_users', $this->caps );
	}

	public function test_constants_are_correct(): void {
		$this->assertSame( 'site_manager', Site_Manager_Role::ROLE_SLUG );
		$this->assertSame( 'Site Manager', Site_Manager_Role::ROLE_NAME );
	}
}
