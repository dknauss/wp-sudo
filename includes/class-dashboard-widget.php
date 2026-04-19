<?php
/**
 * Dashboard_Widget class.
 *
 * Session Activity Dashboard Widget for operator visibility.
 *
 * @package WP_Sudo
 * @since   2.15.0
 */

namespace WP_Sudo;

/**
 * Dashboard widget showing sudo session activity and policy status.
 *
 * Three sections:
 * - Active Sessions: current users with unexpired sudo sessions.
 * - Recent Events: last 10 events from Event_Store.
 * - Policy Summary: session duration and surface policies.
 *
 * @since 2.15.0
 */
class Dashboard_Widget {

	/**
	 * Widget ID.
	 *
	 * @var string
	 */
	public const WIDGET_ID = 'wp_sudo_activity';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'wp_dashboard_setup', array( self::class, 'register' ) );
	}

	/**
	 * Register the dashboard widget.
	 *
	 * Only adds the widget for users with manage_options capability.
	 *
	 * @return void
	 */
	public static function register(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		wp_add_dashboard_widget(
			self::WIDGET_ID,
			__( 'Sudo Session Activity', 'wp-sudo' ),
			array( self::class, 'render' )
		);
	}

	/**
	 * Render the widget content.
	 *
	 * @return void
	 */
	public static function render(): void {
		self::render_active_sessions();
		self::render_recent_events();
		self::render_policy_summary();
	}

	/**
	 * Maximum users to display in active sessions list.
	 *
	 * @var int
	 */
	private const MAX_DISPLAY_USERS = 5;

	/**
	 * Render active sessions section.
	 *
	 * Queries users with unexpired sudo sessions and displays count + usernames.
	 *
	 * @return void
	 */
	private static function render_active_sessions(): void {
		$users = get_users(
			array(
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => '_wp_sudo_expires',
						'value'   => time(),
						'compare' => '>',
						'type'    => 'NUMERIC',
					),
				),
				'fields'     => 'ID',
			)
		);

		$count = count( $users );

		echo '<h3>' . esc_html__( 'Active Sessions', 'wp-sudo' ) . '</h3>';

		if ( 0 === $count ) {
			echo '<p>' . esc_html__( 'No active sessions', 'wp-sudo' ) . '</p>';
			return;
		}

		// phpcs:ignore WordPress.WP.I18n.MissingTranslatorsComment -- Simple count.
		echo '<p><strong>' . esc_html( sprintf( _n( '%d active session', '%d active sessions', $count, 'wp-sudo' ), $count ) ) . '</strong></p>';

		echo '<ul>';
		$displayed = 0;
		foreach ( $users as $user_id ) {
			if ( $displayed >= self::MAX_DISPLAY_USERS ) {
				break;
			}

			$user = get_userdata( (int) $user_id );
			if ( ! $user ) {
				continue;
			}

			$expires    = (int) get_user_meta( (int) $user_id, '_wp_sudo_expires', true );
			$time_left  = human_time_diff( time(), $expires );
			$user_login = isset( $user->user_login ) && is_string( $user->user_login ) ? $user->user_login : 'User ' . $user_id;

			echo '<li>' . esc_html( $user_login ) . ' — ' . esc_html( $time_left ) . ' ' . esc_html__( 'remaining', 'wp-sudo' ) . '</li>';
			++$displayed;
		}
		echo '</ul>';

		if ( $count > self::MAX_DISPLAY_USERS ) {
			// phpcs:ignore WordPress.WP.I18n.MissingTranslatorsComment -- Simple count.
			echo '<p><em>' . esc_html( sprintf( __( '+ %d more', 'wp-sudo' ), $count - self::MAX_DISPLAY_USERS ) ) . '</em></p>';
		}
	}

	/**
	 * Event label map for human-readable display.
	 *
	 * @var array<string, string>
	 */
	private const EVENT_LABELS = array(
		'lockout'         => 'Lockout',
		'action_gated'    => 'Gated',
		'action_blocked'  => 'Blocked',
		'action_allowed'  => 'Allowed',
		'action_replayed' => 'Replayed',
	);

	/**
	 * Render recent events section.
	 *
	 * Displays the last 10 events from Event_Store in a table.
	 *
	 * @return void
	 */
	private static function render_recent_events(): void {
		// Ensure the events table exists before querying.
		Event_Store::maybe_create_table();
		$events = Event_Store::recent( 10 );

		echo '<h3>' . esc_html__( 'Recent Events', 'wp-sudo' ) . '</h3>';

		if ( empty( $events ) ) {
			echo '<p>' . esc_html__( 'No recent activity', 'wp-sudo' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Time', 'wp-sudo' ) . '</th>';
		echo '<th>' . esc_html__( 'User', 'wp-sudo' ) . '</th>';
		echo '<th>' . esc_html__( 'Event', 'wp-sudo' ) . '</th>';
		echo '<th>' . esc_html__( 'Action', 'wp-sudo' ) . '</th>';
		echo '<th>' . esc_html__( 'Surface', 'wp-sudo' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $events as $event ) {
			$event_obj = is_array( $event ) ? (object) $event : $event;

			$created_at  = isset( $event_obj->created_at ) ? strtotime( (string) $event_obj->created_at ) : 0;
			$time_ago    = $created_at > 0 ? human_time_diff( $created_at, time() ) : '—';
			$user_id     = isset( $event_obj->user_id ) ? (int) $event_obj->user_id : 0;
			$user        = $user_id > 0 ? get_userdata( $user_id ) : null;
			$username    = $user && isset( $user->user_login ) ? $user->user_login : '—';
			$event_type  = isset( $event_obj->event ) ? (string) $event_obj->event : '';
			$event_label = self::EVENT_LABELS[ $event_type ] ?? $event_type;
			$rule_id     = isset( $event_obj->rule_id ) ? (string) $event_obj->rule_id : '';
			$rule_label  = self::get_rule_label( $rule_id );
			$surface     = isset( $event_obj->surface ) ? (string) $event_obj->surface : '';

			echo '<tr>';
			echo '<td>' . esc_html( $time_ago ) . '</td>';
			echo '<td>' . esc_html( $username ) . '</td>';
			echo '<td>' . esc_html( $event_label ) . '</td>';
			echo '<td>' . esc_html( $rule_label ) . '</td>';
			echo '<td>' . esc_html( '' !== $surface ? $surface : '—' ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * Get human-readable label for a rule ID.
	 *
	 * @param string $rule_id Action Registry rule ID.
	 * @return string
	 */
	private static function get_rule_label( string $rule_id ): string {
		if ( '' === $rule_id ) {
			return '—';
		}

		$rules = Action_Registry::get_rules();
		foreach ( $rules as $rule ) {
			if ( isset( $rule['id'] ) && $rule['id'] === $rule_id ) {
				return isset( $rule['label'] ) ? (string) $rule['label'] : $rule_id;
			}
		}

		return $rule_id;
	}

	/**
	 * Policy label map for human-readable display.
	 *
	 * @var array<string, string>
	 */
	private const POLICY_LABELS = array(
		'disabled'     => 'Disabled',
		'limited'      => 'Limited',
		'unrestricted' => 'Unrestricted',
	);

	/**
	 * Default settings for display.
	 *
	 * @var array<string, mixed>
	 */
	private const DEFAULTS = array(
		'session_duration'         => 5,
		'rest_app_password_policy' => 'limited',
		'cli_policy'               => 'limited',
		'cron_policy'              => 'disabled',
		'xmlrpc_policy'            => 'disabled',
		'wpgraphql_policy'         => 'disabled',
	);

	/**
	 * Render policy summary section.
	 *
	 * Displays current session duration and entry-point policies.
	 *
	 * @return void
	 */
	private static function render_policy_summary(): void {
		$settings = get_option( 'wp_sudo_settings', array() );
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		$duration = isset( $settings['session_duration'] ) ? (int) $settings['session_duration'] : self::DEFAULTS['session_duration'];

		echo '<h3>' . esc_html__( 'Policy Summary', 'wp-sudo' ) . '</h3>';

		echo '<p><strong>' . esc_html__( 'Session Duration:', 'wp-sudo' ) . '</strong> ';
		// phpcs:ignore WordPress.WP.I18n.MissingTranslatorsComment -- Simple duration.
		echo esc_html( sprintf( _n( '%d minute', '%d minutes', $duration, 'wp-sudo' ), $duration ) );
		echo '</p>';

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Surface', 'wp-sudo' ) . '</th>';
		echo '<th>' . esc_html__( 'Policy', 'wp-sudo' ) . '</th>';
		echo '</tr></thead><tbody>';

		$surfaces = array(
			'rest_app_password_policy' => __( 'REST App Passwords', 'wp-sudo' ),
			'cli_policy'               => __( 'CLI', 'wp-sudo' ),
			'cron_policy'              => __( 'Cron', 'wp-sudo' ),
			'xmlrpc_policy'            => __( 'XML-RPC', 'wp-sudo' ),
		);

		// Add WPGraphQL if active.
		if ( class_exists( 'WPGraphQL' ) ) {
			$surfaces['wpgraphql_policy'] = __( 'WPGraphQL', 'wp-sudo' );
		}

		foreach ( $surfaces as $key => $label ) {
			$policy       = isset( $settings[ $key ] ) ? (string) $settings[ $key ] : (string) self::DEFAULTS[ $key ];
			$policy_label = self::POLICY_LABELS[ $policy ] ?? $policy;

			echo '<tr>';
			echo '<td>' . esc_html( $label ) . '</td>';
			echo '<td>' . esc_html( $policy_label ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}
}
