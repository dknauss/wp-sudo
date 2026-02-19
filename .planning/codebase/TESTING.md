# Testing Patterns

**Analysis Date:** 2026-02-19

## Test Framework

**Runner:**
- PHPUnit 9.6
- Config: `phpunit.xml.dist` (lines 1-23)
- Strict mode enabled: `beStrictAboutTestsThatDoNotTestAnything="true"`, `beStrictAboutOutputDuringTests="true"`, `failOnWarning="true"`, `failOnRisky="true"`

**Assertion Library:**
- PHPUnit native assertions: `assertSame()`, `assertFalse()`, `assertTrue()`, `assertIsArray()`, `assertArrayHasKey()`, `assertArrayNotHasKey()`, `assertGreaterThan()`, `assertLessThanOrEqual()`
- Mockery assertions: `expect()`, `once()`, `with()`, `never()`, `andReturn()` (via Brain\Monkey facade)

**WordPress Function Mocking:**
- Brain\Monkey 2.7+ (see `composer.json` line 11)
- Replaces actual WordPress functions with test stubs/mocks

**Run Commands:**
```bash
composer test              # Run all tests (PHPUnit)
./vendor/bin/phpunit tests/Unit/SudoSessionTest.php   # Run single test file
./vendor/bin/phpunit --filter testMethodName          # Run single test method
```

## Test File Organization

**Location:**
- All tests in `tests/Unit/` directory
- Co-located with source (mirror naming, not separate `__tests__` dir)
- One test class per source class

**Naming:**
- Test files: `{SourceClass}Test.php` → `SudoSessionTest.php`, `GateTest.php`, `ChallengeTest.php`
- Test classes: `namespace WP_Sudo\Tests\Unit;`, extend `WP_Sudo\Tests\TestCase`
- Test methods: `test_{feature}_{scenario}` → `test_is_active_returns_true_when_valid()`, `test_detect_surface_ajax()`

**Structure:**
```
tests/
├── bootstrap.php          # WordPress stubs, constants, classmap autoloader
├── TestCase.php           # Base class with Brain\Monkey setup/teardown
├── MANUAL-TESTING.md      # UI/UX testing checklist (not automated)
└── Unit/
    ├── ActionRegistryTest.php
    ├── AdminTest.php
    ├── AdminBarTest.php
    ├── ChallengeTest.php
    ├── GateTest.php
    ├── PluginTest.php
    ├── RequestStashTest.php
    ├── SiteHealthTest.php
    ├── SudoSessionTest.php
    └── UpgraderTest.php
```

## Test Structure

**Suite Organization:**

From `GateTest.php` (lines 21-66):
```php
class GateTest extends TestCase {

	/**
	 * Gate instance under test.
	 *
	 * @var Gate
	 */
	private Gate $gate;

	protected function setUp(): void {
		parent::setUp();
		// Test doubles created here
		$this->session = \Mockery::mock( Sudo_Session::class );
		$this->stash   = \Mockery::mock( Request_Stash::class );
		$this->gate    = new Gate( $this->session, $this->stash );
	}

	protected function tearDown(): void {
		// Unset globals used by test
		unset( $_REQUEST['action'], ... );
		parent::tearDown();
	}
}
```

**Patterns:**
- **Setup:** Create mocks in `setUp()`, instantiate system under test (SUT) with mocks
- **Teardown:** Clear test state (globals, superglobals, static caches) before calling `parent::tearDown()`
- **Assertion:** One logical assertion per test (may involve multiple `assert*()` calls for a single behavior)
- **Naming:** Test method names describe scenario: `test_register_hooks()`, `test_is_active_returns_true_when_valid()`

**Section organization within test class:**

From `AdminTest.php` (lines 23-24):
```php
	// -----------------------------------------------------------------
	// defaults()
	// -----------------------------------------------------------------

	public function test_defaults_returns_expected_structure(): void {
```

Tests grouped by method being tested, separated by comment blocks.

## Mocking

**Framework:** Mockery 1.6+ (with Brain\Monkey integration)

**WordPress Function Mocking (Brain\Monkey):**

From `TestCase.php` (lines 27-36):
```php
Functions\stubs(
	array(
		'wp_unslash'          => static function ( $value ) {
			return $value;
		},
		'sanitize_text_field' => static function ( $str ) {
			return (string) $str;
		},
	)
);
```

- **`Functions\stubs()`** — Define default behavior for pure functions
- **`Functions\when()`** — Define conditional behavior (can be overridden per test)
- **`Functions\expect()`** — Assert function was called with specific arguments

