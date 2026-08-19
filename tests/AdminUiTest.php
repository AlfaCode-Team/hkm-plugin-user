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
 * The admin Pageflow UI ('User/Index', 'User/Show'), verified through the REAL
 * boot pipeline, route compiler and dependency graph — not a hand-rolled
 * bootstrap, and not just "does a .tsx file with this name exist" (though
 * assertRendersPage() checks that too, both halves at once).
 *
 * Backed by a real file-based SQLite `users` table (DB_DRIVER defaults to
 * sqlite; a real file rather than :memory: because Database's Provider does
 * NOT reuse one adapter instance — a fresh MultiDriverDatabaseAdapter is built
 * on every resolution, and separate :memory: connections do not share data).
 * That means UserRepository::paginate()'s search/verified filtering — added
 * this session — is exercised against REAL SQL here, not skipped the way the
 * FakeUserStore-backed UserServiceTest has to skip it.
 *
 * Every sibling provider loaded here binds lazily and does not reach a real
 * external system unless something actually calls it:
 *   - RedisCache no-ops without REDIS_HOST (the ground's FakeCache stays bound)
 *   - Mail/HttpClient/Crypto/Validation/Audit/Pageflow/ViteManifest bind
 *     closures only; nothing in the routes this file hits sends mail, makes an
 *     outbound call, or renders an HTML shell (pageflow() requests JSON, not
 *     the layout, so Vite asset resolution never runs).
 */
#[CoversNothing]
final class AdminUiTest extends PluginGroundTestCase
{
    private string $dbFile = '';

    protected function plugin(): string
    {
        return Provider::class;
    }

    /** Every domain user.management's module.json requires[], plus http.pageflow for the admin routes. */
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

    protected function setUp(): void
    {
        parent::setUp();

        // Computed here, NOT inside configure(): ground() lazily boots on
        // first use and memoizes, so configure() only runs the first time a
        // test calls $this->ground(). Every test here calls seedUser() BEFORE
        // that first call — if the path were assigned in configure(), it
        // would still be '' at seed time. PDO treats an empty filename as a
        // private, connection-scoped temp database that vanishes the moment
        // that PDO handle is released, so the seed would silently land
        // nowhere and the real (correctly configured) connection would find
        // an empty database — exactly the "no such table: users" failure
        // this setUp() fixes.
        $this->dbFile = sys_get_temp_dir() . '/hkm-user-ground-' . bin2hex(random_bytes(6)) . '.sqlite';
    }

    protected function configure(PluginGround $ground): PluginGround
    {
        // A real file, not :memory: — Database's Provider does not cache one
        // adapter instance (DatabasePort is bind(), not singleton()), and
        // separate :memory: connections do not share data. The file itself is
        // NOT created here: only tests that actually seed data touch PDO
        // (via insertUser(), which creates the schema on first use), so a
        // test that never calls seedUser() never needs pdo_sqlite loaded.
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

    /**
     * Every test that seeds a real row needs pdo_sqlite. It's what the
     * plugin's own CI installs specifically for this (.github/workflows/ci.yml
     * — "several plugins ... exercise the SQLite driver"), but is not always
     * present locally. Skip cleanly rather than fail on a driver error that
     * has nothing to do with the plugin under test.
     */
    private function requireSqlite(): void
    {
        if (!\extension_loaded('pdo_sqlite')) {
            self::markTestSkipped(
                'pdo_sqlite is not loaded in this PHP — install it to run the live-SQL half '
                . 'of this suite (this plugin\'s own CI does, in .github/workflows/ci.yml).',
            );
        }
    }

    // ── Index: both halves (component + real page file) ────────────────────────

    public function testAdminIndexRendersUserIndexWithSearchAndFilterProps(): void
    {
        $this->requireSqlite();
        $this->seedUser('janedoe', 'jane@example.com', verified: true);
        $this->seedUser('johnsmith', 'john@example.com', verified: false);

        $page = $this->ground()->pageflow('/admin/users');

        $this->assertRendersPage($page, 'User/Index', 'admin');
        $this->assertHasProp($page, 'users');
        $this->assertHasProp($page, 'hasMore');
        $this->assertHasProp($page, 'nextCursor');
        // Echoed back with no q/verified on the request — proves the new props
        // this session added to UserFlowController::adminIndex() are actually
        // wired, not silently dropped.
        $this->assertPageProp($page, 'search', null);
        $this->assertPageProp($page, 'verified', null);
        self::assertCount(2, $page->prop('users'));
    }

    public function testSearchNarrowsTheResultSetAgainstRealSql(): void
    {
        $this->requireSqlite();
        $this->seedUser('janedoe', 'jane@example.com', verified: true);
        $this->seedUser('johnsmith', 'john@example.com', verified: true);

        $page = $this->ground()->pageflow('/admin/users', ['q' => 'jane']);

        self::assertCount(1, $page->prop('users'));
        self::assertSame('janedoe', $page->prop('users.0.username'));
        $this->assertPageProp($page, 'search', 'jane');
    }

    public function testSearchAlsoMatchesEmailNotJustUsername(): void
    {
        $this->requireSqlite();
        $this->seedUser('alpha', 'findme@example.com', verified: true);
        $this->seedUser('bravo', 'other@example.com', verified: true);

        $page = $this->ground()->pageflow('/admin/users', ['q' => 'findme']);

        self::assertCount(1, $page->prop('users'));
        self::assertSame('alpha', $page->prop('users.0.username'));
    }

    public function testVerifiedFilterNarrowsTheResultSetAgainstRealSql(): void
    {
        $this->requireSqlite();
        $this->seedUser('verifieduser', 'v1@example.com', verified: true);
        $this->seedUser('unverifieduser', 'u1@example.com', verified: false);

        $page = $this->ground()->pageflow('/admin/users', ['verified' => '0']);

        self::assertCount(1, $page->prop('users'));
        self::assertSame('unverifieduser', $page->prop('users.0.username'));
    }

    public function testSecretsNeverReachTheIndexPage(): void
    {
        $this->requireSqlite();
        $this->seedUser('someone', 'someone@example.com', verified: true);

        $page = $this->ground()->pageflow('/admin/users');

        $this->assertNotProp($page, 'users.0.passwordHash');
        $this->assertNotProp($page, 'users.0.rememberToken');
    }

    // ── Show: both halves, plus the new lockout prop ────────────────────────────

    public function testAdminShowRendersUserShowWithLockoutStatus(): void
    {
        $this->requireSqlite();
        $id = $this->seedUser('shownuser', 'shown@example.com', verified: true);

        $page = $this->ground()->pageflow("/admin/users/{$id}");

        $this->assertRendersPage($page, 'User/Show', 'admin');
        $this->assertPageProp($page, 'user.username', 'shownuser');
        // A fresh account with no failed logins is not locked out.
        $this->assertPageProp($page, 'locked', false);
    }

    public function testAdminShowReportsAnActualLockout(): void
    {
        $this->requireSqlite();
        $id = $this->seedUser('lockedout', 'lockedout@example.com', verified: true);

        // Drive the same lockout counter verifyCredentials() uses, through the
        // real service — 5 wrong-password attempts trips it.
        $users = $this->ground()->service(\Plugins\User\API\Contracts\UserServiceContract::class);
        for ($i = 0; $i < 5; $i++) {
            $users->verifyCredentials('lockedout', 'definitely-wrong-password');
        }

        $page = $this->ground()->pageflow("/admin/users/{$id}");

        $this->assertPageProp($page, 'locked', true);
    }

    public function testAdminShowHidesLockoutWhenViewerLacksUnlockPermission(): void
    {
        $this->requireSqlite();
        $dbFile = sys_get_temp_dir() . '/hkm-user-ground-' . bin2hex(random_bytes(6)) . '.sqlite';
        $id     = self::insertUser($dbFile, 'unprivileged', 'unpriv@example.com', true);

        // A second, independently-configured ground: this viewer has enough
        // permission to SEE the user (user:read-any) but not enough to see
        // lockout state (user:unlock) — proving the two are gated separately,
        // and that a missing permission degrades the page rather than 403ing
        // the whole thing.
        $ground = $this->bootGround(static fn(PluginGround $g): PluginGround =>
            $g->as(Identity::asUser('someone-else', permissions: ['user:read-any']))
                ->env(['DB_DRIVER' => 'sqlite', 'DB_DATABASE' => $dbFile]));

        try {
            $page = $ground->pageflow("/admin/users/{$id}");

            $this->assertPageProp($page, 'user.username', 'unprivileged');
            // Hidden, not wrongly reported as false — this viewer cannot see it.
            $this->assertPageProp($page, 'locked', null);
        } finally {
            $ground->destroy();
            unlink($dbFile);
        }
    }

    public function testUnknownUserRendersAnEmptyShowPageRatherThanErroring(): void
    {
        $this->requireSqlite();
        // No seedUser() call — but the query still runs against a real table,
        // so it must exist even with zero rows in it.
        self::createUsersTable($this->dbFile);

        $page = $this->ground()->pageflow('/admin/users/does-not-exist');

        $this->assertComponent('User/Show', $page);
        $this->assertPageProp($page, 'user', null);
        $this->assertPageProp($page, 'locked', null);
    }

    // ── Page-file resolution, independent of any one route ──────────────────────

    public function testBothAdminPagesResolveOnDisk(): void
    {
        $this->assertPageResolves('User/Index', 'admin');
        $this->assertPageResolves('User/Show', 'admin');
    }

    // ── Seeding ──────────────────────────────────────────────────────────────

    private function seedUser(string $username, string $email, bool $verified): string
    {
        return self::insertUser($this->dbFile, $username, $email, $verified);
    }

    private static function insertUser(string $file, string $username, string $email, bool $verified): string
    {
        self::createUsersTable($file);

        $id  = strtoupper(bin2hex(random_bytes(13)));
        $pdo = new \PDO('sqlite:' . $file);
        $stmt = $pdo->prepare(
            'INSERT INTO users
                (user_id, username, email, password_hash, version, email_verified_at, created_at, updated_at)
             VALUES (:id, :username, :email, :hash, 1, :verified_at, :now, :now)',
        );
        $stmt->execute([
            'id'           => $id,
            'username'     => $username,
            'email'        => $email,
            'hash'         => '$2y$12$' . str_repeat('a', 53),
            'verified_at'  => $verified ? '2026-01-01 00:00:00' : null,
            'now'          => '2026-01-01 00:00:00',
        ]);

        return $id;
    }

    private static function createUsersTable(string $file): void
    {
        $pdo = new \PDO('sqlite:' . $file);
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
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_remember_token ON users(remember_token)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_email_verif_token ON users(email_verification_token_hash)');
    }
}
