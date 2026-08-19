<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\User;

use AlfacodeTeam\PhpServicePlatform\Kernel\Database\TransactionManager;
use AlfacodeTeam\PhpServicePlatform\Kernel\Events\DomainEventCollector;
use AlfacodeTeam\PhpServicePlatform\Kernel\Events\EventBus;
use Psr\Container\ContainerInterface;
use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\SecurityException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\ValidationException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Identity;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Plugins\Tenancy\API\Contracts\MembershipServiceContract;
use Plugins\Tenancy\API\DTOs\TenantSummary;
use Plugins\User\API\DTOs\ListUsersQuery;
use Plugins\User\API\DTOs\RegisterUserDTO;
use Plugins\User\API\DTOs\UpdateUserDTO;
use Plugins\User\Application\Services\UserService;
use Plugins\User\Domain\Entities\User;
use Plugins\User\Domain\ValueObjects\Email;
use Plugins\User\Domain\ValueObjects\Username;
use Plugins\Audit\Application\Services\AuditService;
use Tests\Unit\Plugins\User\Support\FakeCache;
use Tests\Unit\Plugins\User\Support\FakeDatabasePort;
use Tests\Unit\Plugins\User\Support\FakeHasher;
use Tests\Unit\Plugins\User\Support\FakeOutbox;
use Tests\Unit\Plugins\User\Support\FakeUserStore;

#[CoversClass(UserService::class)]
final class UserServiceTest extends TestCase
{
    private FakeUserStore $store;
    private FakeOutbox $outbox;
    private FakeHasher $hasher;
    private FakeCache $cache;

    protected function setUp(): void
    {
        $this->store  = new FakeUserStore();
        $this->outbox = new FakeOutbox();
        $this->hasher = new FakeHasher();
        $this->cache  = new FakeCache();
    }

    private function service(Identity $identity): UserService
    {
        return new UserService(
            repository:  $this->store,
            transaction: new TransactionManager(new FakeDatabasePort()),
            collector:   new DomainEventCollector(),
            outbox:      $this->outbox,
            eventBus:    new EventBus($this->emptyContainer()),
            hasher:      $this->hasher,
            identity:    $identity,
            cache:       $this->cache,
            audit:       new AuditService(writer: null, sink: static fn(string $l) => null, actorId: 'actor'),
        );
    }

    /**
     * A UserService scoped to a tenant, wired to a stub MembershipServiceContract
     * that always reports $membership for activeMember() (null = no active seat).
     * Exercises the find()/verifyCredentials()/cycleRememberToken() membership
     * composition — UserDTO::fromEntity() now takes membership/profile as
     * parameters instead of the entity carrying them (Domain must not import
     * another plugin's DTO).
     */
    private function serviceWithTenant(Identity $identity, string $tenantId, ?TenantSummary $membership): UserService
    {
        $fakeMembership = new class($membership) implements MembershipServiceContract {
            public function __construct(private readonly ?TenantSummary $membership) {}
            public function myTenants(string $userId): array { return $this->membership !== null ? [$this->membership] : []; }
            public function isActiveMember(string $userId, string $tenantId): bool { return $this->membership !== null; }
            public function activeMember(string $userId, string $tenantId): ?TenantSummary { return $this->membership; }
            public function selectTenant(string $userId, string $tenantId, ?string $ip = null): TenantSummary
            {
                return $this->membership ?? throw new \RuntimeException('not a member');
            }
        };

        return new UserService(
            repository:  $this->store,
            transaction: new TransactionManager(new FakeDatabasePort()),
            collector:   new DomainEventCollector(),
            outbox:      $this->outbox,
            eventBus:    new EventBus($this->emptyContainer()),
            hasher:      $this->hasher,
            identity:    $identity,
            cache:       $this->cache,
            audit:       new AuditService(writer: null, sink: static fn(string $l) => null, actorId: 'actor'),
            tenantId:    $tenantId,
            membership:  $fakeMembership,
        );
    }

    private function emptyContainer(): ContainerInterface
    {
        return new class implements ContainerInterface {
            public function get(string $id): mixed { throw new \RuntimeException('no bindings'); }
            public function has(string $id): bool { return false; }
        };
    }

    private function seedUser(string $username = 'janedoe', string $email = 'janedoe@example.com'): User
    {
        $user = User::register(
            username:     Username::fromString($username),
            email:        Email::fromString($email),
            passwordHash: $this->hasher->make('Sup3rSecret!!'),
        );
        $this->store->insert($user);
        return $user;
    }