From `ChallengeTest.php` (lines 88-98):
```php
public function test_enqueue_assets_skips_other_pages(): void {
	$_GET['page'] = 'some-other-page';

	// wp_enqueue_style should never be called.
	Functions\expect( 'wp_enqueue_style' )->never();
	Functions\expect( 'wp_enqueue_script' )->never();

	$this->challenge->enqueue_assets();

	unset( $_GET['page'] );
}
```

**Object Mocking (Mockery):**

From `GateTest.php` (lines 44-49):
```php
protected function setUp(): void {
	parent::setUp();
	$this->session = \Mockery::mock( Sudo_Session::class );
	$this->stash   = \Mockery::mock( Request_Stash::class );
	$this->gate    = new Gate( $this->session, $this->stash );
}
```

- `\Mockery::mock( ClassName::class )` — Create mock object
- Automatically verified at teardown via `MockeryPHPUnitIntegration` trait

**Action Mocking (Brain\Monkey Actions):**

From `ChallengeTest.php` (lines 50-68):
```php
public function test_register_hooks_the_correct_actions(): void {
	Actions\expectAdded( 'admin_menu' )
		->once()
		->with( array( $this->challenge, 'register_page' ), \Mockery::any() );

	Actions\expectAdded( 'wp_ajax_' . Challenge::AJAX_AUTH_ACTION )
		->once()
		->with( array( $this->challenge, 'handle_ajax_auth' ), \Mockery::any() );

	$this->challenge->register();
}
```

- `Actions\expectAdded()` — Assert hook was registered (with `add_action`)
- `->once()` — Verify called exactly once
- `->with()` — Verify arguments (use `\Mockery::any()` for wildcards)

**What to Mock:**
- WordPress built-in functions: `get_option()`, `get_user_meta()`, `wp_die()`, etc.
- External dependencies (Request_Stash, Sudo_Session) when testing other components
- HTTP operations: `setcookie()`, `header()` (redefined via Patchwork)
- Hooks: Assert actions were registered, not the hook system itself

**What NOT to Mock:**
- The class under test (SUT) — test the real implementation
- Pure helper logic — test the real implementation
- Constants and class properties — read the actual values
- Two_Factor_Core static provider (test controls it via `$mock_provider` property in bootstrap)

## Fixtures and Factories

**Test Data:**

From `SudoSessionTest.php` (lines 142-150):
```php
public function test_is_active_returns_true_when_valid(): void {
	$future = time() + 300;
	$token  = 'valid-token-456';

	Functions\when( 'get_user_meta' )->alias( function ( $uid, $key, $single ) use ( $future, $token ) {
		if ( Sudo_Session::META_KEY === $key ) {
			return $future;
		}
		if ( Sudo_Session::TOKEN_META_KEY === $key ) {
			return hash( 'sha256', $token );
		}
		return '';
	} );
	// ... test continues
}
```

**Helpers in TestCase:**

From `TestCase.php` (no helper methods currently — base class only provides Brain\Monkey setup):
- No factory methods for users/roles/data yet (see CLAUDE.md: "TDD strategy" roadmap)
- Tests create needed data inline using `Functions\when()->alias()` closures

**WordPress class stubs:**

From `bootstrap.php` (lines 38-156):
- Minimal WP_User stub: `public int $ID`, `public array $roles`, `public string $user_pass`
- WP_Admin_Bar stub: `add_node()`, `get_nodes()`
- WP_Error stub: `get_error_code()`, `get_error_message()`, `get_error_data()`
- WP_REST_Request stub: `get_method()`, `get_route()`, `get_params()`, `get_header()`, `set_header()`
- WP_Screen stub: `add_help_tab()`, `set_help_sidebar()`, `get_help_tabs()`, `get_help_sidebar()`
- Two_Factor_Core mock provider system (lines 175-188): static `$mock_provider` allows tests to simulate Two Factor availability

**Location:**
- Bootstrap stubs in `tests/bootstrap.php` (included via `phpunit.xml.dist` line 5)
- Test data created inline in test methods using closures and function aliases

## Coverage

**Requirements:** Not enforced by CI (see `phpunit.xml.dist` lines 18-22)

Coverage configured but not required:
```xml
<coverage>
	<include>
		<directory suffix=".php">includes</directory>
	</include>
</coverage>
```

**View Coverage:**
```bash
composer test -- --coverage-html ./coverage/
composer test -- --coverage-text
```

## Test Types

**Unit Tests (all tests in `tests/Unit/`):**
- Scope: Single class in isolation with mocked dependencies
- Approach: Dependency injection of test doubles (Mockery mocks + Brain\Monkey function stubs)
- Example: `GateTest` tests Gate class logic without instantiating Session or Stash
- No WordPress loaded: Tests use stubs and mocks to avoid full WP bootstrap

