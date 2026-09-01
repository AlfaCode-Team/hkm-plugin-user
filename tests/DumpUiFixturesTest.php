<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\User;

use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Identity;
use PHPUnit\Framework\Attributes\CoversNothing;
use Plugins\Audit\Provider as AuditProvider;
use Plugins\Crypto\Provider as CryptoProvider;
use Plugins\Database\Provider as DatabaseProvider;
use Plugins\Ground\Ground\PluginGround;
use Plugins\Ground\Ground\PluginGroundTestCase;
use Plugins\HttpClient\Provider as HttpClientProvider;
use Plugins\I18n\Provider as I18nProvider;
use Plugins\Mail\Provider as MailProvider;
use Plugins\Pageflow\Provider as PageflowProvider;
use Plugins\RedisCache\Provider as RedisCacheProvider;
use Plugins\User\Provider;
use Plugins\Validation\Provider as ValidationProvider;
use Plugins\View\Provider as ViewProvider;
use Plugins\ViteManifest\Provider as ViteManifestProvider;

/**
 * Dumps the props each admin page is really sent into `ui/__fixtures__/`, for
 * the vitest component tests to render against.
 *
 * ─── WHY A DUMP AND NOT A MOCK ──────────────────────────────────────────────
 *
 * A component test written against hand-written props keeps passing after the
 * server renames a field: the mock is renamed by whoever wrote it, or never,
 * and the page breaks in the browser while both suites stay green. The fixture
 * is the actual response body, so a rename on the PHP side changes the file and
 * the component test fails on the next run.
 *
 * These assertions are deliberately thin — the page contract is asserted in
 * AdminUiTest. What this file guarantees is that the fixture on disk is
 * CURRENT, which is the only property the vitest side depends on.
 *
 * Re-run it when props change (`vendor/bin/phpunit tests/DumpUiFixturesTest.php`)
 * and commit the JSON.
 */
#[CoversNothing]
final class DumpUiFixturesTest extends PluginGroundTestCase
{
    private const FIXTURES = __DIR__ . '/../ui/__fixtures__';

    private string $dbFile = '';

    protected function plugin(): string
    {
        return Provider::class;
    }

    protected function dependencies(): array
    {
        return [
            DatabaseProvider::class,
            CryptoProvider::class,
            RedisCacheProvider::class,
            ViewProvider::class,
            HttpClientProvider::class,
            ValidationProvider::class,
            MailProvider::class,
            AuditProvider::class,
            I18nProvider::class,
            PageflowProvider::class,
            ViteManifestProvider::class,
        ];
    }

    /**
     * The database path is chosen in setUp, NOT in configure().
     *
     * configure() runs lazily, on the first ground() call — which is AFTER the
     * seeding a dump needs. Assigning it there left $dbFile empty while seed()
     * ran, so `new PDO('sqlite:')` opened a throwaway anonymous database, the
     * rows went into it, and the request then read the real file and reported
     * "no such table: users".
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->dbFile = sys_get_temp_dir() . '/user-ui-fixtures-' . bin2hex(random_bytes(6)) . '.sqlite';
    }

    protected function configure(PluginGround $ground): PluginGround
    {
        return $ground
            ->as(Identity::asAdmin())
            ->env(['DB_DRIVER' => 'sqlite', 'DB_DATABASE' => $this->dbFile]);
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        if ($this->dbFile !== '' && is_file($this->dbFile)) {
            unlink($this->dbFile);
        }
    }

    public function testItDumpsTheAdminIndexPage(): void
    {
        $this->requireSqlite();

        // Two rows, with fixed names: the fixture is COMMITTED, so it must not
        // carry a real person's details and must not change on every run.
        $this->seed('janedoe', 'jane@example.test', verified: true);
        $this->seed('johnsmith', 'john@example.test', verified: false);

        $page = $this->ground()->pageflow('/admin/users');

        $this->assertComponent('User/Index', $page);

        $page->writeFixture(self::FIXTURES . '/user-index.json');
    }

    public function testItDumpsTheAdminShowPage(): void
    {
        // An id that resolves to no row: the page renders an empty state rather
        // than erroring (AdminUiTest pins that), and an empty state is the
        // safest thing to commit — a dumped real user would put a name and an
        // email address in the repository.
        $this->requireSqlite();
        $this->createSchema();

        $page = $this->ground()->pageflow('/admin/users/00000000-0000-4000-8000-000000000000');

        $this->assertComponent('User/Show', $page);

        $page->writeFixture(self::FIXTURES . '/user-show.json');
    }

    // ── Fixture data ──────────────────────────────────────────────────────────

    /**
     * The live-SQL half needs pdo_sqlite, which this plugin's CI installs and a
     * laptop may not. Skipping cleanly beats failing on a driver error that has
     * nothing to do with the page under test — but it does mean a skipped run
     * leaves the previous fixture in place rather than refreshing it.
     */
    private function requireSqlite(): void
    {
        if (!\extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite is not loaded — install it to refresh the UI fixtures.');
        }
    }

    private function seed(string $username, string $email, bool $verified): void
    {
        $this->createSchema();

        $pdo = new \PDO('sqlite:' . $this->dbFile);
        $pdo->prepare(
            'INSERT INTO users
                (user_id, username, email, password_hash, version, email_verified_at, created_at, updated_at)
             VALUES (:id, :username, :email, :hash, 1, :verified_at, :now, :now)',
        )->execute([
            // Deterministic ids, so re-running does not produce a diff that is
            // nothing but noise in the committed fixture.
            'id'          => strtoupper(substr(hash('sha256', $username), 0, 26)),
            'username'    => $username,
            'email'       => $email,
            'hash'        => '$2y$12$' . str_repeat('a', 53),
            'verified_at' => $verified ? '2026-01-01 00:00:00' : null,
            'now'         => '2026-01-01 00:00:00',
        ]);
    }

    private function createSchema(): void
    {
        $pdo = new \PDO('sqlite:' . $this->dbFile);
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id CHAR(31) NOT NULL,
                username VARCHAR(50) NOT NULL,
                email VARCHAR(150) NOT NULL,
                password_hash VARCHAR(255) NOT NULL,
                remember_token CHAR(64) NULL,
                version INTEGER NOT NULL DEFAULT 1,
                email_verified_at TIMESTAMP NULL,
                email_verification_token_hash CHAR(64) NULL,
                email_verification_expires_at TIMESTAMP NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                deleted_at TIMESTAMP NULL,
                UNIQUE(user_id), UNIQUE(username), UNIQUE(email)
            )',
        );
    }
}
