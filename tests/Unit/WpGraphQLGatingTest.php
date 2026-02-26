<?php
/**
 * Unit tests for WPGraphQL surface gating.
 *
 * Tests the Gate class's detection of WPGraphQL requests and policy
 * enforcement (Disabled / Limited / Unrestricted) without a database.
 *
 * @package WP_Sudo\Tests\Unit
 */

namespace WP_Sudo\Tests\Unit;

use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use WP_Sudo\Gate;
use WP_Sudo\Request_Stash;
use WP_Sudo\Sudo_Session;
use WP_Sudo\Tests\TestCase;

/**
 * Class WpGraphQLGatingTest
 */
class WpGraphQLGatingTest extends TestCase {

	private Gate $gate;

	protected function setUp(): void {
		parent::setUp();

		$session     = \Mockery::mock( Sudo_Session::class );
		$stash       = \Mockery::mock( Request_Stash::class );
		$this->gate  = new Gate( $session, $stash );

		// Standard stubs shared across all tests.
		Functions\when( '__' )->returnArg();
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'get_user_meta' )->justReturn( false ); // no active session by default
	}

	// ── Helpers ────────────────────────────────────────────────────────

	/**
	 * Build a POST /graphql request with the given body.
	 */
	private function graphql_request( string $body ): \WP_REST_Request {
		$request = new \WP_REST_Request( 'POST', '/graphql' );
		$request->set_body( $body );
		return $request;
	}

	/**
	 * Stub get_option() to return a wp_sudo_settings array with the given policy.
	 */
	private function with_policy( string $policy ): void {
		Functions\when( 'get_option' )->justReturn(
			array( Gate::SETTING_WPGRAPHQL_POLICY => $policy )
		);
	}

	/**
	 * Stub apply_filters() to return the default (second argument), except for
	 * wp_sudo_wpgraphql_route which returns $custom_route.
	 */
	private function with_custom_route( string $custom_route ): void {
		Functions\when( 'apply_filters' )->alias(
			static function ( string $tag, $default ) use ( $custom_route ) {
				return 'wp_sudo_wpgraphql_route' === $tag ? $custom_route : $default;
			}
		);
	}

	// ── Route detection ────────────────────────────────────────────────

	/** @test */
	public function test_graphql_route_is_detected(): void {
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'get_option' )->justReturn( array() ); // limited default

		$request = $this->graphql_request( '{"query":"mutation { deleteUser(input:{id:\"1\"}) { deletedId } }"}' );
		$result  = $this->gate->intercept_rest( null, array(), $request );

		// Should return a WP_Error (sudo_blocked), not pass through.
		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	/** @test */
	public function test_non_graphql_rest_route_is_not_intercepted_as_wpgraphql(): void {
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'get_option' )->justReturn( array() );

		// A normal WP REST GET route that has no gated rule — should pass through
		// without being caught by the WPGraphQL handler.
		$request = new \WP_REST_Request( 'GET', '/wp/v2/posts' );
		$result  = $this->gate->intercept_rest( null, array(), $request );

		// No rule match → passes through.
		$this->assertNull( $result );
	}

	/** @test */
	public function test_wpgraphql_route_filter_overrides_default(): void {
		$this->with_custom_route( '/custom-graphql' );
		Functions\when( 'get_option' )->justReturn( array() ); // limited default

		// Default /graphql is no longer the GraphQL route.
		$default_route_request = $this->graphql_request( '{"query":"mutation { deleteUser(input:{id:\"1\"}) { deletedId } }"}' );
		$result = $this->gate->intercept_rest( null, array(), $default_route_request );
		$this->assertNull( $result ); // not intercepted as GraphQL

		// Custom route IS the GraphQL route.
		$custom_route_request = new \WP_REST_Request( 'POST', '/custom-graphql' );
		$custom_route_request->set_body( '{"query":"mutation { deleteUser(input:{id:\"1\"}) { deletedId } }"}' );
		$result = $this->gate->intercept_rest( null, array(), $custom_route_request );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'sudo_blocked', $result->get_error_code() );
	}

	// ── Mutation detection ─────────────────────────────────────────────

	/** @test */
	public function test_body_containing_mutation_keyword_is_treated_as_mutation(): void {
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'get_option' )->justReturn( array() ); // limited default

		$request = $this->graphql_request( '{"query":"mutation { deleteUser(input:{id:\"1\"}) { deletedId } }"}' );
		$result  = $this->gate->intercept_rest( null, array(), $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'sudo_blocked', $result->get_error_code() );
	}

	/** @test */
	public function test_body_without_mutation_keyword_passes_through_on_limited_policy(): void {
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'get_option' )->justReturn( array() ); // limited default

		$request = $this->graphql_request( '{"query":"{ posts { nodes { id title } } }"}' );
		$result  = $this->gate->intercept_rest( null, array(), $request );

		$this->assertNull( $result );
	}

	/** @test */
	public function test_empty_body_passes_through_on_limited_policy(): void {
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'get_option' )->justReturn( array() );

		$request = $this->graphql_request( '' );
		$result  = $this->gate->intercept_rest( null, array(), $request );

		$this->assertNull( $result );
	}

	// ── Policy: Disabled ───────────────────────────────────────────────

	/** @test */
	public function test_disabled_policy_blocks_graphql_query(): void {
		Functions\when( 'apply_filters' )->returnArg( 2 );
		$this->with_policy( Gate::POLICY_DISABLED );

		$request = $this->graphql_request( '{"query":"{ posts { nodes { id } } }"}' );
		$result  = $this->gate->intercept_rest( null, array(), $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'sudo_disabled', $result->get_error_code() );
	}

	/** @test */
	public function test_disabled_policy_blocks_graphql_mutation(): void {
		Functions\when( 'apply_filters' )->returnArg( 2 );
		$this->with_policy( Gate::POLICY_DISABLED );

		$request = $this->graphql_request( '{"query":"mutation { deleteUser(input:{id:\"1\"}) { deletedId } }"}' );
		$result  = $this->gate->intercept_rest( null, array(), $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'sudo_disabled', $result->get_error_code() );
	}

	/** @test */
	public function test_disabled_policy_does_not_fire_blocked_hook(): void {
		Functions\when( 'apply_filters' )->returnArg( 2 );
		$this->with_policy( Gate::POLICY_DISABLED );

		Actions\expectDone( 'wp_sudo_action_blocked' )->never();

		$request = $this->graphql_request( '{"query":"mutation { deleteUser(input:{id:\"1\"}) { deletedId } }"}' );
		$this->gate->intercept_rest( null, array(), $request );
	}

	// ── Policy: Unrestricted ───────────────────────────────────────────

	/** @test */
	public function test_unrestricted_policy_passes_graphql_mutation(): void {
		Functions\when( 'apply_filters' )->returnArg( 2 );
		$this->with_policy( Gate::POLICY_UNRESTRICTED );

		$request = $this->graphql_request( '{"query":"mutation { deleteUser(input:{id:\"1\"}) { deletedId } }"}' );
		$result  = $this->gate->intercept_rest( null, array(), $request );

		$this->assertNull( $result );
	}

	/** @test */
	public function test_unrestricted_policy_passes_graphql_query(): void {
		Functions\when( 'apply_filters' )->returnArg( 2 );
		$this->with_policy( Gate::POLICY_UNRESTRICTED );

		$request = $this->graphql_request( '{"query":"{ posts { nodes { id } } }"}' );
		$result  = $this->gate->intercept_rest( null, array(), $request );

		$this->assertNull( $result );
	}

	// ── Policy: Limited (default) ──────────────────────────────────────

	/** @test */
	public function test_limited_policy_blocks_mutation_without_session_and_fires_hook(): void {
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'get_option' )->justReturn( array() ); // limited is the default

		Actions\expectDone( 'wp_sudo_action_blocked' )
			->once()
			->with( 1, 'wpgraphql', 'wpgraphql' );

		$request = $this->graphql_request( '{"query":"mutation { deleteUser(input:{id:\"1\"}) { deletedId } }"}' );
		$result  = $this->gate->intercept_rest( null, array(), $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'sudo_blocked', $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data()['status'] );
	}

	/** @test */
	public function test_limited_policy_passes_query_without_session(): void {
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'get_option' )->justReturn( array() );

		Actions\expectDone( 'wp_sudo_action_blocked' )->never();

		$request = $this->graphql_request( '{"query":"{ users { nodes { id name } } }"}' );
		$result  = $this->gate->intercept_rest( null, array(), $request );

		$this->assertNull( $result );
	}

	/** @test */
	public function test_unauthenticated_request_passes_through(): void {
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'get_current_user_id' )->justReturn( 0 ); // no user

		$request = $this->graphql_request( '{"query":"mutation { deleteUser(input:{id:\"1\"}) { deletedId } }"}' );
		$result  = $this->gate->intercept_rest( null, array(), $request );

		$this->assertNull( $result );
	}

	/** @test */
	public function test_already_error_response_passes_through(): void {
		Functions\when( 'is_wp_error' )->justReturn( true );

		$existing_error = new \WP_Error( 'rest_forbidden', 'Forbidden' );
		$request        = $this->graphql_request( '{"query":"mutation { deleteUser(input:{id:\"1\"}) { deletedId } }"}' );
		$result         = $this->gate->intercept_rest( $existing_error, array(), $request );

		$this->assertSame( $existing_error, $result );
	}
}
