<?php
/**
 * Integration tests for Event_Store query shape and index backfills.
 *
 * @package WP_Sudo\Tests\Integration
 */

namespace WP_Sudo\Tests\Integration;

use WP_Sudo\Event_Store;

/**
 * @covers \WP_Sudo\Event_Store
 * @covers \WP_Sudo\Upgrader
 * @group event-store
 */
class EventStoreTest extends TestCase {

	/**
	 * Ensure the events table exists before each test.
	 */
	public function set_up(): void {
		parent::set_up();

		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}

		Event_Store::create_table();

		if ( ! $this->table_exists() ) {
			$this->markTestSkipped( 'Event_Store table could not be created.' );
		}

		$this->clear_events_table();
	}

	/**
	 * Clean up rows created by the test.
	 */
	public function tear_down(): void {
		$this->clear_events_table();

		parent::tear_down();
	}

	/**
	 * The 3.0.0 migration reruns create_table() safely and exposes new indexes.
	 */
	public function test_create_table_is_idempotent_and_backfills_performance_indexes(): void {
		Event_Store::create_table();

		$index_names = $this->get_index_names();

		$this->assertContains( 'event_created_at', $index_names );
		$this->assertContains( 'site_created_at', $index_names );
		$this->assertContains( 'user_created_at', $index_names );
		$this->assertContains( 'created_at', $index_names );
		$this->assertContains( 'site_event_created_at', $index_names );
	}

	/**
	 * Dashboard reads return lean rows, ordered newest-first, and honor filters.
	 */
	public function test_recent_for_dashboard_returns_lean_rows_in_descending_order(): void {
		$user = $this->make_admin();

		Event_Store::insert(
			array(
				'user_id'    => $user->ID,
				'event'      => 'action_allowed',
				'rule_id'    => 'plugin.activate',
				'surface'    => 'cli',
				'ip'         => '127.0.0.1',
				'context'    => array( 'source' => 'older' ),
				'created_at' => '2026-04-01 00:00:00',
			)
		);

		Event_Store::insert(
			array(
				'user_id'    => $user->ID,
				'event'      => 'action_gated',
				'rule_id'    => 'plugin.delete',
				'surface'    => 'admin',
				'ip'         => '127.0.0.1',
				'context'    => array( 'source' => 'middle' ),
				'created_at' => '2026-04-02 00:00:00',
			)
		);

		Event_Store::insert(
			array(
				'user_id'    => $user->ID,
				'event'      => 'action_allowed',
				'rule_id'    => 'plugin.update',
				'surface'    => 'rest',
				'ip'         => '127.0.0.1',
				'context'    => array( 'source' => 'newest' ),
				'created_at' => '2026-04-03 00:00:00',
			)
		);

		$events = Event_Store::recent_for_dashboard( 10, 'action_allowed' );

		$this->assertCount( 2, $events );
		$this->assertSame( 'plugin.update', $events[0]['rule_id'] );
		$this->assertSame( 'plugin.activate', $events[1]['rule_id'] );
		$this->assertSame(
			array( 'id', 'created_at', 'user_id', 'event', 'rule_id', 'surface' ),
			array_keys( $events[0] )
		);
		$this->assertArrayNotHasKey( 'context', $events[0] );
		$this->assertArrayNotHasKey( 'ip', $events[0] );
	}

	/**
	 * Prune deletes old rows while leaving fresh rows available for reads.
	 */
	public function test_prune_removes_old_rows_and_preserves_recent_rows(): void {
		$user = $this->make_admin();

		Event_Store::insert(
			array(
				'user_id'    => $user->ID,
				'event'      => 'action_gated',
				'rule_id'    => 'plugin.delete',
				'surface'    => 'admin',
				'ip'         => '127.0.0.1',
				'context'    => array(),
				'created_at' => gmdate( 'Y-m-d H:i:s', time() - ( 30 * DAY_IN_SECONDS ) ),
			)
		);

		Event_Store::insert(
			array(
				'user_id'    => $user->ID,
				'event'      => 'action_allowed',
				'rule_id'    => 'plugin.activate',
				'surface'    => 'cli',
				'ip'         => '127.0.0.1',
				'context'    => array(),
				'created_at' => gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ),
			)
		);

		$deleted = Event_Store::prune( 14 );
		$events  = Event_Store::recent( 10 );

		$this->assertSame( 1, $deleted );
		$this->assertCount( 1, $events );
		$this->assertSame( 'plugin.activate', $events[0]['rule_id'] );
	}

	/**
	 * Check if the events table exists.
	 *
	 * @return bool
	 */
	private function table_exists(): bool {
		global $wpdb;

		$wpdb->suppress_errors( true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Event_Store::table_name() is a safe identifier assembled from the configured prefix.
		$columns = $wpdb->get_results( 'DESCRIBE ' . Event_Store::table_name() );
		$wpdb->suppress_errors( false );

		return is_array( $columns ) && ! empty( $columns );
	}

	/**
	 * Delete all event rows between tests.
	 *
	 * @return void
	 */
	private function clear_events_table(): void {
		global $wpdb;

		if ( ! $this->table_exists() ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Event_Store::table_name() is a safe identifier assembled from the configured prefix.
		$wpdb->query( 'DELETE FROM ' . Event_Store::table_name() );
	}

	/**
	 * Fetch the index names currently defined on the events table.
	 *
	 * @return array<int, string>
	 */
	private function get_index_names(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Event_Store::table_name() is a safe identifier assembled from the configured prefix.
		$indexes = $wpdb->get_results( 'SHOW INDEX FROM ' . Event_Store::table_name() );

		if ( ! is_array( $indexes ) ) {
			return array();
		}

		$names = array();

		foreach ( $indexes as $index ) {
			if ( is_object( $index ) && isset( $index->Key_name ) && is_string( $index->Key_name ) ) {
				$names[] = $index->Key_name;
			}
		}

		return array_values( array_unique( $names ) );
	}
}