**Integration Tests:**
- Not currently present in this codebase
- Roadmap item: See CLAUDE.md "roadmap-2026-02.md" mentions integration test strategy

**E2E Tests:**
- Not automated
- Manual testing checklist: `tests/MANUAL-TESTING.md` — UI/UX testing prompts
- No Playwright/Cypress/Webdriver tests

## Common Patterns

**Async Testing:**
Not applicable (WordPress/PHP is synchronous, no async/await)

**Hook Testing:**

From `ChallengeTest.php` (lines 50-68) — verify hook registration:
```php
public function test_register_hooks_the_correct_actions(): void {
	Actions\expectAdded( 'admin_menu' )
		->once()
		->with( array( $this->challenge, 'register_page' ), \Mockery::any() );

	$this->challenge->register();
}
```

From `SudoSessionTest.php` — verify action fired (example pattern):
```php
Actions\expectFired( 'wp_sudo_action_gated' )
	->once()
	->when( function () {
		// Code that triggers the action
	} );
```

**Error Testing:**

From `GateTest.php` (pattern for soft-block):
```php
public function test_soft_block_returns_401_for_ajax(): void {
	Functions\expect( 'wp_send_json_error' )
		->once()
		->with( \Mockery::any(), 401 );

	$this->gate->soft_block_request();
}
```

From `ChallengeTest.php` (pattern for hard-block):
```php
public function test_render_page_dies_when_not_logged_in(): void {
	Functions\when( 'is_user_logged_in' )->justReturn( false );
	Functions\expect( 'wp_die' )
		->once()
		->with( \Mockery::type( 'string' ), 403 );

	$this->challenge->render_page();
}
```

**Rate Limiting / Lockout Testing:**

From `SudoSessionTest.php` pattern (lockout constants verified):
```php
public function test_lockout_constants(): void {
	$this->assertSame( '_wp_sudo_failed_attempts', Sudo_Session::LOCKOUT_META_KEY );
	$this->assertSame( '_wp_sudo_lockout_until', Sudo_Session::LOCKOUT_UNTIL_META_KEY );
	$this->assertSame( 5, Sudo_Session::MAX_FAILED_ATTEMPTS );
	$this->assertSame( 300, Sudo_Session::LOCKOUT_DURATION );
}
```

**Constant Verification:**

From multiple test files (e.g., `AdminTest.php` line 26-31):
```php
public function test_defaults_returns_expected_structure(): void {
	$defaults = Admin::defaults();

	$this->assertIsArray( $defaults );
	$this->assertArrayHasKey( 'session_duration', $defaults );
	$this->assertSame( 15, $defaults['session_duration'] );
}
```

## Static Cache Reset

From `TestCase.php` tearDown (lines 53-62):
```php
protected function tearDown(): void {
	unset( $_COOKIE[ \WP_Sudo\Sudo_Session::CHALLENGE_COOKIE ] );

	// Clear per-request static caches to prevent cross-test contamination.
	\WP_Sudo\Action_Registry::reset_cache();
	\WP_Sudo\Sudo_Session::reset_cache();
	\WP_Sudo\Admin::reset_cache();

	Monkey\tearDown();
	parent::tearDown();
}
```

Classes implement `reset_cache()` static method (not shown in public API, but used in tests). This prevents:
- `Action_Registry` from returning cached rules from previous test
- `Sudo_Session` from returning cached session state
- `Admin` from returning cached settings

Tests must call parent `tearDown()` to clear caches.

## Test Database / Transients

**Request Stash (uses transients):**
- Tests mock `Request_Stash` rather than testing transient behavior directly
- Transients are WordPress's temporary key-value store (like filesystem cache)
- Tests verify stash methods (`store()`, `retrieve()`, `delete()`) via Mockery

**No fixtures for:**
- Users (created inline via test data)
- Options/Metadata (mocked via `get_option()` stubs)
- Transients (Request_Stash mocked)

## Patchwork for Low-Level Functions

From `patchwork.json`:
```json
{
	"redefinable-internals": [
		"setcookie",
		"header",
		"hash_equals"
	]
}
```

Allows tests to mock these PHP internals (not normally mockable by frameworks):
- `setcookie()` — Verify session cookies set during `is_active()` check
- `header()` — Verify redirects (not used much, preference for `wp_safe_redirect()`)
- `hash_equals()` — Verify token validation (timing-safe comparison)

Used via Brain\Monkey `Functions\expect()`:
```php
Functions\when( 'setcookie' )->justReturn( true );
```

---

*Testing analysis: 2026-02-19*