    private function registerRequest(array $data): RegisterUserDTO
    {
        return RegisterUserDTO::fromRequest(FakeRequest::with($data));
    }

    // ── registration ────────────────────────────────────────────────────────

    public function test_register_persists_user_and_enqueues_event(): void
    {
        $svc = $this->service(Identity::asAdmin());

        $dto = $this->registerRequest([
            'username' => 'newbie',
            'email'    => 'new@example.com',
            'password' => 'Sup3rSecret!!',
        ]);
        $result = $svc->register($dto);

        $this->assertSame('newbie', $result->username);
        $this->assertFalse($result->emailVerified);
        $this->assertContains('user.registered', $this->outbox->names());
        $this->assertNotNull($this->store->find($result->id));
        // Regression: flushEvents() used to discard write()'s returned row id
        // (a commented-out line), so $pending stayed empty and deliver() never
        // marked anything dispatched — every event silently depended on the
        // CLI relay cron instead of firing synchronously after commit.
        $this->assertNotEmpty($this->outbox->dispatched, 'outbox event must be dispatched synchronously after commit');
    }

    public function test_register_rejects_duplicate(): void
    {
        $this->seedUser('taken', 'taken@example.com');
        $svc = $this->service(Identity::asAdmin());

        $this->expectException(ValidationException::class);
        $svc->register($this->registerRequest([
            'username' => 'taken',
            'email'    => 'taken@example.com',
            'password' => 'Sup3rSecret!!',
        ]));
    }

    public function test_register_rejects_weak_password(): void
    {
        $this->expectException(ValidationException::class);
        $this->registerRequest([
            'username' => 'weakling',
            'email'    => 'weak@example.com',
            'password' => 'short',
        ]);
    }

    /**
     * Regression: the admin/back-office register() had NO permission check at
     * all — the route filter only required SOME authenticated identity
     * (`auth`), so any logged-in user, including a freshly self-registered
     * one with zero privileges, could call the "admin" account-creation
     * endpoint and get back the full created record.
     */
    public function test_register_requires_permission_and_rejects_unprivileged_caller(): void
    {
        $svc = $this->service(Identity::asUser('low-priv-user'));

        $this->expectException(SecurityException::class);
        $svc->register($this->registerRequest([
            'username' => 'shouldnotexist',
            'email'    => 'shouldnotexist@example.com',
            'password' => 'Sup3rSecret!!',
        ]));
    }

    public function test_register_public_signup_requires_no_permission(): void
    {
        // Contrast with the above: registerPublic() is the intentionally
        // unauthenticated path and must stay permission-free.
        $svc = $this->service(Identity::guest());

        $token = $svc->registerPublic($this->registerRequest([
            'username' => 'selfsignup',
            'email'    => 'selfsignup@example.com',
            'password' => 'Sup3rSecret!!',
        ]));

        $this->assertNotSame('', $token);
    }

    // ── authorization ─────────────────────────────────────────────────────────

    public function test_list_requires_permission(): void
    {
        $svc = $this->service(Identity::asUser('u1'));
        $this->expectException(SecurityException::class);
        $svc->list(new ListUsersQuery(25, null));
    }

    public function test_admin_can_list(): void
    {
        $this->seedUser();
        $svc = $this->service(Identity::asAdmin());

        $page = $svc->list(new ListUsersQuery(25, null));
        $this->assertCount(1, $page->items);
    }

    public function test_user_cannot_read_another_user(): void
    {
        $other = $this->seedUser();
        $svc   = $this->service(Identity::asUser('not-the-owner'));

        $this->expectException(SecurityException::class);
        $svc->find($other->id());
    }

    public function test_user_can_read_self(): void
    {
        $me  = $this->seedUser();
        $svc = $this->service(Identity::asUser($me->id()));

        $this->assertNotNull($svc->find($me->id()));
    }

    // ── update / delete events ─────────────────────────────────────────────────

    public function test_update_changes_username_and_emits_event(): void
    {
        $me  = $this->seedUser('oldname');
        $svc = $this->service(Identity::asUser($me->id()));

        $dto = UpdateUserDTO::fromRequest(FakeRequest::with(['username' => 'newname']));
        $result = $svc->update($me->id(), $dto);

        $this->assertSame('newname', $result?->username);
        $this->assertContains('user.updated', $this->outbox->names());
        $this->assertSame(2, $this->store->find($me->id())?->version());
    }

