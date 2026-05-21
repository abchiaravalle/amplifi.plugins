# amplifi.schema Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the amplifi.schema WordPress plugin: bulk + per-page AI schema generation via Claude, dual-pane editor, foreign-schema detection, URL rules, single `@graph` output, with migration from amplifi.meta JSON-LD.

**Architecture:** Multi-file plugin under `plugins/ac-schema/` using PSR-style autoloader (namespace `Amplifi\Schema\*`), three custom tables, REST API, WP-Cron-driven bulk queue, React-based dual-pane editor mounted in a post metabox. Closely mirrors `plugins/amplifi-security/` structure. The amplifi.studio framework provides the menu shell and auto-update channel.

**Tech Stack:**
- PHP 8.1+ / WordPress 6.4+
- Anthropic Messages API (Claude Haiku 4.5 / Sonnet 4.6 / Opus 4.7) with tool-use for strict JSON output
- WP REST API + WP-Cron
- React 18 for the dual-pane editor (built with Vite, no inline build during dev)
- PHPUnit for PHP tests, Vitest for JS tests
- Docker Compose for local dev (WP :8093, MySQL :3319)

**Reference patterns:** `plugins/amplifi-security/` (autoloader, installer, `Secret_Store`, `Plugin` orchestrator), `plugins/ac-bulk-meta/` (OpenAI-style admin UI), `plugins/ac-sync/includes/class-acsync-api.php` (REST controller style).

---

## Phase 1 — Plumbing

Goal: empty plugin activates, registers under amplifi.studio, creates DB tables, runs no other logic.

### Task 1.1: Scaffold the plugin directory

**Files:**
- Create: `plugins/ac-schema/ac-schema.php`
- Create: `plugins/ac-schema/uninstall.php`
- Create: `plugins/ac-schema/README.md` (short — name, link to docs)
- Create: `plugins/ac-schema/.gitignore` (excludes `node_modules`, `includes/admin/assets/dist`)
- Create: `plugins/ac-schema/includes/.gitkeep`

- [ ] **Step 1: Create the bootstrap file** at `plugins/ac-schema/ac-schema.php`. Mirror `plugins/amplifi-security/amplifi-security.php` lines 1-100 exactly, replacing every `AMPLIFI_SECURITY_*` constant with `AMPLIFI_SCHEMA_*` and every text-domain `amplifi-security` with `amplifi-schema`. Set `Plugin Name: amplifi.schema`, `Description: Bulk-generate, edit, and deploy schema.org JSON-LD with Claude.`, `Version: 0.1.0`. The constants block must also `define( 'AMPLIFI_SCHEMA_ACTIVE', true );` — this is the deferral signal amplifi.meta checks. Append after the OpenSSL gate:

```php
// Load the amplifi.studio shared framework (top-level menu, hub, auto-updates).
require_once AMPLIFI_SCHEMA_PATH . 'includes/amplifi-framework.php';

// Load namespace autoloader and bootstrap.
require_once AMPLIFI_SCHEMA_PATH . 'includes/class-autoloader.php';
\Amplifi\Schema\Autoloader::register();

register_activation_hook( __FILE__, [ \Amplifi\Schema\Activator::class, 'activate' ] );
register_deactivation_hook( __FILE__, [ \Amplifi\Schema\Deactivator::class, 'deactivate' ] );

add_action( 'plugins_loaded', static function () {
    ( new \Amplifi\Schema\Plugin() )->boot();
}, 20 );
```

- [ ] **Step 2: Create uninstall.php** at `plugins/ac-schema/uninstall.php`:

```php
<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) { exit; }

global $wpdb;
$tables = [
    $wpdb->prefix . 'ac_schema_entries',
    $wpdb->prefix . 'ac_schema_bulk_jobs',
    $wpdb->prefix . 'ac_schema_spend',
];
foreach ( $tables as $t ) {
    $wpdb->query( "DROP TABLE IF EXISTS $t" ); // phpcs:ignore
}

$option_keys = [
    'ac_schema_settings',
    'ac_schema_global_organization',
    'ac_schema_global_website',
    'ac_schema_global_localbusiness',
    'ac_schema_url_rules',
    'ac_schema_db_version',
    'ac_schema_onboarding_complete',
    'ac_schema_meta_import_status',
];
foreach ( $option_keys as $k ) { delete_option( $k ); }

delete_post_meta_by_key( '_ac_schema_overrides' );
delete_post_meta_by_key( '_ac_schema_detected_cache' );
```

- [ ] **Step 3: Copy the framework into the includes folder.** Copy `shared/amplifi-framework.php` to `plugins/ac-schema/includes/amplifi-framework.php` verbatim. (At release time `scripts/release.sh` re-copies it; for dev it lives in the repo.)

- [ ] **Step 4: Add `.gitignore`:**

```
node_modules/
includes/admin/assets/dist/
*.log
```

- [ ] **Step 5: Commit.**

```bash
git add plugins/ac-schema/
git commit -m "feat(ac-schema): scaffold plugin bootstrap and uninstall"
```

### Task 1.2: Autoloader

**Files:**
- Create: `plugins/ac-schema/includes/class-autoloader.php`

- [ ] **Step 1: Copy autoloader pattern.** Copy `plugins/amplifi-security/includes/class-autoloader.php` to the new path, then in the new file replace `Amplifi\Security` with `Amplifi\Schema`, `AMPLIFI_SECURITY_PATH` with `AMPLIFI_SCHEMA_PATH`, and the docblock `@package` accordingly. No other changes.

- [ ] **Step 2: Quick verify** — start docker (after Task 1.4 if not yet), or just lint:

```bash
php -l plugins/ac-schema/includes/class-autoloader.php
```

Expected: `No syntax errors`.

- [ ] **Step 3: Commit.**

```bash
git add plugins/ac-schema/includes/class-autoloader.php
git commit -m "feat(ac-schema): add PSR-style autoloader"
```

### Task 1.3: Activator, Deactivator, Installer, empty Plugin orchestrator

**Files:**
- Create: `plugins/ac-schema/includes/class-activator.php`
- Create: `plugins/ac-schema/includes/class-deactivator.php`
- Create: `plugins/ac-schema/includes/class-installer.php`
- Create: `plugins/ac-schema/includes/class-plugin.php`

- [ ] **Step 1: Write Installer with all three tables.** Create `class-installer.php`:

```php
<?php
declare(strict_types=1);
namespace Amplifi\Schema;
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Installer {

    public const DB_VERSION = '1';

    public static function install(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $prefix  = $wpdb->prefix;

        dbDelta( "CREATE TABLE {$prefix}ac_schema_entries (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            scope_type varchar(16) NOT NULL,
            scope_id varchar(191) NOT NULL,
            schema_type varchar(64) NOT NULL,
            source varchar(16) NOT NULL,
            json_ld longtext NOT NULL,
            hash char(64) NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY scope_type_id_schema (scope_type, scope_id, schema_type),
            KEY scope_lookup (scope_type, scope_id)
        ) $charset;" );

        dbDelta( "CREATE TABLE {$prefix}ac_schema_bulk_jobs (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            status varchar(16) NOT NULL,
            scope longtext NOT NULL,
            total int NOT NULL DEFAULT 0,
            processed int NOT NULL DEFAULT 0,
            failed int NOT NULL DEFAULT 0,
            model varchar(64) NOT NULL,
            started_at datetime NULL,
            finished_at datetime NULL,
            cost_usd decimal(10,4) NOT NULL DEFAULT 0,
            PRIMARY KEY  (id),
            KEY status (status)
        ) $charset;" );

        dbDelta( "CREATE TABLE {$prefix}ac_schema_spend (
            day date NOT NULL,
            input_tokens bigint(20) NOT NULL DEFAULT 0,
            output_tokens bigint(20) NOT NULL DEFAULT 0,
            cost_usd decimal(10,4) NOT NULL DEFAULT 0,
            PRIMARY KEY  (day)
        ) $charset;" );

        update_option( 'ac_schema_db_version', self::DB_VERSION );
    }

    public static function maybe_upgrade(): void {
        if ( get_option( 'ac_schema_db_version' ) !== self::DB_VERSION ) {
            self::install();
        }
    }
}
```

- [ ] **Step 2: Write Activator and Deactivator.**

`class-activator.php`:

```php
<?php
declare(strict_types=1);
namespace Amplifi\Schema;
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Activator {
    public static function activate(): void {
        Installer::install();

        if ( ! get_option( 'ac_schema_settings' ) ) {
            update_option( 'ac_schema_settings', [
                'default_model'           => 'claude-haiku-4-5-20251001',
                'daily_spend_cap_usd'     => 5.0,
                'monthly_spend_cap_usd'   => 50.0,
                'output_priority'         => 1,
                'suppress_amplifi_meta_jsonld' => true,
            ] );
        }

        // Stage meta-import notice if amplifi.meta is present.
        if ( defined( 'AC_BULK_META_VERSION' ) || is_plugin_active( 'ac-bulk-meta/ac-bulk-meta.php' ) ) {
            if ( false === get_option( 'ac_schema_meta_import_status', false ) ) {
                update_option( 'ac_schema_meta_import_status', 'pending' );
            }
        }
    }
}
```

`class-deactivator.php`:

```php
<?php
declare(strict_types=1);
namespace Amplifi\Schema;
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Deactivator {
    public static function deactivate(): void {
        wp_clear_scheduled_hook( 'ac_schema_run_bulk_batch' );
    }
}
```

- [ ] **Step 3: Write minimal Plugin orchestrator.** `class-plugin.php`:

```php
<?php
declare(strict_types=1);
namespace Amplifi\Schema;
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Plugin {
    public function boot(): void {
        Installer::maybe_upgrade();
        // Subsystems are wired in later phases.
    }
}
```

- [ ] **Step 4: Activate in docker (after Task 1.4) and verify tables.** Expected: 3 tables present, option `ac_schema_db_version = "1"`.

- [ ] **Step 5: Commit.**

```bash
git add plugins/ac-schema/includes/
git commit -m "feat(ac-schema): installer, activator, deactivator, plugin shell"
```

### Task 1.4: Docker dev environment

**Files:**
- Create: `plugins/ac-schema/docker-compose.yml`

- [ ] **Step 1: Copy docker compose pattern from another plugin** — copy `plugins/amplifi-security/docker-compose.yml`, rewrite to use port `8093` for WP and `3319` for MySQL. Volume-mount `./` to `/var/www/html/wp-content/plugins/ac-schema`.

- [ ] **Step 2: Smoke test.**

```bash
cd plugins/ac-schema && docker-compose up -d
```

Open `http://localhost:8093`, complete WP install, activate amplifi.schema, confirm "amplifi.studio" menu appears with no fatal errors. Inspect DB to confirm tables.

- [ ] **Step 3: Update root CLAUDE.md** — add `ac-schema` to the plugins listing in the Development section: `cd plugins/ac-schema && docker-compose up -d    # WordPress on :8093, MySQL on :3319`.

- [ ] **Step 4: Commit.**

```bash
git add plugins/ac-schema/docker-compose.yml CLAUDE.md
git commit -m "feat(ac-schema): docker dev environment on :8093"
```

### Task 1.5: Register with amplifi.studio framework

**Files:**
- Modify: `plugins/ac-schema/includes/class-plugin.php`
- Create: `plugins/ac-schema/includes/admin/class-admin.php`
- Create: `plugins/ac-schema/includes/admin/class-dashboard-page.php`

- [ ] **Step 1: Create stub Dashboard page.** `class-dashboard-page.php`:

```php
<?php
declare(strict_types=1);
namespace Amplifi\Schema\Admin;
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Dashboard_Page {
    public function render(): void {
        echo '<div class="wrap"><h1>amplifi.schema</h1><p>Coming online…</p></div>';
    }
}
```

- [ ] **Step 2: Create Admin orchestrator.** `class-admin.php`:

```php
<?php
declare(strict_types=1);
namespace Amplifi\Schema\Admin;
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Admin {
    public function register(): void {
        add_action( 'init', [ $this, 'register_with_framework' ], 5 );
    }

    public function register_with_framework(): void {
        if ( ! function_exists( 'amplifi_register_plugin' ) ) { return; }
        $dashboard = new Dashboard_Page();
        amplifi_register_plugin(
            'ac-schema',
            'Schema',
            'AI schema.org generation and editor.',
            AMPLIFI_SCHEMA_VERSION,
            AMPLIFI_SCHEMA_FILE,
            [ $dashboard, 'render' ]
        );
    }
}
```

- [ ] **Step 3: Wire it up.** Modify `class-plugin.php`'s `boot()`:

```php
public function boot(): void {
    Installer::maybe_upgrade();
    ( new Admin\Admin() )->register();
}
```

- [ ] **Step 4: Verify in browser.** Reload WP admin → Schema appears under amplifi.studio with the stub page.

- [ ] **Step 5: Commit.**

```bash
git add plugins/ac-schema/includes/
git commit -m "feat(ac-schema): register Schema submenu under amplifi.studio"
```

---

## Phase 2 — Core domain: Registry + Validator

Goal: validate any JSON-LD against schema.org structure, in pure PHP, no I/O.

### Task 2.1: Bundle the schema.org type index

**Files:**
- Create: `plugins/ac-schema/includes/schema/data/schema-org-types.json`
- Create: `plugins/ac-schema/scripts/build-schema-index.php` (build-time only)

- [ ] **Step 1: Write the build script** that produces `schema-org-types.json`. Script fetches `https://schema.org/version/latest/schemaorg-current-https.jsonld`, walks the graph, and emits a lean map keyed by type name:

```json
{
  "Article": {
    "parent": "CreativeWork",
    "properties": ["headline", "author", "datePublished", "image", "..."],
    "required_for_rich_results": ["headline", "author", "datePublished", "image"]
  },
  "Organization": { "...": "..." }
}
```

`required_for_rich_results` is a hand-maintained allowlist per known rich-result type — start with `Article`, `BlogPosting`, `NewsArticle`, `Product`, `FAQPage`, `Event`, `Recipe`, `HowTo`, `LocalBusiness`, `Course`, `BreadcrumbList`, `VideoObject`, `Person`. For anything not in the allowlist, leave required empty.

The properties list for each type should include inherited properties (walk `parent` chain via `rdfs:subClassOf`).

- [ ] **Step 2: Run the build script** and produce the JSON file. Commit both the script and the generated file (the file is small, ~200KB).

- [ ] **Step 3: Commit.**

```bash
git add plugins/ac-schema/scripts/build-schema-index.php plugins/ac-schema/includes/schema/data/schema-org-types.json
git commit -m "feat(ac-schema): bundle schema.org type/property index"
```

### Task 2.2: Registry class

**Files:**
- Create: `plugins/ac-schema/includes/schema/class-registry.php`
- Create: `plugins/ac-schema/tests/Schema/RegistryTest.php`
- Create: `plugins/ac-schema/tests/bootstrap.php`
- Create: `plugins/ac-schema/phpunit.xml.dist`
- Create: `plugins/ac-schema/composer.json`

- [ ] **Step 1: Set up PHPUnit.**

`composer.json`:

```json
{
  "name": "amplifi/ac-schema",
  "type": "wordpress-plugin",
  "require-dev": {
    "phpunit/phpunit": "^10.0",
    "yoast/phpunit-polyfills": "^2.0"
  },
  "autoload-dev": {
    "psr-4": { "Amplifi\\Schema\\Tests\\": "tests/" }
  }
}
```

`phpunit.xml.dist`:

```xml
<?xml version="1.0"?>
<phpunit bootstrap="tests/bootstrap.php" colors="true">
  <testsuites>
    <testsuite name="ac-schema">
      <directory>tests/</directory>
    </testsuite>
  </testsuites>
</phpunit>
```

`tests/bootstrap.php`:

```php
<?php
declare(strict_types=1);
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/../' ); }
if ( ! defined( 'AMPLIFI_SCHEMA_PATH' ) ) { define( 'AMPLIFI_SCHEMA_PATH', dirname( __DIR__ ) . '/' ); }
require_once dirname( __DIR__ ) . '/includes/class-autoloader.php';
\Amplifi\Schema\Autoloader::register();
```

Run `composer install` in `plugins/ac-schema/`.

- [ ] **Step 2: Write the failing test.** `tests/Schema/RegistryTest.php`:

```php
<?php
declare(strict_types=1);
namespace Amplifi\Schema\Tests\Schema;

use PHPUnit\Framework\TestCase;
use Amplifi\Schema\Schema\Registry;

final class RegistryTest extends TestCase {
    public function test_knows_article_type(): void {
        $r = new Registry();
        $this->assertTrue( $r->has_type( 'Article' ) );
    }

    public function test_returns_properties_for_article(): void {
        $r = new Registry();
        $props = $r->properties_for( 'Article' );
        $this->assertContains( 'headline', $props );
        $this->assertContains( 'author', $props );
    }

    public function test_required_for_rich_results_article(): void {
        $r = new Registry();
        $req = $r->required_for_rich_results( 'Article' );
        $this->assertEqualsCanonicalizing(
            [ 'headline', 'author', 'datePublished', 'image' ],
            $req
        );
    }

    public function test_unknown_type_returns_empty(): void {
        $r = new Registry();
        $this->assertFalse( $r->has_type( 'Nonsense123' ) );
        $this->assertSame( [], $r->properties_for( 'Nonsense123' ) );
    }
}
```

Run: `vendor/bin/phpunit tests/Schema/RegistryTest.php`. Expected: FAIL — class not found.

- [ ] **Step 3: Implement Registry.** `class-registry.php`:

```php
<?php
declare(strict_types=1);
namespace Amplifi\Schema\Schema;
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Registry {
    private array $index;

    public function __construct( ?string $index_path = null ) {
        $path  = $index_path ?? AMPLIFI_SCHEMA_PATH . 'includes/schema/data/schema-org-types.json';
        $json  = is_file( $path ) ? (string) file_get_contents( $path ) : '{}';
        $this->index = json_decode( $json, true ) ?: [];
    }

    public function has_type( string $type ): bool {
        return isset( $this->index[ $type ] );
    }

    public function properties_for( string $type ): array {
        return $this->index[ $type ]['properties'] ?? [];
    }

    public function required_for_rich_results( string $type ): array {
        return $this->index[ $type ]['required_for_rich_results'] ?? [];
    }

    public function all_types(): array {
        return array_keys( $this->index );
    }
}
```

- [ ] **Step 4: Run tests.** Expected: 4 PASS.

- [ ] **Step 5: Commit.**

```bash
git add plugins/ac-schema/composer.json plugins/ac-schema/phpunit.xml.dist plugins/ac-schema/tests/ plugins/ac-schema/includes/schema/class-registry.php
git commit -m "feat(ac-schema): Registry over bundled schema.org index + tests"
```

### Task 2.3: Validator

**Files:**
- Create: `plugins/ac-schema/includes/schema/class-validator.php`
- Create: `plugins/ac-schema/tests/Schema/ValidatorTest.php`

- [ ] **Step 1: Write failing tests** covering each validation rule:

```php
<?php
declare(strict_types=1);
namespace Amplifi\Schema\Tests\Schema;

use PHPUnit\Framework\TestCase;
use Amplifi\Schema\Schema\Registry;
use Amplifi\Schema\Schema\Validator;

final class ValidatorTest extends TestCase {
    private Validator $v;
    protected function setUp(): void {
        $this->v = new Validator( new Registry() );
    }

    public function test_invalid_json_returns_error(): void {
        $r = $this->v->validate( '{ not json' );
        $this->assertFalse( $r['ok'] );
        $this->assertSame( 'invalid_json', $r['errors'][0]['code'] );
    }

    public function test_missing_context_errors(): void {
        $r = $this->v->validate( json_encode( [ '@type' => 'Article' ] ) );
        $this->assertFalse( $r['ok'] );
        $codes = array_column( $r['errors'], 'code' );
        $this->assertContains( 'missing_context', $codes );
    }

    public function test_unknown_type_errors(): void {
        $r = $this->v->validate( json_encode( [
            '@context' => 'https://schema.org',
            '@type'    => 'Nonsense123',
        ] ) );
        $codes = array_column( $r['errors'], 'code' );
        $this->assertContains( 'unknown_type', $codes );
    }

    public function test_unknown_property_warns(): void {
        $r = $this->v->validate( json_encode( [
            '@context'    => 'https://schema.org',
            '@type'       => 'Article',
            'headline'    => 'Hi',
            'author'      => 'Me',
            'datePublished' => '2026-01-01',
            'image'       => 'https://x/y.jpg',
            'nonsenseProp' => 'x',
        ] ) );
        $codes = array_column( $r['errors'], 'code' );
        $this->assertContains( 'unknown_property', $codes );
    }

    public function test_required_for_rich_results_missing(): void {
        $r = $this->v->validate( json_encode( [
            '@context' => 'https://schema.org',
            '@type'    => 'Article',
            'headline' => 'Hi',
            // author, datePublished, image missing
        ] ) );
        $codes = array_column( $r['errors'], 'code' );
        $this->assertContains( 'missing_required_for_rich_results', $codes );
    }

    public function test_valid_article_passes(): void {
        $r = $this->v->validate( json_encode( [
            '@context'      => 'https://schema.org',
            '@type'         => 'Article',
            'headline'      => 'Hi',
            'author'        => [ '@type' => 'Person', 'name' => 'Me' ],
            'datePublished' => '2026-01-01',
            'image'         => 'https://x/y.jpg',
        ] ) );
        $this->assertTrue( $r['ok'] );
        $this->assertSame( [], $r['errors'] );
    }
}
```

Run: FAIL — class not found.

- [ ] **Step 2: Implement Validator.** `class-validator.php`:

```php
<?php
declare(strict_types=1);
namespace Amplifi\Schema\Schema;
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Validator {
    private const CONTEXT_OK = [ 'https://schema.org', 'http://schema.org' ];

    public function __construct( private Registry $registry ) {}

    public function validate( string $json_ld ): array {
        $errors = [];
        $data   = json_decode( $json_ld, true );
        if ( null === $data && JSON_ERROR_NONE !== json_last_error() ) {
            return [ 'ok' => false, 'errors' => [ [
                'path' => '$', 'code' => 'invalid_json', 'message' => json_last_error_msg(),
            ] ] ];
        }
        if ( ! is_array( $data ) ) {
            return [ 'ok' => false, 'errors' => [ [
                'path' => '$', 'code' => 'not_object', 'message' => 'JSON-LD must be an object.',
            ] ] ];
        }

        $context = $data['@context'] ?? null;
        if ( ! is_string( $context ) || ! in_array( rtrim( $context, '/' ), self::CONTEXT_OK, true ) ) {
            $errors[] = [ 'path' => '$.@context', 'code' => 'missing_context', 'message' => '@context must be https://schema.org' ];
        }

        $type = $data['@type'] ?? null;
        if ( ! is_string( $type ) ) {
            $errors[] = [ 'path' => '$.@type', 'code' => 'missing_type', 'message' => '@type is required' ];
            return [ 'ok' => false, 'errors' => $errors ];
        }
        if ( ! $this->registry->has_type( $type ) ) {
            $errors[] = [ 'path' => '$.@type', 'code' => 'unknown_type', 'message' => "Unknown @type: $type" ];
            return [ 'ok' => false, 'errors' => $errors ];
        }

        $allowed_props = array_flip( array_merge(
            $this->registry->properties_for( $type ),
            [ '@context', '@type', '@id', '@graph' ]
        ) );
        foreach ( array_keys( $data ) as $prop ) {
            if ( ! isset( $allowed_props[ $prop ] ) ) {
                $errors[] = [ 'path' => '$.' . $prop, 'code' => 'unknown_property', 'message' => "Unknown property '$prop' for $type" ];
            }
        }

        $required = $this->registry->required_for_rich_results( $type );
        $missing  = array_values( array_filter( $required, static fn( $p ) => ! array_key_exists( $p, $data ) ) );
        if ( $missing ) {
            $errors[] = [
                'path' => '$', 'code' => 'missing_required_for_rich_results',
                'message' => 'Missing for rich results: ' . implode( ', ', $missing ),
            ];
        }

        return [ 'ok' => empty( $errors ), 'errors' => $errors ];
    }
}
```