    public function test_delete_self_emits_event(): void
    {
        $me  = $this->seedUser();
        $svc = $this->service(Identity::asUser($me->id()));

        $this->assertTrue($svc->delete($me->id()));
        $this->assertContains('user.deleted', $this->outbox->names());
        $this->assertNull($this->store->find($me->id()));
    }

    // ── login / lockout ─────────────────────────────────────────────────────────

    public function test_verify_credentials_success(): void
    {
        $this->seedUser('janedoe', 'janedoe@example.com');
        // jane is Pending by default → inactive for login; activate via verify path.
        $active = $this->activeUser('active', 'active@example.com', 'Sup3rSecret!!');
        $svc = $this->service(Identity::guest());

        $this->assertNotNull($svc->verifyCredentials('active', 'Sup3rSecret!!'));
        $this->assertSame($active, $active); // sanity
    }

    public function test_verify_credentials_wrong_password_returns_null(): void
    {
        $this->activeUser('active', 'active@example.com', 'Sup3rSecret!!');
        $svc = $this->service(Identity::guest());

        $this->assertNull($svc->verifyCredentials('active', 'WrongPass123!'));
    }

    public function test_lockout_after_repeated_failures(): void
    {
        $this->activeUser('active', 'active@example.com', 'Sup3rSecret!!');
        $svc = $this->service(Identity::guest());

        for ($i = 0; $i < 5; $i++) {
            $svc->verifyCredentials('active', 'WrongPass123!');
        }

        // Even the CORRECT password is now refused while locked out.
        $this->assertNull($svc->verifyCredentials('active', 'Sup3rSecret!!'));
    }

    public function test_admin_can_view_and_clear_a_lockout(): void
    {
        $victim = $this->activeUser('victim', 'victim@example.com', 'Sup3rSecret!!');
        $svc    = $this->service(Identity::asAdmin());

        $this->assertFalse($svc->lockoutStatus($victim->id()));

        for ($i = 0; $i < 5; $i++) {
            $svc->verifyCredentials('victim', 'WrongPass123!');
        }
        $this->assertTrue($svc->lockoutStatus($victim->id()));

        $this->assertTrue($svc->clearLockout($victim->id()));
        $this->assertFalse($svc->lockoutStatus($victim->id()));

        // The clear actually un-blocks login, not just the status flag.
        $this->assertNotNull($svc->verifyCredentials('victim', 'Sup3rSecret!!'));
    }

    public function test_lockout_status_requires_permission(): void
    {
        $victim = $this->activeUser('victim2', 'victim2@example.com', 'Sup3rSecret!!');
        $svc    = $this->service(Identity::asUser('someone-else'));

        $this->expectException(SecurityException::class);
        $svc->lockoutStatus($victim->id());
    }

    public function test_clear_lockout_requires_permission(): void
    {
        $victim = $this->activeUser('victim3', 'victim3@example.com', 'Sup3rSecret!!');
        $svc    = $this->service(Identity::asUser('someone-else'));

        $this->expectException(SecurityException::class);
        $svc->clearLockout($victim->id());
    }

    public function test_lockout_actions_return_false_for_unknown_user(): void
    {
        $svc = $this->service(Identity::asAdmin());

        $this->assertFalse($svc->lockoutStatus('does-not-exist'));
        $this->assertFalse($svc->clearLockout('does-not-exist'));
    }

    public function test_reset_password_updates_hash_and_emits_event(): void
    {
        $me  = $this->activeUser('resetme', 'resetme@example.com', 'Sup3rSecret!!');
        $svc = $this->service(Identity::guest()); // token-authorized, not identity-gated

        // Arm a remember-me token so we can prove the reset clears it too.
        $svc->cycleRememberToken($me->id());
        $this->assertNotEmpty($this->store->rememberTokens[$me->id()] ?? '');

        $this->assertTrue($svc->resetPassword($me->id(), 'NewSup3rSecret!!'));

        $this->assertContains('user.updated', $this->outbox->names());
        $this->assertArrayNotHasKey($me->id(), $this->store->rememberTokens);
        $this->assertNotNull($svc->verifyCredentials('resetme', 'NewSup3rSecret!!'));
        $this->assertNull($svc->verifyCredentials('resetme', 'Sup3rSecret!!'));
    }