- [ ] **Step 3: Run tests.** Expected: 6 PASS.

- [ ] **Step 4: Commit.**

```bash
git add plugins/ac-schema/includes/schema/class-validator.php plugins/ac-schema/tests/Schema/ValidatorTest.php
git commit -m "feat(ac-schema): JSON-LD Validator with tests"
```

---

## Phase 3 — AI integration

Goal: call Anthropic Messages API with strict JSON output, track spend with caps, encrypt the API key at rest.

### Task 3.1: Secret_Store (port from amplifi-security)

**Files:**
- Create: `plugins/ac-schema/includes/crypto/class-secret-store.php`
- Create: `plugins/ac-schema/tests/Crypto/SecretStoreTest.php`

- [ ] **Step 1: Copy & adapt.** Copy `plugins/amplifi-security/includes/crypto/class-secret-store.php` to the new path. Edit: rewrite namespace to `Amplifi\Schema\Crypto`, change option-key prefix to `ac_schema_secret_`, keep encryption logic identical. Replace `AMPLIFI_SECURITY_VERSION` mentions if any.

- [ ] **Step 2: Write a round-trip test** (mocking WP options if needed via in-memory shim — or skip the test here and rely on the security plugin's own coverage; do a manual round-trip in a temp script). Pragmatic choice: add a minimal `tests/Crypto/SecretStoreTest.php` that just exercises the encryption helpers in isolation (skip the WP option side).

```php
<?php
declare(strict_types=1);
namespace Amplifi\Schema\Tests\Crypto;

use PHPUnit\Framework\TestCase;
use Amplifi\Schema\Crypto\Secret_Store;

final class SecretStoreTest extends TestCase {
    public function test_encrypt_decrypt_roundtrip(): void {
        // Define WP key constants the store needs.
        if ( ! defined( 'AUTH_KEY' ) ) { define( 'AUTH_KEY', str_repeat( 'a', 64 ) ); }
        if ( ! defined( 'SECURE_AUTH_KEY' ) ) { define( 'SECURE_AUTH_KEY', str_repeat( 'b', 64 ) ); }
        $cipher = Secret_Store::encrypt_for_test( 'sk-ant-abc123' );
        $this->assertNotSame( 'sk-ant-abc123', $cipher );
        $this->assertSame( 'sk-ant-abc123', Secret_Store::decrypt_for_test( $cipher ) );
    }
}
```

If the existing `Secret_Store` does not expose `encrypt_for_test`/`decrypt_for_test`, add them as `public static` thin wrappers around the private encrypt/decrypt methods. They are test-only and remain public on the class — that's an acceptable seam.

- [ ] **Step 3: Run tests.** Expected: PASS.

- [ ] **Step 4: Commit.**

```bash
git add plugins/ac-schema/includes/crypto/ plugins/ac-schema/tests/Crypto/
git commit -m "feat(ac-schema): AES-256-GCM Secret_Store for API keys"
```

### Task 3.2: Spend_Tracker

**Files:**
- Create: `plugins/ac-schema/includes/ai/class-spend-tracker.php`
- Create: `plugins/ac-schema/tests/AI/SpendTrackerTest.php`

- [ ] **Step 1: Failing tests.**

```php
<?php
declare(strict_types=1);
namespace Amplifi\Schema\Tests\AI;

use PHPUnit\Framework\TestCase;
use Amplifi\Schema\AI\Spend_Tracker;

final class SpendTrackerTest extends TestCase {
    public function test_cost_for_haiku(): void {
        $cost = Spend_Tracker::estimate_cost( 'claude-haiku-4-5-20251001', 1_000_000, 100_000 );
        // Haiku 4.5: $1/M input, $5/M output → $1.50
        $this->assertEqualsWithDelta( 1.5, $cost, 0.001 );
    }

    public function test_cost_for_sonnet(): void {
        $cost = Spend_Tracker::estimate_cost( 'claude-sonnet-4-6', 1_000_000, 100_000 );
        // Sonnet 4.6: $3/M input, $15/M output → $4.50
        $this->assertEqualsWithDelta( 4.5, $cost, 0.001 );
    }

    public function test_unknown_model_uses_sonnet_pricing(): void {
        $cost = Spend_Tracker::estimate_cost( 'unknown', 1_000_000, 0 );
        $this->assertGreaterThan( 0, $cost );
    }
}
```

- [ ] **Step 2: Implement Spend_Tracker.**

```php
<?php
declare(strict_types=1);
namespace Amplifi\Schema\AI;
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Spend_Tracker {
    /** Per-million-token USD pricing. */
    public const PRICING = [
        'claude-haiku-4-5-20251001' => [ 'in' => 1.00,  'out' => 5.00 ],
        'claude-sonnet-4-6'         => [ 'in' => 3.00,  'out' => 15.00 ],
        'claude-opus-4-7'           => [ 'in' => 15.00, 'out' => 75.00 ],
    ];

    public static function estimate_cost( string $model, int $input_tokens, int $output_tokens ): float {
        $price = self::PRICING[ $model ] ?? self::PRICING['claude-sonnet-4-6'];
        return ( $input_tokens / 1_000_000 ) * $price['in']
             + ( $output_tokens / 1_000_000 ) * $price['out'];
    }

    public static function record( string $model, int $input_tokens, int $output_tokens ): void {
        global $wpdb;
        $cost  = self::estimate_cost( $model, $input_tokens, $output_tokens );
        $day   = gmdate( 'Y-m-d' );
        $table = $wpdb->prefix . 'ac_schema_spend';
        $wpdb->query( $wpdb->prepare(
            "INSERT INTO {$table} (day, input_tokens, output_tokens, cost_usd)
             VALUES (%s, %d, %d, %f)
             ON DUPLICATE KEY UPDATE
               input_tokens = input_tokens + VALUES(input_tokens),
               output_tokens = output_tokens + VALUES(output_tokens),
               cost_usd = cost_usd + VALUES(cost_usd)",
            $day, $input_tokens, $output_tokens, $cost
        ) ); // phpcs:ignore
    }

    public static function spend_today_usd(): float {
        global $wpdb;
        $table = $wpdb->prefix . 'ac_schema_spend';
        $row   = $wpdb->get_var( $wpdb->prepare( "SELECT cost_usd FROM {$table} WHERE day = %s", gmdate( 'Y-m-d' ) ) ); // phpcs:ignore
        return (float) ( $row ?? 0.0 );
    }

    public static function spend_month_usd(): float {
        global $wpdb;
        $table = $wpdb->prefix . 'ac_schema_spend';
        $row   = $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(cost_usd),0) FROM {$table} WHERE day >= %s", gmdate( 'Y-m-01' ) ) ); // phpcs:ignore
        return (float) ( $row ?? 0.0 );
    }

    public static function can_spend( float $estimated_usd ): bool {
        $settings = get_option( 'ac_schema_settings', [] );
        $daily    = (float) ( $settings['daily_spend_cap_usd'] ?? 5.0 );
        $monthly  = (float) ( $settings['monthly_spend_cap_usd'] ?? 50.0 );
        return ( self::spend_today_usd() + $estimated_usd ) <= $daily
            && ( self::spend_month_usd() + $estimated_usd ) <= $monthly;
    }
}
```

- [ ] **Step 3: Run tests.** Expected: 3 PASS.

- [ ] **Step 4: Commit.**

```bash
git add plugins/ac-schema/includes/ai/class-spend-tracker.php plugins/ac-schema/tests/AI/SpendTrackerTest.php
git commit -m "feat(ac-schema): Spend_Tracker with per-model pricing"
```

### Task 3.3: Prompt_Builder

**Files:**
- Create: `plugins/ac-schema/includes/ai/class-prompt-builder.php`
- Create: `plugins/ac-schema/tests/AI/PromptBuilderTest.php`

- [ ] **Step 1: Failing tests.** Cover: trimming preserves head + tail of content; system prompt includes schema.org instruction; output payload includes URL, title, post_type.

```php
<?php
declare(strict_types=1);
namespace Amplifi\Schema\Tests\AI;

use PHPUnit\Framework\TestCase;
use Amplifi\Schema\AI\Prompt_Builder;

final class PromptBuilderTest extends TestCase {
    public function test_trim_to_token_budget_keeps_head_and_tail(): void {
        $long = str_repeat( 'word ', 10_000 );
        $trimmed = Prompt_Builder::trim_to_token_budget( $long, 100 );
        $this->assertLessThan( strlen( $long ), strlen( $trimmed ) );
        $this->assertStringStartsWith( 'word', $trimmed );
    }

    public function test_build_for_post_includes_required_context(): void {
        $msg = Prompt_Builder::build_for_post( [
            'title'     => 'Hello',
            'url'       => 'https://example.com/hello',
            'post_type' => 'post',
            'content'   => 'Body here.',
            'existing'  => null,
        ] );
        $this->assertStringContainsString( 'Hello', $msg['user'] );
        $this->assertStringContainsString( 'https://example.com/hello', $msg['user'] );
        $this->assertStringContainsString( 'post', $msg['user'] );
        $this->assertStringContainsString( 'schema.org', $msg['system'] );
    }
}
```

- [ ] **Step 2: Implement Prompt_Builder.**

```php
<?php
declare(strict_types=1);
namespace Amplifi\Schema\AI;
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Prompt_Builder {
    /** Rough heuristic: 1 token ~= 4 chars of English. */
    public static function trim_to_token_budget( string $text, int $budget_tokens ): string {
        $max_chars = $budget_tokens * 4;
        if ( strlen( $text ) <= $max_chars ) {
            return $text;
        }
        $head = substr( $text, 0, (int) ( $max_chars * 0.7 ) );
        $tail = substr( $text, -(int) ( $max_chars * 0.2 ) );
        return $head . "\n\n[...content truncated...]\n\n" . $tail;
    }

    public static function build_for_post( array $ctx, int $content_budget_tokens = 6000 ): array {
        $system = "You generate schema.org JSON-LD for web pages. Pick the most specific @type from schema.org "
                . "that fits the content. Return strictly valid JSON-LD that would pass Google Rich Results "
                . "validation. Use https://schema.org as @context. Do not invent properties.";
        $existing_note = $ctx['existing']
            ? "\nExisting schema (revise it):\n" . wp_json_encode( $ctx['existing'] )
            : '';
        $content = self::trim_to_token_budget( (string) ( $ctx['content'] ?? '' ), $content_budget_tokens );
        $user = "Title: {$ctx['title']}\nURL: {$ctx['url']}\nPost type: {$ctx['post_type']}\n\nContent:\n{$content}{$existing_note}";
        return [ 'system' => $system, 'user' => $user ];
    }

    public static function build_for_global( string $key, array $site_ctx ): array {
        $system = "You generate schema.org JSON-LD describing the site itself. Return strictly valid JSON-LD.";
        $type = match ( $key ) {
            'organization'  => 'Organization',
            'website'       => 'WebSite',
            'localbusiness' => 'LocalBusiness',
            default         => 'Thing',
        };
        $user = "Generate a $type JSON-LD entity for this site:\n" . wp_json_encode( $site_ctx );
        return [ 'system' => $system, 'user' => $user ];
    }
}
```

- [ ] **Step 3: Run tests.** Expected: 2 PASS.

- [ ] **Step 4: Commit.**

```bash
git add plugins/ac-schema/includes/ai/class-prompt-builder.php plugins/ac-schema/tests/AI/PromptBuilderTest.php
git commit -m "feat(ac-schema): Prompt_Builder for post + global schema generation"
```

### Task 3.4: Anthropic_Client

**Files:**
- Create: `plugins/ac-schema/includes/ai/class-anthropic-client.php`
- Create: `plugins/ac-schema/tests/AI/AnthropicClientTest.php`

- [ ] **Step 1: Failing test using a fake HTTP transport.** Design the client so its HTTP call is injectable.

```php
<?php
declare(strict_types=1);
namespace Amplifi\Schema\Tests\AI;

use PHPUnit\Framework\TestCase;
use Amplifi\Schema\AI\Anthropic_Client;

final class AnthropicClientTest extends TestCase {
    public function test_generate_json_ld_returns_parsed_payload(): void {
        $fake = function ( array $req ) {
            $payload = [
                'content' => [ [
                    'type' => 'tool_use',
                    'name' => 'emit_jsonld',
                    'input' => [
                        '@context' => 'https://schema.org',
                        '@type'    => 'Article',
                        'headline' => 'Hi',
                    ],
                ] ],
                'usage' => [ 'input_tokens' => 100, 'output_tokens' => 50 ],
            ];
            return [ 'ok' => true, 'body' => $payload ];
        };
        $client = new Anthropic_Client( 'sk-test', 'claude-haiku-4-5-20251001', $fake );
        $r = $client->generate_jsonld( 'sys', 'user' );
        $this->assertSame( 'Article', $r['jsonld']['@type'] );
        $this->assertSame( 100, $r['input_tokens'] );
        $this->assertSame( 50, $r['output_tokens'] );
    }

    public function test_error_response_returns_error_shape(): void {
        $fake = fn( array $req ) => [ 'ok' => false, 'error' => 'boom' ];
        $client = new Anthropic_Client( 'sk-test', 'claude-haiku-4-5-20251001', $fake );
        $r = $client->generate_jsonld( 'sys', 'user' );
        $this->assertArrayHasKey( 'error', $r );
    }
}
```

- [ ] **Step 2: Implement Anthropic_Client** using tool-use to force JSON output:

```php
<?php
declare(strict_types=1);
namespace Amplifi\Schema\AI;
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Anthropic_Client {
    private const API_URL = 'https://api.anthropic.com/v1/messages';
    private const TOOL_NAME = 'emit_jsonld';

    /** @var callable */
    private $transport;

    public function __construct(
        private string $api_key,
        private string $model,
        ?callable $transport = null
    ) {
        $this->transport = $transport ?? [ $this, 'default_transport' ];
    }

    public function generate_jsonld( string $system, string $user ): array {
        $req = [
            'model'      => $this->model,
            'max_tokens' => 2048,
            'system'     => $system,
            'tools'      => [ [
                'name'        => self::TOOL_NAME,
                'description' => 'Emit a single JSON-LD object describing the page.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => (object) [],
                    'additionalProperties' => true,
                ],
            ] ],
            'tool_choice' => [ 'type' => 'tool', 'name' => self::TOOL_NAME ],
            'messages'    => [ [ 'role' => 'user', 'content' => $user ] ],
        ];
        $resp = ( $this->transport )( $req );
        if ( empty( $resp['ok'] ) ) {
            return [ 'error' => $resp['error'] ?? 'transport_failed' ];
        }
        $body = $resp['body'];
        $tool_use = null;
        foreach ( $body['content'] ?? [] as $block ) {
            if ( ( $block['type'] ?? '' ) === 'tool_use' && ( $block['name'] ?? '' ) === self::TOOL_NAME ) {
                $tool_use = $block;
                break;
            }
        }
        if ( ! $tool_use ) {
            return [ 'error' => 'no_tool_use_block' ];
        }
        return [
            'jsonld'        => $tool_use['input'],
            'input_tokens'  => (int) ( $body['usage']['input_tokens']  ?? 0 ),
            'output_tokens' => (int) ( $body['usage']['output_tokens'] ?? 0 ),
        ];
    }

    private function default_transport( array $req ): array {
        $r = wp_remote_post( self::API_URL, [
            'timeout' => 60,
            'headers' => [
                'x-api-key'         => $this->api_key,
                'anthropic-version' => '2023-06-01',
                'content-type'      => 'application/json',
            ],
            'body' => wp_json_encode( $req ),
        ] );
        if ( is_wp_error( $r ) ) {
            return [ 'ok' => false, 'error' => $r->get_error_message() ];
        }
        $code = wp_remote_retrieve_response_code( $r );
        $body = json_decode( (string) wp_remote_retrieve_body( $r ), true );
        if ( $code < 200 || $code >= 300 ) {
            return [ 'ok' => false, 'error' => $body['error']['message'] ?? "http_$code" ];
        }
        return [ 'ok' => true, 'body' => $body ];
    }
}
```

- [ ] **Step 3: Run tests.** Expected: 2 PASS.

- [ ] **Step 4: Commit.**

```bash
git add plugins/ac-schema/includes/ai/class-anthropic-client.php plugins/ac-schema/tests/AI/AnthropicClientTest.php
git commit -m "feat(ac-schema): Anthropic_Client with tool-use forced JSON output"
```

---

## Phase 4 — Storage layer

Goal: typed read/write helpers for the `entries` table so REST and queue don't write raw SQL.

### Task 4.1: Entry_Store

**Files:**
- Create: `plugins/ac-schema/includes/data/class-entry-store.php`
- Create: `plugins/ac-schema/tests/Data/EntryStoreTest.php` (integration test — requires DB. Skip the test if `WORDPRESS_DB_HOST` env not set.)

- [ ] **Step 1: Decide on integration test strategy.** Two paths:
  - **A:** Run PHPUnit inside the docker container against the real WP DB. Add `plugins/ac-schema/scripts/run-tests-in-docker.sh` that execs `docker-compose exec wp_ac_schema wp eval-file ...` or runs phpunit there.
  - **B:** Skip DB-touching tests; rely on smoke testing via REST.
  - **Choose A.** Write the helper:

```bash
#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
docker-compose exec -T wordpress sh -lc 'cd /var/www/html/wp-content/plugins/ac-schema && vendor/bin/phpunit "$@"' "$@"
```

`chmod +x scripts/run-tests-in-docker.sh`.

- [ ] **Step 2: Write Entry_Store interface + failing test.**

```php
<?php
declare(strict_types=1);
namespace Amplifi\Schema\Tests\Data;

use PHPUnit\Framework\TestCase;
use Amplifi\Schema\Data\Entry_Store;

final class EntryStoreTest extends TestCase {
    protected function setUp(): void {
        if ( ! defined( 'DB_NAME' ) ) {
            $this->markTestSkipped( 'Requires WP DB.' );
        }
        global $wpdb;
        $wpdb->query( "DELETE FROM {$wpdb->prefix}ac_schema_entries" );
    }

    public function test_save_and_fetch_post_entry(): void {
        $store = new Entry_Store();
        $id = $store->save( [
            'scope_type' => 'post',
            'scope_id'   => '42',
            'schema_type'=> 'Article',
            'source'     => 'ai',
            'json_ld'    => '{"@type":"Article"}',
        ] );
        $this->assertIsInt( $id );
        $row = $store->find_one( 'post', '42', 'Article' );
        $this->assertSame( '{"@type":"Article"}', $row['json_ld'] );
    }

    public function test_save_upserts_by_unique_key(): void {
        $store = new Entry_Store();
        $id1 = $store->save( [
            'scope_type'=>'post', 'scope_id'=>'42',
            'schema_type'=>'Article', 'source'=>'ai',
            'json_ld'=>'{"v":1}',
        ] );
        $id2 = $store->save( [
            'scope_type'=>'post', 'scope_id'=>'42',
            'schema_type'=>'Article', 'source'=>'manual',
            'json_ld'=>'{"v":2}',
        ] );
        $this->assertSame( $id1, $id2 );
        $this->assertSame( '{"v":2}', $store->find_one( 'post', '42', 'Article' )['json_ld'] );
    }

    public function test_find_all_for_scope_returns_multiple_types(): void {
        $store = new Entry_Store();
        $store->save( [ 'scope_type'=>'post', 'scope_id'=>'42', 'schema_type'=>'Article', 'source'=>'ai', 'json_ld'=>'{}' ] );
        $store->save( [ 'scope_type'=>'post', 'scope_id'=>'42', 'schema_type'=>'BreadcrumbList', 'source'=>'ai', 'json_ld'=>'{}' ] );
        $rows = $store->find_all_for_scope( 'post', '42' );
        $this->assertCount( 2, $rows );
    }
}
```

- [ ] **Step 3: Implement Entry_Store.**

```php
<?php
declare(strict_types=1);
namespace Amplifi\Schema\Data;
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Entry_Store {
    private string $table;

    public function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . 'ac_schema_entries';
    }

    public function save( array $row ): int {
        global $wpdb;
        $json = (string) $row['json_ld'];
        $hash = hash( 'sha256', $json );
        $now  = gmdate( 'Y-m-d H:i:s' );
        $wpdb->query( $wpdb->prepare(
            "INSERT INTO {$this->table} (scope_type, scope_id, schema_type, source, json_ld, hash, updated_at)
             VALUES (%s,%s,%s,%s,%s,%s,%s)
             ON DUPLICATE KEY UPDATE source=VALUES(source), json_ld=VALUES(json_ld), hash=VALUES(hash), updated_at=VALUES(updated_at)",
            $row['scope_type'], (string) $row['scope_id'], $row['schema_type'], $row['source'], $json, $hash, $now
        ) ); // phpcs:ignore
        return (int) $wpdb->insert_id ?: (int) $this->find_id( $row['scope_type'], (string) $row['scope_id'], $row['schema_type'] );
    }

    public function find_id( string $scope_type, string $scope_id, string $schema_type ): ?int {
        global $wpdb;
        $id = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$this->table} WHERE scope_type=%s AND scope_id=%s AND schema_type=%s",
            $scope_type, $scope_id, $schema_type
        ) ); // phpcs:ignore
        return $id ? (int) $id : null;
    }

    public function find_one( string $scope_type, string $scope_id, string $schema_type ): ?array {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE scope_type=%s AND scope_id=%s AND schema_type=%s",
            $scope_type, $scope_id, $schema_type
        ), ARRAY_A ); // phpcs:ignore
        return $row ?: null;
    }

    public function find_all_for_scope( string $scope_type, string $scope_id ): array {
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE scope_type=%s AND scope_id=%s ORDER BY schema_type",
            $scope_type, $scope_id
        ), ARRAY_A ) ?: []; // phpcs:ignore
    }

    public function delete( int $id ): bool {
        global $wpdb;
        return (bool) $wpdb->delete( $this->table, [ 'id' => $id ], [ '%d' ] );
    }
}
```

- [ ] **Step 4: Run tests in docker.** `./scripts/run-tests-in-docker.sh tests/Data/EntryStoreTest.php`. Expected: 3 PASS.

- [ ] **Step 5: Commit.**

```bash
git add plugins/ac-schema/includes/data/class-entry-store.php plugins/ac-schema/tests/Data/EntryStoreTest.php plugins/ac-schema/scripts/run-tests-in-docker.sh
git commit -m "feat(ac-schema): Entry_Store with upsert by unique key"
```

### Task 4.2: Job_Store

**Files:**
- Create: `plugins/ac-schema/includes/queue/class-job-store.php`
- Create: `plugins/ac-schema/tests/Queue/JobStoreTest.php`

- [ ] **Step 1: Failing test.** Cover: create returns ID; update status persists; find pending returns rows; increment_processed atomically updates count and cost.

- [ ] **Step 2: Implement** following the same wpdb pattern as Entry_Store. Methods: `create( array $scope, string $model, int $total ): int`, `set_status( int $id, string $status ): void`, `record_progress( int $id, int $delta_processed, int $delta_failed, float $delta_cost ): void`, `find( int $id ): ?array`, `find_active(): ?array`, `list_recent( int $limit ): array`.

- [ ] **Step 3: Run + commit.**

```bash
git add plugins/ac-schema/includes/queue/class-job-store.php plugins/ac-schema/tests/Queue/JobStoreTest.php
git commit -m "feat(ac-schema): Job_Store"
```

---

## Phase 5 — Graph builder + head output

Goal: front-end emits one `<script type="application/ld+json">` per request.

### Task 5.1: Graph_Builder

**Files:**
- Create: `plugins/ac-schema/includes/schema/class-graph-builder.php`
- Create: `plugins/ac-schema/tests/Schema/GraphBuilderTest.php`

- [ ] **Step 1: Failing tests** — feed in a fake Entry_Store via constructor injection. Verify: returns `@graph` array; includes global org+website always; includes per-post entries when post context provided; resolves `author` text → Person `@id` reference.

```php
public function test_builds_graph_with_global_and_post_entries(): void {
    $fake_store = new class {
        public function find_all_for_scope( string $type, string $id ): array {
            if ( $type === 'global' && $id === 'organization' ) {
                return [ [ 'json_ld' => '{"@context":"https://schema.org","@type":"Organization","name":"Acme"}', 'schema_type' => 'Organization' ] ];
            }
            if ( $type === 'global' && $id === 'website' ) {
                return [ [ 'json_ld' => '{"@context":"https://schema.org","@type":"WebSite","name":"Acme Blog"}', 'schema_type' => 'WebSite' ] ];
            }
            if ( $type === 'post' && $id === '42' ) {
                return [ [ 'json_ld' => '{"@context":"https://schema.org","@type":"Article","headline":"Hi"}', 'schema_type' => 'Article' ] ];
            }
            return [];
        }
    };
    $gb = new Graph_Builder( $fake_store );
    $graph = $gb->build( [ 'post_id' => 42, 'url_rules' => [] ] );
    $this->assertSame( 'https://schema.org', $graph['@context'] );
    $types = array_column( $graph['@graph'], '@type' );
    $this->assertSame( [ 'Organization', 'WebSite', 'Article' ], $types );
}
```

- [ ] **Step 2: Implement Graph_Builder.**

```php
<?php
declare(strict_types=1);
namespace Amplifi\Schema\Schema;
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Graph_Builder {
    public function __construct( private object $store ) {}

    public function build( array $ctx ): array {
        $graph = [];
        foreach ( [ 'organization', 'website', 'localbusiness' ] as $key ) {
            foreach ( $this->store->find_all_for_scope( 'global', $key ) as $row ) {
                $graph[] = $this->decode( $row['json_ld'] );
            }
        }
        foreach ( $ctx['url_rules'] ?? [] as $rule_id ) {
            foreach ( $this->store->find_all_for_scope( 'url_rule', (string) $rule_id ) as $row ) {
                $graph[] = $this->decode( $row['json_ld'] );
            }
        }
        if ( ! empty( $ctx['post_id'] ) ) {
            foreach ( $this->store->find_all_for_scope( 'post', (string) $ctx['post_id'] ) as $row ) {
                $graph[] = $this->decode( $row['json_ld'] );
            }
        }
        $graph = array_values( array_filter( $graph ) );
        $graph = $this->strip_inner_contexts( $graph );
        return [ '@context' => 'https://schema.org', '@graph' => $graph ];
    }

    private function decode( string $json ): ?array {
        $d = json_decode( $json, true );
        return is_array( $d ) ? $d : null;
    }

    private function strip_inner_contexts( array $items ): array {
        foreach ( $items as &$item ) {
            unset( $item['@context'] );
        }
        return $items;
    }
}
```

- [ ] **Step 3: Run + commit.**

```bash
git add plugins/ac-schema/includes/schema/class-graph-builder.php plugins/ac-schema/tests/Schema/GraphBuilderTest.php
git commit -m "feat(ac-schema): Graph_Builder merges global + URL-rule + per-post"
```

### Task 5.2: Head_Output

**Files:**
- Create: `plugins/ac-schema/includes/frontend/class-head-output.php`
- Modify: `plugins/ac-schema/includes/class-plugin.php`

- [ ] **Step 1: Implement Head_Output.**

```php
<?php
declare(strict_types=1);
namespace Amplifi\Schema\Frontend;

use Amplifi\Schema\Data\Entry_Store;
use Amplifi\Schema\Schema\Graph_Builder;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Head_Output {
    public function register(): void {
        $settings = get_option( 'ac_schema_settings', [] );
        $priority = (int) ( $settings['output_priority'] ?? 1 );
        add_action( 'wp_head', [ $this, 'emit' ], $priority );
    }

    public function emit(): void {
        $ctx = [
            'post_id'   => is_singular() ? get_queried_object_id() : 0,
            'url_rules' => $this->match_url_rules( $this->current_url() ),
        ];
        $gb    = new Graph_Builder( new Entry_Store() );
        $graph = $gb->build( $ctx );
        if ( empty( $graph['@graph'] ) ) { return; }

        echo "\n<script type=\"application/ld+json\" id=\"amplifi-schema\">";
        echo wp_json_encode( $graph, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_PRETTY_PRINT );
        echo "</script>\n";
    }

    private function current_url(): string {
        $scheme = is_ssl() ? 'https' : 'http';
        $host   = $_SERVER['HTTP_HOST'] ?? '';
        $uri    = $_SERVER['REQUEST_URI'] ?? '/';
        return $scheme . '://' . $host . $uri;
    }

    private function match_url_rules( string $url ): array {
        $rules   = get_option( 'ac_schema_url_rules', [] );
        $matched = [];
        $path    = (string) wp_parse_url( $url, PHP_URL_PATH );
        foreach ( $rules as $rule ) {
            $pattern = $rule['pattern'] ?? '';
            $match_type = $rule['match_type'] ?? 'glob';
            $hit = $match_type === 'regex'
                ? @preg_match( $pattern, $path ) === 1
                : fnmatch( $pattern, $path );
            if ( $hit ) { $matched[] = $rule['id'] ?? ''; }
        }
        return array_values( array_filter( $matched ) );
    }
}
```

- [ ] **Step 2: Wire into Plugin::boot().**

```php
( new Frontend\Head_Output() )->register();
```

- [ ] **Step 3: Manual smoke test.** Insert a row directly:

```sql
INSERT INTO wp_ac_schema_entries (scope_type, scope_id, schema_type, source, json_ld, hash, updated_at)
VALUES ('global', 'organization', 'Organization', 'manual',
        '{"@context":"https://schema.org","@type":"Organization","name":"Smoke Test"}',
        REPEAT('a', 64), NOW());
```

Visit homepage → view source → confirm `<script type="application/ld+json" id="amplifi-schema">` with the org block.

- [ ] **Step 4: Commit.**

```bash
git add plugins/ac-schema/includes/frontend/class-head-output.php plugins/ac-schema/includes/class-plugin.php
git commit -m "feat(ac-schema): emit single @graph block in wp_head"
```

---

## Phase 6 — Detector + Foreign_Suppressor

### Task 6.1: Detector

**Files:**
- Create: `plugins/ac-schema/includes/schema/class-detector.php`
- Create: `plugins/ac-schema/tests/Schema/DetectorTest.php`
- Create: `plugins/ac-schema/tests/fixtures/yoast-head.html`
- Create: `plugins/ac-schema/tests/fixtures/rankmath-head.html`
- Create: `plugins/ac-schema/tests/fixtures/seopress-head.html`
- Create: `plugins/ac-schema/tests/fixtures/aioseo-head.html`
- Create: `plugins/ac-schema/tests/fixtures/manual-head.html`

- [ ] **Step 1: Save real-world HTML fixtures.** Collect from live sites or generated demo installs. Each fixture must contain `<head>` with at least one `<script type="application/ld+json">` block from that source.

- [ ] **Step 2: Failing tests** — `Detector::parse_html()` should be pure (no HTTP). It accepts an HTML string and returns the list.

```php
public function test_detects_yoast(): void {
    $html = file_get_contents( __DIR__ . '/../fixtures/yoast-head.html' );
    $d = new Detector();
    $found = $d->parse_html( $html );
    $sources = array_column( $found, 'source' );
    $this->assertContains( 'yoast', $sources );
}
// same for rankmath, seopress, aioseo, and one 'unknown' case
```

- [ ] **Step 3: Implement Detector.**

```php
<?php
declare(strict_types=1);
namespace Amplifi\Schema\Schema;
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Detector {
    public function detect_for_url( string $url ): array {
        $cache_key = 'ac_schema_detected_' . md5( $url );
        $cached = get_transient( $cache_key );
        if ( false !== $cached ) { return $cached; }

        $r = wp_remote_get( $url, [
            'timeout'    => 5,
            'redirection'=> 3,
            'user-agent' => 'amplifi.schema-detector/1.0',
        ] );
        if ( is_wp_error( $r ) ) { return []; }
        $body = (string) wp_remote_retrieve_body( $r );
        if ( strlen( $body ) > 5_000_000 ) { $body = substr( $body, 0, 5_000_000 ); }
        $found = $this->parse_html( $body );
        set_transient( $cache_key, $found, HOUR_IN_SECONDS );
        return $found;
    }

    public function parse_html( string $html ): array {
        $found = [];
        if ( ! preg_match_all( '#<script\s+[^>]*type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#is', $html, $m, PREG_SET_ORDER ) ) {
            return $found;
        }
        foreach ( $m as $match ) {
            $tag  = $match[0];
            $json = trim( html_entity_decode( $match[1], ENT_QUOTES, 'UTF-8' ) );
            $data = json_decode( $json, true );
            if ( ! is_array( $data ) ) { continue; }
            $source = $this->guess_source( $tag );
            foreach ( $this->expand_graph( $data ) as $entity ) {
                $type = $entity['@type'] ?? 'Unknown';
                $found[] = [
                    'source'      => $source,
                    'schema_type' => is_array( $type ) ? implode( ',', $type ) : (string) $type,
                    'json_string' => wp_json_encode( $entity ),
                ];
            }
        }
        return $found;
    }

    private function expand_graph( array $data ): array {
        if ( isset( $data['@graph'] ) && is_array( $data['@graph'] ) ) {
            return $data['@graph'];
        }
        return [ $data ];
    }

    private function guess_source( string $script_tag ): string {
        if ( stripos( $script_tag, 'yoast-schema-graph' ) !== false ) { return 'yoast'; }
        if ( stripos( $script_tag, 'rank-math' ) !== false || stripos( $script_tag, 'rank_math' ) !== false ) { return 'rankmath'; }
        if ( stripos( $script_tag, 'seopress' ) !== false ) { return 'seopress'; }
        if ( stripos( $script_tag, 'aioseo' ) !== false ) { return 'aioseo'; }
        if ( stripos( $script_tag, 'amplifi-schema' ) !== false ) { return 'amplifi-schema'; }
        if ( stripos( $script_tag, 'ac-jsonld-data' ) !== false ) { return 'amplifi-meta'; }
        return 'unknown';
    }
}
```

- [ ] **Step 4: Run tests** — make sure each fixture is correctly classified. PASS.

- [ ] **Step 5: Commit.**

```bash
git add plugins/ac-schema/includes/schema/class-detector.php plugins/ac-schema/tests/Schema/DetectorTest.php plugins/ac-schema/tests/fixtures/
git commit -m "feat(ac-schema): Detector parses foreign JSON-LD from <head>"
```

### Task 6.2: Foreign_Suppressor

**Files:**
- Create: `plugins/ac-schema/includes/frontend/class-foreign-suppressor.php`
- Modify: `plugins/ac-schema/includes/class-plugin.php`

- [ ] **Step 1: Implement filter-based suppression.**

```php
<?php
declare(strict_types=1);
namespace Amplifi\Schema\Frontend;
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Foreign_Suppressor {
    public function register(): void {
        add_filter( 'wpseo_schema_graph', [ $this, 'filter_yoast_graph' ], 99, 2 );
        add_filter( 'rank_math/json_ld', [ $this, 'filter_rankmath' ], 99, 2 );
        add_filter( 'seopress_pro_schemas_json', [ $this, 'filter_seopress' ], 99 );
        add_filter( 'aioseo_schema_output', [ $this, 'filter_aioseo' ], 99 );
        // amplifi.meta deferral happens in that plugin's own check for AMPLIFI_SCHEMA_ACTIVE.
    }

    private function overridden_types(): array {
        if ( ! is_singular() ) { return []; }
        $list = get_post_meta( get_queried_object_id(), '_ac_schema_overrides', true );
        return is_array( $list ) ? $list : [];
    }

    public function filter_yoast_graph( $graph, $context = null ) {
        $kill = $this->overridden_types();
        if ( ! $kill || ! is_array( $graph ) ) { return $graph; }
        return array_values( array_filter( $graph, function ( $piece ) use ( $kill ) {
            $t = $piece['@type'] ?? '';
            $t = is_array( $t ) ? $t : [ $t ];
            return ! array_intersect( $t, $kill );
        } ) );
    }

    public function filter_rankmath( $data ) {
        $kill = $this->overridden_types();
        if ( ! $kill || ! is_array( $data ) ) { return $data; }
        foreach ( $kill as $type ) { unset( $data[ $type ] ); }
        return $data;
    }

    public function filter_seopress( $schemas ) {
        $kill = $this->overridden_types();
        if ( ! $kill || ! is_array( $schemas ) ) { return $schemas; }
        return array_values( array_filter( $schemas, function ( $s ) use ( $kill ) {
            $t = $s['@type'] ?? '';
            return ! in_array( $t, $kill, true );
        } ) );
    }

    public function filter_aioseo( $output ) {
        // AIOSEO's output is a string; if any kill types overlap, replace the script tag with empty.
        $kill = $this->overridden_types();
        if ( ! $kill || ! is_string( $output ) ) { return $output; }
        foreach ( $kill as $type ) {
            $output = preg_replace( '#<script[^>]*application/ld\+json[^>]*>[^<]*"@type":"' . preg_quote( $type, '#' ) . '"[^<]*</script>#is', '', $output );
        }
        return (string) $output;
    }
}
```

- [ ] **Step 2: Wire into Plugin::boot().** `( new Frontend\Foreign_Suppressor() )->register();`

- [ ] **Step 3: Commit.**

```bash
git add plugins/ac-schema/includes/frontend/class-foreign-suppressor.php plugins/ac-schema/includes/class-plugin.php
git commit -m "feat(ac-schema): Foreign_Suppressor for Yoast/Rank Math/SEOPress/AIOSEO"
```

---

## Phase 7 — REST API

Goal: every UI operation goes through REST. No admin-ajax.

### Task 7.1: REST controller scaffold + permission check

**Files:**
- Create: `plugins/ac-schema/includes/rest/class-rest-controller.php`
- Modify: `plugins/ac-schema/includes/class-plugin.php`

- [ ] **Step 1: Implement controller** that registers all routes in `register()`. Use one method per route:

```php
<?php
declare(strict_types=1);
namespace Amplifi\Schema\Rest;
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Rest_Controller {
    public const NS = 'amplifi-schema/v1';

    public function register(): void {
        add_action( 'rest_api_init', [ $this, 'routes' ] );
    }

    public function routes(): void {
        $perm = fn() => current_user_can( 'manage_options' );

        register_rest_route( self::NS, '/entries', [
            [ 'methods' => 'GET',  'callback' => [ $this, 'list_entries' ],   'permission_callback' => $perm ],
            [ 'methods' => 'POST', 'callback' => [ $this, 'create_entry' ],   'permission_callback' => $perm ],
        ] );
        register_rest_route( self::NS, '/entries/(?P<id>\d+)', [
            [ 'methods' => 'GET',    'callback' => [ $this, 'get_entry' ],    'permission_callback' => $perm ],
            [ 'methods' => 'PUT',    'callback' => [ $this, 'update_entry' ], 'permission_callback' => $perm ],
            [ 'methods' => 'DELETE', 'callback' => [ $this, 'delete_entry' ], 'permission_callback' => $perm ],
        ] );
        register_rest_route( self::NS, '/entries/validate', [
            [ 'methods' => 'POST', 'callback' => [ $this, 'validate_entry' ], 'permission_callback' => $perm ],
        ] );
        register_rest_route( self::NS, '/entries/generate', [
            [ 'methods' => 'POST', 'callback' => [ $this, 'generate_entry' ], 'permission_callback' => $perm ],
        ] );
        register_rest_route( self::NS, '/detect', [
            [ 'methods' => 'GET', 'callback' => [ $this, 'detect' ], 'permission_callback' => $perm ],
        ] );
        register_rest_route( self::NS, '/global/(?P<key>[a-z_-]+)', [
            [ 'methods' => 'GET',  'callback' => [ $this, 'get_global' ],    'permission_callback' => $perm ],
            [ 'methods' => 'PUT',  'callback' => [ $this, 'put_global' ],    'permission_callback' => $perm ],
        ] );
        register_rest_route( self::NS, '/global/(?P<key>[a-z_-]+)/ai-prefill', [
            [ 'methods' => 'POST', 'callback' => [ $this, 'prefill_global' ], 'permission_callback' => $perm ],
        ] );
        register_rest_route( self::NS, '/rules', [
            [ 'methods' => 'GET',  'callback' => [ $this, 'list_rules' ],   'permission_callback' => $perm ],
            [ 'methods' => 'POST', 'callback' => [ $this, 'create_rule' ],  'permission_callback' => $perm ],
        ] );
        register_rest_route( self::NS, '/rules/(?P<id>[a-z0-9_-]+)', [
            [ 'methods' => 'PUT',    'callback' => [ $this, 'update_rule' ], 'permission_callback' => $perm ],
            [ 'methods' => 'DELETE', 'callback' => [ $this, 'delete_rule' ], 'permission_callback' => $perm ],
        ] );
        register_rest_route( self::NS, '/rules/test', [
            [ 'methods' => 'POST', 'callback' => [ $this, 'test_rule' ], 'permission_callback' => $perm ],
        ] );
        register_rest_route( self::NS, '/jobs', [
            [ 'methods' => 'GET',  'callback' => [ $this, 'list_jobs' ],  'permission_callback' => $perm ],
            [ 'methods' => 'POST', 'callback' => [ $this, 'create_job' ], 'permission_callback' => $perm ],
        ] );
        register_rest_route( self::NS, '/jobs/(?P<id>\d+)', [
            [ 'methods' => 'GET', 'callback' => [ $this, 'get_job' ], 'permission_callback' => $perm ],
        ] );
        register_rest_route( self::NS, '/jobs/(?P<id>\d+)/(?P<action>pause|resume|cancel)', [
            [ 'methods' => 'POST', 'callback' => [ $this, 'control_job' ], 'permission_callback' => $perm ],
        ] );
        register_rest_route( self::NS, '/jobs/preview-cost', [
            [ 'methods' => 'POST', 'callback' => [ $this, 'preview_cost' ], 'permission_callback' => $perm ],
        ] );
        register_rest_route( self::NS, '/spend', [
            [ 'methods' => 'GET', 'callback' => [ $this, 'spend' ], 'permission_callback' => $perm ],
        ] );
        register_rest_route( self::NS, '/migrate-from-meta', [
            [ 'methods' => 'POST', 'callback' => [ $this, 'migrate_from_meta' ], 'permission_callback' => $perm ],
        ] );
    }

    // ---- handlers below ----
}
```

The handlers are implemented over the subsequent tasks in this phase. Each handler returns a `WP_REST_Response` or `WP_Error`.

- [ ] **Step 2: Wire into Plugin::boot().** `( new Rest\Rest_Controller() )->register();`

- [ ] **Step 3: Smoke test** — `curl -u user:app-password http://localhost:8093/wp-json/amplifi-schema/v1/spend` returns a 200 once `spend` handler is in place (next task).

- [ ] **Step 4: Commit.**

```bash
git add plugins/ac-schema/includes/rest/ plugins/ac-schema/includes/class-plugin.php
git commit -m "feat(ac-schema): REST controller scaffold"
```

### Task 7.2: Entry handlers (CRUD + validate + generate)

- [ ] **Step 1: Implement** the entry handlers using `Entry_Store`, `Validator`, `Anthropic_Client`, `Prompt_Builder`, `Spend_Tracker`, and `Secret_Store`. Each handler is ~15-30 lines.

`list_entries`:

```php
public function list_entries( \WP_REST_Request $req ): \WP_REST_Response {
    $store = new \Amplifi\Schema\Data\Entry_Store();
    $scope_type = (string) $req->get_param( 'scope_type' );
    $scope_id   = (string) $req->get_param( 'scope_id' );
    if ( $scope_type && $scope_id !== '' ) {
        return new \WP_REST_Response( $store->find_all_for_scope( $scope_type, $scope_id ) );
    }
    // (Pagination omitted in v1: callers always scope.)
    return new \WP_REST_Response( [] );
}
```

`create_entry` / `update_entry`: accept `{scope_type, scope_id, schema_type, json_ld, source}`, run `Validator`, on failure return `WP_Error('invalid_schema', 400, $errors)`, on success `Entry_Store::save()` and return the row.

`validate_entry`: just runs `Validator->validate()` and returns the result. No persistence.

`generate_entry`: accepts `{post_id, model?}` or `{url, content, model?}`. Builds prompt via `Prompt_Builder::build_for_post`, instantiates `Anthropic_Client` with the decrypted key from `Secret_Store`, calls `generate_jsonld`, records spend, validates, and returns `{jsonld, errors, cost_usd}`. Does NOT save automatically; the UI decides.

- [ ] **Step 2: Manual test via curl** for each handler.

- [ ] **Step 3: Commit.**

```bash
git add plugins/ac-schema/includes/rest/class-rest-controller.php
git commit -m "feat(ac-schema): REST entry CRUD + validate + AI generate"
```

### Task 7.3: Global, Rules, Detect, Spend, Migrate handlers

- [ ] **Step 1: Implement remaining handlers** following the same pattern:
  - `get_global` / `put_global`: read/write `ac_schema_global_{key}` option, plus mirror as an `entries` row with `scope_type='global'`. `prefill_global` builds site_ctx (`title`, `tagline`, `url`, `admin_email`, `icon`) and runs `Anthropic_Client` with `Prompt_Builder::build_for_global`.
  - `list_rules` / `create_rule` / `update_rule` / `delete_rule` / `test_rule`: read/write `ac_schema_url_rules` option. Each rule has a generated `id` (uniqid). `test_rule` accepts `{pattern, match_type, url}` and returns `{matches: bool}`.
  - `detect`: `wp_die`s if URL not on same host; calls `Detector::detect_for_url()`.
  - `spend`: returns `{today_usd, month_usd, daily_cap, monthly_cap}`.
  - `migrate_from_meta`: dispatched to `Meta_Importer` (Phase 11).

- [ ] **Step 2: Commit.**

```bash
git add plugins/ac-schema/includes/rest/class-rest-controller.php
git commit -m "feat(ac-schema): REST global, rules, detect, spend, migrate handlers"
```

### Task 7.4: Job handlers (just routing for now)

- [ ] **Step 1: Implement** `list_jobs`, `get_job`, `create_job`, `control_job`, `preview_cost` over `Job_Store`. `create_job` writes a row and triggers `wp_schedule_single_event( time(), 'ac_schema_run_bulk_batch', [ $id ] )`. The handler that actually runs the batch comes in Phase 8.

- [ ] **Step 2: Commit.**

```bash
git add plugins/ac-schema/includes/rest/class-rest-controller.php
git commit -m "feat(ac-schema): REST job handlers"
```

---

## Phase 8 — Bulk queue

### Task 8.1: Bulk_Job runner

**Files:**
- Create: `plugins/ac-schema/includes/queue/class-bulk-job.php`
- Modify: `plugins/ac-schema/includes/class-plugin.php`

- [ ] **Step 1: Implement Bulk_Job.**

```php
<?php
declare(strict_types=1);
namespace Amplifi\Schema\Queue;

use Amplifi\Schema\AI\Anthropic_Client;
use Amplifi\Schema\AI\Prompt_Builder;
use Amplifi\Schema\AI\Spend_Tracker;
use Amplifi\Schema\Crypto\Secret_Store;
use Amplifi\Schema\Data\Entry_Store;
use Amplifi\Schema\Schema\Validator;
use Amplifi\Schema\Schema\Registry;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Bulk_Job {
    private const BATCH = 5;
    private const RESCHEDULE_DELAY = 30;

    public function register(): void {
        add_action( 'ac_schema_run_bulk_batch', [ $this, 'run_batch' ], 10, 1 );
    }

    public function run_batch( int $job_id ): void {
        $store = new Job_Store();
        $job   = $store->find( $job_id );
        if ( ! $job || ! in_array( $job['status'], [ 'queued', 'running' ], true ) ) { return; }
        $store->set_status( $job_id, 'running' );

        $scope = json_decode( $job['scope'], true ) ?: [];
        $ids   = $this->next_post_ids( $job_id, $scope, self::BATCH );
        if ( empty( $ids ) ) {
            $store->set_status( $job_id, 'completed' );
            return;
        }

        $settings = get_option( 'ac_schema_settings', [] );
        $api_key  = Secret_Store::get( 'anthropic_api_key' );
        if ( ! $api_key ) { $store->set_status( $job_id, 'failed' ); return; }
        $client    = new Anthropic_Client( $api_key, $job['model'] );
        $validator = new Validator( new Registry() );
        $entries   = new Entry_Store();

        $processed = 0; $failed = 0; $cost = 0.0;
        foreach ( $ids as $post_id ) {
            if ( ! Spend_Tracker::can_spend( 0.05 ) ) { $store->set_status( $job_id, 'paused' ); break; }
            $post = get_post( $post_id );
            if ( ! $post ) { $failed++; continue; }
            $prompt = Prompt_Builder::build_for_post( [
                'title'     => $post->post_title,
                'url'       => get_permalink( $post ),
                'post_type' => $post->post_type,
                'content'   => wp_strip_all_tags( $post->post_content ),
                'existing'  => null,
            ] );
            $r = $client->generate_jsonld( $prompt['system'], $prompt['user'] );
            if ( ! empty( $r['error'] ) ) { $failed++; continue; }
            Spend_Tracker::record( $job['model'], $r['input_tokens'], $r['output_tokens'] );
            $cost += Spend_Tracker::estimate_cost( $job['model'], $r['input_tokens'], $r['output_tokens'] );
            $json = wp_json_encode( $r['jsonld'] );
            $v = $validator->validate( $json );
            if ( ! $v['ok'] ) { $failed++; continue; }
            $entries->save( [
                'scope_type' => 'post',
                'scope_id'   => (string) $post_id,
                'schema_type'=> $r['jsonld']['@type'] ?? 'Thing',
                'source'     => 'ai',
                'json_ld'    => $json,
            ] );
            $processed++;
        }
        $store->record_progress( $job_id, $processed, $failed, $cost );

        // Re-arm if more work remains.
        if ( in_array( $store->find( $job_id )['status'], [ 'running' ], true ) ) {
            wp_schedule_single_event( time() + self::RESCHEDULE_DELAY, 'ac_schema_run_bulk_batch', [ $job_id ] );
        }
    }

    private function next_post_ids( int $job_id, array $scope, int $limit ): array {
        // Build WP_Query from scope; exclude posts already in `entries` for the same schema_type.
        $args = [
            'post_type'      => $scope['post_types'] ?? [ 'post', 'page' ],
            'post_status'    => 'publish',
            'posts_per_page' => $limit,
            'fields'         => 'ids',
            'orderby'        => 'ID',
            'order'          => 'ASC',
        ];
        if ( ! empty( $scope['ids'] ) ) {
            $args['post__in'] = array_map( 'intval', $scope['ids'] );
        }
        if ( ! empty( $scope['after'] ) ) {
            $args['date_query'] = [ [ 'after' => $scope['after'] ] ];
        }
        // Exclude posts that already have a generated entry for this scope.
        global $wpdb;
        $already = $wpdb->get_col( "SELECT scope_id FROM {$wpdb->prefix}ac_schema_entries WHERE scope_type='post' AND source='ai'" ); // phpcs:ignore
        if ( $already ) { $args['post__not_in'] = array_map( 'intval', $already ); }
        return get_posts( $args );
    }
}
```

- [ ] **Step 2: Wire into Plugin::boot().** `( new Queue\Bulk_Job() )->register();`

- [ ] **Step 3: End-to-end smoke test** — create a post, set the API key via REST (after settings page exists, or via wp-cli `update_option`), kick a job for one post, verify entry appears in `entries` table.

- [ ] **Step 4: Commit.**

```bash
git add plugins/ac-schema/includes/queue/class-bulk-job.php plugins/ac-schema/includes/class-plugin.php
git commit -m "feat(ac-schema): Bulk_Job WP-Cron batch runner"
```

---

## Phase 9 — Admin pages (non-React)

The dashboard, global, rules, and bulk pages are server-rendered with WP admin styling. The React editor is Phase 10.

### Task 9.1: Settings on dashboard + API key + spend display

**Files:**
- Modify: `plugins/ac-schema/includes/admin/class-dashboard-page.php`
- Create: `plugins/ac-schema/includes/admin/class-settings-section.php`

- [ ] **Step 1: Render the dashboard** with: total entries by type (queried via Entry_Store), recent jobs (Job_Store::list_recent), spend today + month (Spend_Tracker), big "Generate for site" CTA linking to the Bulk page, and a settings section (API key, model, caps). API key field POSTs via REST `/wp-json/amplifi-schema/v1/settings` (new lightweight endpoint, or just register here a small admin-post handler — your call; prefer REST for consistency). The encrypted key is written via `Secret_Store::set( 'anthropic_api_key', $value )`.

- [ ] **Step 2: Commit.**

```bash
git add plugins/ac-schema/includes/admin/
git commit -m "feat(ac-schema): dashboard with settings, spend, recent jobs"
```

### Task 9.2: Global page

**Files:**
- Create: `plugins/ac-schema/includes/admin/class-global-page.php`
- Modify: `plugins/ac-schema/includes/admin/class-admin.php`

- [ ] **Step 1: Render three form sections** (Organization / WebSite / LocalBusiness). Each loads its `ac_schema_global_{key}` option and prefills form fields for that type's required properties. "Prefill with AI" button calls REST `/global/{key}/ai-prefill`. Save POSTs to REST `/global/{key}`.

- [ ] **Step 2: Register as submenu** via `add_submenu_page( 'amplifi-studio', 'Schema: Global', 'Schema: Global', 'manage_options', 'amplifi-ac-schema-global', [ $page, 'render' ] );` from `Admin::register_with_framework()`. Use the slug-prefix guard pattern from amplifi-security so the URL stays `amplifi-ac-schema-global`.

- [ ] **Step 3: Commit.**

```bash
git add plugins/ac-schema/includes/admin/class-global-page.php plugins/ac-schema/includes/admin/class-admin.php
git commit -m "feat(ac-schema): Global schema editor page"
```

### Task 9.3: URL Rules page

**Files:**
- Create: `plugins/ac-schema/includes/admin/class-rules-page.php`
- Modify: `plugins/ac-schema/includes/admin/class-admin.php`

- [ ] **Step 1: Render a table** of rules with pattern, match type, and number of schema entries. "Add rule" opens an inline form. "Test" button calls REST `/rules/test`. Per-rule edit opens a modal that wraps the same dual-pane editor used per-post (Phase 10) but bound to `scope_type=url_rule`.

- [ ] **Step 2: Commit.**

```bash
git add plugins/ac-schema/includes/admin/class-rules-page.php plugins/ac-schema/includes/admin/class-admin.php
git commit -m "feat(ac-schema): URL Rules admin page"
```

### Task 9.4: Bulk page

**Files:**
- Create: `plugins/ac-schema/includes/admin/class-bulk-page.php`
- Modify: `plugins/ac-schema/includes/admin/class-admin.php`

- [ ] **Step 1: Render scope picker** (post types as checkboxes from `get_post_types(['public'=>true])`, "after date" input, optional explicit IDs textarea, model dropdown). "Preview cost" button calls REST `/jobs/preview-cost` and renders the estimate. "Start" calls REST `/jobs` (POST). After starting, the page polls `/jobs/{id}` every 3s and renders a progress bar + per-post log. Pause/Resume/Cancel buttons call the corresponding REST routes.

- [ ] **Step 2: Commit.**

```bash
git add plugins/ac-schema/includes/admin/class-bulk-page.php plugins/ac-schema/includes/admin/class-admin.php
git commit -m "feat(ac-schema): Bulk generate page with live progress"
```

---

## Phase 10 — Per-post metabox + React dual-pane editor

### Task 10.1: Vite + React build setup

**Files:**
- Create: `plugins/ac-schema/admin-src/package.json`
- Create: `plugins/ac-schema/admin-src/vite.config.ts`
- Create: `plugins/ac-schema/admin-src/tsconfig.json`
- Create: `plugins/ac-schema/admin-src/src/main.tsx`

- [ ] **Step 1: Init the build** under `admin-src/`. `package.json`:

```json
{
  "name": "ac-schema-admin",
  "private": true,
  "type": "module",
  "scripts": {
    "build": "vite build",
    "dev": "vite build --watch",
    "test": "vitest run"
  },
  "dependencies": { "react": "^18.3.1", "react-dom": "^18.3.1" },
  "devDependencies": {
    "@types/react": "^18.3.0", "@types/react-dom": "^18.3.0",
    "@vitejs/plugin-react": "^4.3.0", "typescript": "^5.4.0",
    "vite": "^5.3.0", "vitest": "^1.6.0", "@testing-library/react": "^15.0.0"
  }
}
```

`vite.config.ts`:

```ts
import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
export default defineConfig({
  plugins: [react()],
  build: {
    outDir: '../includes/admin/assets/dist',
    emptyOutDir: true,
    lib: {
      entry: 'src/main.tsx',
      name: 'AcSchemaEditor',
      formats: ['iife'],
      fileName: () => 'editor.js',
    },
    rollupOptions: { output: { assetFileNames: 'editor.css' } },
  },
});
```

`src/main.tsx`:

```tsx
import { createRoot } from 'react-dom/client';
import { Editor } from './Editor';

declare global { interface Window { AcSchema: any } }

const els = document.querySelectorAll('[data-ac-schema-editor]');
els.forEach((el) => {
  const root = createRoot(el);
  const postId = Number((el as HTMLElement).dataset.postId || '0');
  root.render(<Editor postId={postId} api={window.AcSchema} />);
});
```

- [ ] **Step 2: Run `npm install && npm run build`** to verify the toolchain. The output `includes/admin/assets/dist/editor.js` + `editor.css` should appear.

- [ ] **Step 3: Commit** (excluding node_modules and dist via .gitignore for source-only; commit `dist/` because release tarballs need it).

```bash
git add plugins/ac-schema/admin-src/ plugins/ac-schema/includes/admin/assets/dist/
git commit -m "feat(ac-schema): Vite + React build setup, empty editor"
```

### Task 10.2: Editor React component (dual-pane)

**Files:**
- Create: `plugins/ac-schema/admin-src/src/Editor.tsx`
- Create: `plugins/ac-schema/admin-src/src/FormPane.tsx`
- Create: `plugins/ac-schema/admin-src/src/JsonPane.tsx`
- Create: `plugins/ac-schema/admin-src/src/api.ts`
- Create: `plugins/ac-schema/admin-src/src/types.ts`
- Create: `plugins/ac-schema/admin-src/src/__tests__/Editor.test.tsx`

- [ ] **Step 1: Define types** in `types.ts` (Entry, ValidationError, etc.) and the API wrapper (`api.ts`) that uses `fetch` against `/wp-json/amplifi-schema/v1/...` with the WP REST nonce from `window.AcSchema.nonce`.

- [ ] **Step 2: Build `<Editor>`** — top-level component with:
  - Type picker (dropdown from registry, loaded via API or embedded in `window.AcSchema.registry`).
  - Multi-entry tabs (one per `schema_type` for this post).
  - "Generate with AI" button (calls `/entries/generate` with `{post_id}`).
  - "Detected by Yoast/etc." banner if `window.AcSchema.detected` non-empty, with three buttons (import / override / ignore).
  - "Test in Google Rich Results" link.
  - Validation summary.
  - Dual-pane: `<FormPane>` | `<JsonPane>` side-by-side. State is a single `value: object`. Form edits update value; JSON edits update value if valid. Bidirectional sync runs on blur (debounce 300ms).

- [ ] **Step 3: `<FormPane>`** — receives `type: string` and `value: object`, renders an input per property from `window.AcSchema.registry[type].properties`. Strings → text input, URLs → URL input, dates → date input, arrays → repeating group, nested objects → recursive FormPane.

- [ ] **Step 4: `<JsonPane>`** — controlled `<textarea>` with monospace font; on change parses and on blur surfaces parse errors via callback.

- [ ] **Step 5: Vitest tests** for the form ↔ JSON sync logic (pure function `formStateToJson` and `jsonToFormState`). Cover round-trip, malformed JSON, missing fields.

- [ ] **Step 6: Build + commit.**

```bash
cd plugins/ac-schema/admin-src && npm run build && npm test
cd ../../..
git add plugins/ac-schema/admin-src/ plugins/ac-schema/includes/admin/assets/dist/
git commit -m "feat(ac-schema): dual-pane React editor"
```

### Task 10.3: Post_Editor metabox

**Files:**
- Create: `plugins/ac-schema/includes/admin/class-post-editor.php`
- Modify: `plugins/ac-schema/includes/admin/class-admin.php`

- [ ] **Step 1: Register metabox** on `add_meta_boxes` for `post`, `page`, and all `get_post_types(['public'=>true,'_builtin'=>false])`. Enqueue `editor.js` + `editor.css` from `dist/`. Localize `window.AcSchema`:

```php
wp_localize_script( 'ac-schema-editor', 'AcSchema', [
    'restUrl'  => esc_url_raw( rest_url( 'amplifi-schema/v1/' ) ),
    'nonce'    => wp_create_nonce( 'wp_rest' ),
    'postId'   => $post->ID,
    'permalink'=> get_permalink( $post ),
    'registry' => $this->lite_registry_for_js(),
    'detected' => Detector_For_Edit_Screen::get( $post->ID ), // see Detector cache
] );
```

The metabox HTML body is just `<div data-ac-schema-editor data-post-id="..."></div>`.

- [ ] **Step 2: Smoke test** — edit a post in WP admin, confirm metabox renders the React editor, can generate, save, reload, and edits persist via REST.

- [ ] **Step 3: Commit.**

```bash
git add plugins/ac-schema/includes/admin/class-post-editor.php plugins/ac-schema/includes/admin/class-admin.php
git commit -m "feat(ac-schema): per-post metabox mounts dual-pane editor"
```

---

## Phase 11 — Migration from amplifi.meta

### Task 11.1: Meta_Importer

**Files:**
- Create: `plugins/ac-schema/includes/migration/class-meta-importer.php`
- Create: `plugins/ac-schema/tests/Migration/MetaImporterTest.php`

- [ ] **Step 1: Failing test** — seed mock `_ac_jsonld_data` post meta on a fake post, call `Meta_Importer::import_all()`, expect rows in `entries` with `source='imported'`.

- [ ] **Step 2: Implement.**

```php
<?php
declare(strict_types=1);
namespace Amplifi\Schema\Migration;

use Amplifi\Schema\Data\Entry_Store;
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Meta_Importer {
    public static function import_all(): array {
        global $wpdb;
        $rows = $wpdb->get_results( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_ac_jsonld_data'", ARRAY_A ); // phpcs:ignore
        $store = new Entry_Store();
        $imported = 0;
        foreach ( $rows as $r ) {
            $json = maybe_unserialize( $r['meta_value'] );
            $json = is_array( $json ) ? wp_json_encode( $json ) : (string) $json;
            if ( ! $json ) { continue; }
            $data = json_decode( $json, true );
            if ( ! is_array( $data ) ) { continue; }
            $type = $data['@type'] ?? 'Thing';
            $store->save( [
                'scope_type' => 'post',
                'scope_id'   => (string) $r['post_id'],
                'schema_type'=> is_array( $type ) ? $type[0] : (string) $type,
                'source'     => 'imported',
                'json_ld'    => $json,
            ] );
            $imported++;
        }
        // Org-level import from ac_jsonld_settings if our own global is empty.
        $meta_settings = get_option( 'ac_jsonld_settings', [] );
        if ( is_array( $meta_settings ) && ! get_option( 'ac_schema_global_organization' ) && ! empty( $meta_settings['organization'] ) ) {
            update_option( 'ac_schema_global_organization', $meta_settings['organization'] );
        }
        update_option( 'ac_schema_meta_import_status', 'done' );
        $s = get_option( 'ac_schema_settings', [] );
        $s['suppress_amplifi_meta_jsonld'] = true;
        update_option( 'ac_schema_settings', $s );
        return [ 'imported' => $imported ];
    }
}
```

- [ ] **Step 3: REST `migrate_from_meta` handler** calls `Meta_Importer::import_all()` and returns the count.

- [ ] **Step 4: Admin notice** in Admin: if `ac_schema_meta_import_status === 'pending'`, render notice with "Import" (button posts to REST) and "Skip" (sets status to `skipped`).

- [ ] **Step 5: Commit.**

```bash
git add plugins/ac-schema/includes/migration/ plugins/ac-schema/tests/Migration/ plugins/ac-schema/includes/rest/class-rest-controller.php plugins/ac-schema/includes/admin/class-admin.php
git commit -m "feat(ac-schema): import amplifi.meta JSON-LD with one-time notice"
```

### Task 11.2: Defer amplifi.meta's JSON-LD output

**Files:**
- Modify: `plugins/ac-bulk-meta/ac-bulk-meta.php` (the relevant JSON-LD output method and the JSON-LD admin page)

- [ ] **Step 1: Locate the JSON-LD output method** in `plugins/ac-bulk-meta/ac-bulk-meta.php`. (Grep for `_ac_jsonld_data` to find write/read sites.)

- [ ] **Step 2: Add deferral guard** at the start of the wp_head output method:

```php
if ( defined( 'AMPLIFI_SCHEMA_ACTIVE' ) ) { return; }
```

- [ ] **Step 3: Add deprecation notice** to the JSON-LD admin page render method:

```php
if ( defined( 'AMPLIFI_SCHEMA_ACTIVE' ) ) {
    echo '<div class="notice notice-info"><p>JSON-LD is now managed by <strong>amplifi.schema</strong>. ';
    echo '<a href="' . esc_url( admin_url( 'admin.php?page=amplifi-ac-schema' ) ) . '">Open amplifi.schema</a></p></div>';
}
```

- [ ] **Step 4: Smoke test** — with both plugins active, verify amplifi.meta no longer emits a second JSON-LD block and its admin page shows the deprecation notice.

- [ ] **Step 5: Commit.**

```bash
git add plugins/ac-bulk-meta/ac-bulk-meta.php
git commit -m "feat(ac-bulk-meta): defer JSON-LD to amplifi.schema when active"
```

---

## Phase 12 — Manifest, docs, release prep

### Task 12.1: Update plugins-manifest.json

**Files:**
- Modify: `plugins-manifest.json` (root)

- [ ] **Step 1: Add an entry** for `ac-schema`:

```json
{
  "slug": "ac-schema",
  "name": "Schema",
  "description": "AI-powered schema.org JSON-LD: bulk generate, edit, deploy, validate.",
  "icon": "format-aside"
}
```

(Pick the dashicon at your discretion — `database-view` or `editor-code` also fit.)

- [ ] **Step 2: Commit.**

```bash
git add plugins-manifest.json
git commit -m "feat(manifest): register amplifi.schema"
```

### Task 12.2: Update root CLAUDE.md

**Files:**
- Modify: `CLAUDE.md`

- [ ] **Step 1: Add a `## Plugin: amplifi.schema` section** mirroring the depth of other plugin sections (Architecture / Key Files / Admin Menu / Data Storage / Migration). Source content from the spec file.

- [ ] **Step 2: Commit.**

```bash
git add CLAUDE.md
git commit -m "docs(claude): document amplifi.schema plugin"
```

### Task 12.3: Release dry-run

- [ ] **Step 1: Run** `./scripts/release.sh 2.1.0` in a throwaway branch and inspect: ensure `ac-schema` zip appears in release artifacts with `includes/amplifi-framework.php` and `includes/admin/assets/dist/` bundled.

- [ ] **Step 2: If the release script needs adjustment** to include `dist/` for `ac-schema` (it may exclude `admin-src/` automatically but ensure dist is preserved), patch and commit. Do NOT cut a real release in this plan — that's the user's call.

---

## Self-review

**Spec coverage check** (cross-checked against `docs/superpowers/specs/2026-05-21-amplifi-schema-design.md`):

| Spec section | Covered by |
|---|---|
| Goal & relationship to amplifi.meta | Phase 1 + Phase 11 |
| Requirements 1-12 (locked decisions) | Phases 3, 6, 7, 8, 9, 10 |
| Architecture (file tree) | Phases 1-10 each create their portion |
| Data model (3 tables, post meta, options) | Task 1.3 (tables), Task 4.1 (entries usage), Task 4.2 (jobs), Task 3.2 (spend), settings options in Task 1.3 + 9.1 |
| Admin UI (4 submenus + metabox) | Tasks 1.5, 9.2, 9.3, 9.4, 10.3 |
| Front-end output (single @graph) | Phase 5 |
| AI integration (Anthropic_Client, Prompt_Builder, Spend_Tracker) | Phase 3 |
| Bulk queue with caps + pause | Task 8.1 |
| Validation (Validator + Registry + Rich Results link) | Phase 2 + Task 10.2 (link in editor) |
| Backwards compat (Detector + Foreign_Suppressor) | Phase 6 |
| REST API (all 22 routes) | Phase 7 |
| Migration from amplifi.meta | Phase 11 |
| Security (Secret_Store, REST perms, JSON encoding flags) | Task 3.1, Task 7.1, Task 5.2 |
| Testing strategy | Tests interleaved in Phases 2, 3, 4, 6, 10, 11 |

**Placeholder scan:** No TBD/TODO/"similar to" placeholders. Each code step contains the actual code or the exact pattern to copy.

**Type consistency:** `Entry_Store` methods (`save`, `find_one`, `find_all_for_scope`, `find_id`, `delete`) match usage in `Graph_Builder`, `Bulk_Job`, REST handlers, `Meta_Importer`. `Job_Store` methods referenced consistently between Task 4.2 declaration and Tasks 7.4 / 8.1 callers. `Spend_Tracker::estimate_cost`, `record`, `can_spend`, `spend_today_usd`, `spend_month_usd` consistent across phases.

**Known gaps acknowledged:**
- The Bulk page (Task 9.4) is server-rendered with vanilla JS polling rather than React, to keep the React bundle focused on the editor. If a future iteration wants unified UX, the bundle entry can mount additional roots.
- `Detector_For_Edit_Screen` referenced in Task 10.3 is a thin wrapper that calls `Detector::detect_for_url( get_permalink( $post_id ) )` with the cached transient — implement inline in `class-post-editor.php` rather than as a separate class.

---

## Execution handoff

Plan complete and saved to `docs/superpowers/plans/2026-05-21-amplifi-schema.md`. Two execution options:

1. **Subagent-Driven (recommended)** — I dispatch a fresh subagent per task, review between tasks, fast iteration. Best for a plugin this size — keeps the main thread's context clean.
2. **Inline Execution** — Execute tasks in this session using executing-plans, batch execution with checkpoints.

Which approach?