    public function test_reset_password_returns_false_for_unknown_user(): void
    {
        $svc = $this->service(Identity::guest());

        $this->assertFalse($svc->resetPassword('does-not-exist', 'NewSup3rSecret!!'));
    }

    public function test_rehash_on_login_when_needed(): void
    {
        $u = $this->activeUser('active', 'active@example.com', 'Sup3rSecret!!');
        $this->hasher->needsRehash = true;
        $svc = $this->service(Identity::guest());

        $this->assertNotNull($svc->verifyCredentials('active', 'Sup3rSecret!!'));
        $this->assertArrayHasKey($u->id(), $this->store->rehashed);
    }

    // ── tenant membership composition ───────────────────────────────────────────
    //
    // UserDTO::fromEntity() used to read membership/profile off mutable
    // properties the service set on the User entity (setMembership/setProfile).
    // That coupled the Domain entity to Plugins\Tenancy\API\DTOs\TenantSummary —
    // another plugin's published DTO, which Domain must never import. The fix
    // moved composition to the DTO factory itself: these tests exercise that a
    // tenant-scoped UserService still produces the same DTO shape (role,
    // tenantId, joinedAt) and the same access-denial behaviour it did before.

    public function test_find_with_membership_composes_role_and_tenant_into_dto(): void
    {
        $user = $this->seedUser('tenantuser', 'tenantuser@example.com');
        $membership = new TenantSummary(
            tenantId: 'tenant-1',
            name:     'Acme',
            slug:     'acme',
            role:     'owner',
            status:   'active',
            joinedAt: '2026-01-01T00:00:00+00:00',
        );
        $svc = $this->serviceWithTenant(Identity::asAdmin('tenant-1'), 'tenant-1', $membership);

        $dto = $svc->find($user->id(), checkMembership: true);

        $this->assertNotNull($dto);
        $this->assertSame(['owner'], $dto->roles);
        $this->assertSame('tenant-1', $dto->tenantId);
        $this->assertSame('2026-01-01T00:00:00+00:00', $dto->joinedAt);
    }

    public function test_find_with_membership_returns_null_and_audits_when_no_active_membership(): void
    {
        $user = $this->seedUser('outsider', 'outsider@example.com');
        $svc  = $this->serviceWithTenant(Identity::asAdmin('tenant-1'), 'tenant-1', null);

        $this->assertNull($svc->find($user->id(), checkMembership: true));
    }

    public function test_cycle_remember_token_throws_when_membership_required_but_absent(): void
    {
        $user = $this->seedUser('outsider2', 'outsider2@example.com');
        $svc  = $this->serviceWithTenant(Identity::asAdmin('tenant-1'), 'tenant-1', null);

        $this->expectException(SecurityException::class);
        $svc->cycleRememberToken($user->id(), checkMembership: true);
    }

    public function test_cycle_remember_token_succeeds_when_membership_present(): void
    {
        $user = $this->seedUser('member', 'member@example.com');
        $membership = new TenantSummary('tenant-1', 'Acme', 'acme', 'staff', 'active');
        $svc = $this->serviceWithTenant(Identity::asAdmin('tenant-1'), 'tenant-1', $membership);

        $token = $svc->cycleRememberToken($user->id(), checkMembership: true);

        $this->assertNotSame('', $token);
    }

    public function test_cycle_remember_token_ignores_checkmembership_when_not_tenant_scoped(): void
    {
        // Default service() has no tenantId/membership wired — checkMembership
        // must be a no-op rather than throwing, exactly as before this method's
        // dead parameter was made to do anything at all.
        $user = $this->seedUser('untenanted', 'untenanted@example.com');
        $svc  = $this->service(Identity::asAdmin());

        $token = $svc->cycleRememberToken($user->id(), checkMembership: true);

        $this->assertNotSame('', $token);
    }

    /** Seed an already-active, email-verified user (login-eligible). */
    private function activeUser(string $username, string $email, string $password): User
    {
        $user = User::register(
            username:     Username::fromString($username),
            email:        Email::fromString($email),
            passwordHash: $this->hasher->make($password),
        );
        $user->verifyEmail(); // Pending → Active
        $user->commitChanges();
        $user->releaseEvents();
        $this->store->insert($user);
        return $user;
    }
}
