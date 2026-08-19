<?php

declare(strict_types=1);

namespace Plugins\User\Application\Services;

use AlfacodeTeam\PhpServicePlatform\Kernel\Database\TransactionManager;
use AlfacodeTeam\PhpServicePlatform\Kernel\Events\Contracts\DomainEventContract;
use AlfacodeTeam\PhpServicePlatform\Kernel\Events\Contracts\IntegrationEventContract;
use AlfacodeTeam\PhpServicePlatform\Kernel\Events\DomainEventCollector;
use AlfacodeTeam\PhpServicePlatform\Kernel\Events\EventBus;
use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\SecurityException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\ServiceException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\ValidationException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\CachePort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\HashingPort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Identity;
use Plugins\Tenancy\API\Contracts\MembershipServiceContract;
use Plugins\Tenancy\API\DTOs\TenantSummary;
use Plugins\User\API\Contracts\UserServiceContract;
use Plugins\User\API\DTOs\ListUsersQuery;
use Plugins\User\API\DTOs\RegisterUserDTO;
use Plugins\User\API\DTOs\UpdateUserDTO;
use Plugins\User\API\DTOs\UserDTO;
use Plugins\User\API\DTOs\UserPage;
use Plugins\User\API\DTOs\VerifyEmailDTO;
use Plugins\User\API\DTOs\VerifyEmailResult;
use Plugins\User\API\IntegrationEvents\UserDeletedIntegrationEvent;
use Plugins\User\API\IntegrationEvents\UserRegisteredIntegrationEvent;
use Plugins\User\API\IntegrationEvents\UserUpdatedIntegrationEvent;
use Plugins\User\Application\Ports\BreachChecker;
use Plugins\User\Application\Ports\OutboxPort;
use Plugins\User\Application\Ports\UserStore;
use Plugins\User\Domain\Entities\User;
use Plugins\User\Domain\Entities\UserProfile;
use Plugins\User\Domain\Events\UserDeletedDomainEvent;
use Plugins\User\Domain\Events\UserRegisteredDomainEvent;
use Plugins\User\Domain\Events\UserUpdatedDomainEvent;
use Plugins\Audit\API\Contracts\AuditServiceContract;

/**
 * UserService — orchestrates the user.management domain.
 *
 * Security posture:
 *   - Passwords hashed via the crypto.services HashingPort (bcrypt); plaintext
 *     is never persisted, logged, or returned. Hashes upgrade transparently on
 *     login when the cost factor changes (rehash-on-login).
 *   - Authorization lives HERE: admins act on anyone; a non-admin only on their
 *     own record. Registration / credential verification are public.
 *   - Login is timing-safe AND rate-limited with per-identifier lockout
 *     (CachePort) to blunt credential stuffing.
 *   - Integration events are written to a transactional OUTBOX inside the same
 *     transaction as the state change (atomic, at-least-once delivery).
 *   - Security-relevant actions are audited (identifiers only, no PII).
 */
final class UserService implements UserServiceContract
{
    /** Constant-time decoy hash so login timing never reveals account existence. */
    private const DECOY_HASH = '$2y$12$xOniTd152PkIwSYuT8kYzeaxR/gfiv2IxUKxKUhOIPGAt6S1kFR3C';

    /** Lockout policy. */
    private const MAX_LOGIN_FAILURES = 5;
    private const LOCKOUT_WINDOW = 900; // seconds (15 min)

    public function __construct(
        private readonly UserStore $repository,
        private readonly TransactionManager $transaction,
        private readonly DomainEventCollector $collector,
        private readonly OutboxPort $outbox,
        private readonly EventBus $eventBus,
        private readonly HashingPort $hasher,
        private readonly Identity $identity,
        private readonly CachePort $cache,
        private readonly AuditServiceContract $audit,
        private readonly ?BreachChecker $breachChecker = null,
        private readonly ?string $tenantId = null,
        private readonly ?MembershipServiceContract $membership = null,
        // Tenant user_profiles read surface — attaches UserDTO.fullName when a
        // membership pins the tenant. Best-effort (the reader never throws).
        private readonly ?\Plugins\User\API\Contracts\TenantProfileReaderContract $profiles = null,
    ) {
    }

    public function list(ListUsersQuery $query): UserPage
    {
        // Listing every user is an admin-only capability.
        $this->requirePermission('user:list');

        [$users, $hasMore] = $this->repository->paginate($query);

        return new UserPage(
            items: array_map(static fn(User $u): UserDTO => UserDTO::fromEntity($u), $users),
            hasMore: $hasMore,
            limit: $query->limit,
        );
    }

    /** Verification token lifetime (seconds) — 24h. */
    private const VERIFICATION_TTL = 86400;

    /**
     * ADMIN / back-office registration. Returns the FULL user record so it can
     * be shown in an admin table. Still arms an email-verification token; when
     * you need the plaintext token to email, use registerPublic() instead.
     *
     * @throws SecurityException without `user:create` — this is the ONLY thing
     *         that distinguishes this from the public signup path; the route
     *         filter (`auth`) merely requires SOME authenticated identity.
     */
    public function register(RegisterUserDTO $dto): UserDTO
    {
        $this->requirePermission('user:create');

        [$user] = $this->provision($dto);
        return $user;
    }

    /**
     * PUBLIC self-signup. Returns ONLY the plaintext verification token for the
     * caller to email — never the identity record. A public registrant must not
     * receive their id/email/verification state back, so the controller responds
     * with a fixed "pending" status and this token stays server-side.
     */
    public function registerPublic(RegisterUserDTO $dto): string
    {
        [$_, $token] = $this->provision($dto);
        return $token;
    }

    /**
     * Confirm an email from the PUBLIC (unauthenticated) verification link. The
     * emailed token is matched by its stored SHA-256 hash and must not be
     * expired. One-time: verifyEmail() clears the token on success. Returns
     * false on any miss (unknown/expired/consumed) so a forged token reveals
     * nothing.
     */
    public function verifyEmailByToken(string $token): VerifyEmailResult
    {
        if ($token === '') {
            return VerifyEmailResult::invalid();
        }

        $user = $this->repository->findByVerificationTokenHash(hash('sha256', $token));
        if ($user === null) {
            $this->audit->record('user.email_verify.token_miss');
            return VerifyEmailResult::invalid();
        }

        // The token HASH matched a real pending user — so possession is already
        // proven. If it is merely expired we can safely say so (and steer the
        // holder to resend) without aiding enumeration: a forged/unknown token
        // never reaches this branch (it fails the hash lookup above → INVALID).
        $expiresAt = $user->emailVerificationExpiresAt();
        if ($expiresAt === null || $expiresAt < new \DateTimeImmutable()) {
            $this->audit->record('user.email_verify.token_expired', userId: $user->id());
            return VerifyEmailResult::expired($user->email());
        }

        // Proof of control established: a valid, unexpired token. Only now — when
        // the caller demonstrably holds the emailed secret — is it safe to
        // disclose that the account is already verified (the open resend form
        // never reveals this). The token hash survives verification precisely so
        // a second click resolves here instead of a confusing "invalid link".
        if ($user->isEmailVerified()) {
            $this->audit->record('user.email_verify.already', userId: $user->id());
            return VerifyEmailResult::already($user->email());
        }

        $this->collector->beginCollection();
        $this->transaction->begin();
        try {
            $user->verifyEmail();
            $user->commitChanges();
            $pending = $this->flushEvents($user);
            $this->repository->update($user);
            $this->transaction->commit();
        } catch (\Throwable $e) {
            $this->transaction->rollback();
            $this->collector->discard();
            throw $this->wrap($e, 'user.verify_email.failed', ['id' => $user->id()]);
        }

        $this->collector->release();
        $this->deliver($pending);
        $this->audit->record('user.email_verified', userId: $user->id());

        return VerifyEmailResult::ok();
    }

    /**
     * PUBLIC re-issue of a verification token. Enumeration-safe: returns null —
     * with no observable difference — when the email is unknown OR already
     * verified, so callers respond generically. When the account exists and is
     * unverified, a FRESH token is armed (replacing any pending one, so an old
     * link stops working) and its plaintext returned for the caller to email.
     */
    public function resendVerification(string $email): ?string
    {
        if ($email === '') {
            return null;
        }

        $user = $this->repository->findByIdentifier($email);

        if ($user === null) {
            $this->audit->record('user.email_verify.resend_miss');
            return null;
        }
        if ($user->isEmailVerified()) {
            // Nothing to send — an already-active account must not be re-armed.
            $this->audit->record('user.email_verify.resend_noop', userId: $user->id());
            return null;
        }

        // Same mechanism as signup: emailed once, only the SHA-256 stored,
        // time-boxed + one-time. Re-arming invalidates the previous token.
        $plainToken = bin2hex(random_bytes(32));
        $expiresAt = (new \DateTimeImmutable())->modify('+' . self::VERIFICATION_TTL . ' seconds');

        $this->transaction->begin();
        try {
            $user->startEmailVerification(hash('sha256', $plainToken), $expiresAt);
            $user->commitChanges();
            $this->repository->update($user);
            $this->transaction->commit();
        } catch (\Throwable $e) {
            $this->transaction->rollback();
            throw $this->wrap($e, 'user.verify_email.resend_failed', ['id' => $user->id()]);
        }

        $this->audit->record('user.email_verify.resent', userId: $user->id());

        return $plainToken;
    }

    /**
     * Shared registration core — arms a verification token and persists identity.
     *
     * @return array{0: UserDTO, 1: string} [record, plaintext verification token]
     */
    private function provision(RegisterUserDTO $dto): array
    {
        // Cheap pre-check for a friendly 422 before we hit the unique index;
        // the index + DuplicateUserException is the authoritative guard.
        if ($this->repository->existsByUsernameOrEmail($dto->username->value(), $dto->email->value())) {
            throw new ValidationException(['username' => 'Username or email is already taken.']);
        }

        $this->assertNotBreached($dto->password);

        // Emailed once; only its hash is stored. Time-boxed + one-time.
        $plainToken = bin2hex(random_bytes(32));
        $expiresAt = (new \DateTimeImmutable())->modify('+' . self::VERIFICATION_TTL . ' seconds');

        $this->collector->beginCollection();
        $this->transaction->begin();
        try {
            $user = User::register(
                username: $dto->username,
                email: $dto->email,
                passwordHash: $this->hasher->make($dto->password),
            );
            $user->startEmailVerification(hash('sha256', $plainToken), $expiresAt);

            // Persist the identity row FIRST, so the outbox event (and its
            // userId) is only written once the user actually exists — both land
            // in the same central transaction and commit atomically.
            $this->repository->insert($user);

            // Profile (if submitted) rides on the event for a tenant-side write;
            // it CANNOT join this central identity transaction (different DB).
            $pending = $this->flushEvents($user, $dto->tenantId, $dto->profile);
            $this->transaction->commit();
        } catch (\Throwable $e) {
            $this->transaction->rollback();
            $this->collector->discard();
            throw $this->wrap($e, 'user.register.failed');
        }

        $this->collector->release();
        $this->deliver($pending);
        $this->audit->record('user.registered', userId: $user->id());

        return [UserDTO::fromEntity($user), $plainToken];
    }

    public function find(string $id, bool $checkMembership = false, bool $isAuth = false): ?UserDTO
    {
        if (!$isAuth) {
            $this->requireSelfOrPermission($id, 'user:read-any');
        }

        $user = $this->repository->find($id);
        if ($user === null) {
            return null;
        }

        $membership = null;
        if ($checkMembership) {
            [$membership, $accessible] = $this->resolveMembership($user, 'user.login.no_membership', ['id' => self::pseudonymise($id)]);
            if (!$accessible) {
                return null;
            }
        }

        return UserDTO::fromEntity($user, $membership, $this->resolveProfile($user));
    }

    public function update(string $id, UpdateUserDTO $dto): ?UserDTO
    {
        $this->requireSelfOrPermission($id, 'user:update-any');

        $user = $this->repository->find($id);
        if ($user === null) {
            return null;
        }
        if (!$dto->hasChanges()) {
            return UserDTO::fromEntity($user);
        }

        $newUsername = $dto->username?->value() ?? $user->username();
        $newEmail = $dto->email?->value() ?? $user->email();
        if ($this->repository->existsByUsernameOrEmail($newUsername, $newEmail, exceptUserId: $id)) {
            throw new ValidationException(['username' => 'Username or email is already taken.']);
        }

        if ($dto->password !== null) {
            $this->assertNotBreached($dto->password);
        }

        $this->collector->beginCollection();
        $this->transaction->begin();
        try {
            if ($dto->username !== null) {
                $user->rename($dto->username);
            }
            if ($dto->email !== null) {
                $user->changeEmail($dto->email);
            }
            if ($dto->password !== null) {
                $user->changePassword($this->hasher->make($dto->password));
            }

            if (!$user->commitChanges()) {
                $this->transaction->rollback();
                $this->collector->discard();
                return UserDTO::fromEntity($user);
            }

            $pending = $this->flushEvents($user);
            $this->repository->update($user);
            $this->transaction->commit();
        } catch (\Throwable $e) {
            $this->transaction->rollback();
            $this->collector->discard();
            throw $this->wrap($e, 'user.update.failed', ['id' => $id]);
        }

        $this->collector->release();
        $this->deliver($pending);
        $this->audit->record('user.updated', userId: $id);

        return UserDTO::fromEntity($user);
    }

    public function verifyEmail(string $id, VerifyEmailDTO $dto): ?UserDTO
    {
        $this->requireSelfOrPermission($id, 'user:update-any');

        $user = $this->repository->find($id);
        if ($user === null) {
            return null;
        }

        // PROVE OWNERSHIP OF THE MAILBOX.
        //
        // $dto->token used to be accepted and never looked at, so the whole
        // check was "are you logged in as this user" — which says nothing about
        // whether the address is yours. Anyone could register with someone
        // else's address and self-verify it, defeating the entire point of
        // verification (and any downstream trust placed in a verified email,
        // such as password reset or social-account linking).
        //
        // An admin acting on another account is exempt: they are not proving
        // mailbox control, they are overriding it deliberately, and that is
        // already gated on user:update-any and audited below.
        if (!$this->actingOnBehalfOfAnother($id)) {
            $token = trim($dto->token);

            if ($token === '' || !$this->tokenBelongsTo($user, $token)) {
                throw $this->wrap(
                    new \RuntimeException('Invalid or expired verification token.'),
                    'user.verify_email.invalid_token',
                    ['id' => $id],
                );
            }
        }

        $this->collector->beginCollection();
        $this->transaction->begin();
        try {
            $user->verifyEmail();
            if (!$user->commitChanges()) {
                $this->transaction->rollback();
                $this->collector->discard();
                return UserDTO::fromEntity($user); // already verified — idempotent
            }

            $pending = $this->flushEvents($user);
            $this->repository->update($user);
            $this->transaction->commit();
        } catch (\Throwable $e) {
            $this->transaction->rollback();
            $this->collector->discard();
            throw $this->wrap($e, 'user.verify_email.failed', ['id' => $id]);
        }

        $this->collector->release();
        $this->deliver($pending);
        $this->audit->record('user.email_verified', userId: $id);

        return UserDTO::fromEntity($user);
    }

    /**
     * True when the caller is an ADMIN acting on someone else's account, rather
     * than the account owner acting on their own.
     */
    private function actingOnBehalfOfAnother(string $id): bool
    {
        return $this->identity->userId !== '' && $this->identity->userId !== $id;
    }

    /**
     * Does $token match the verification token stored for $user, and is it still
     * valid? Compared by SHA-256 hash with hash_equals, exactly as the public
     * link path does — the plaintext token is never stored.
     */
    private function tokenBelongsTo(object $user, string $token): bool
    {
        $found = $this->repository->findByVerificationTokenHash(hash('sha256', $token));

        if ($found === null || !hash_equals((string) $found->id(), (string) $user->id())) {
            return false;
        }

        $expiresAt = $found->emailVerificationExpiresAt();

        return $expiresAt !== null && $expiresAt >= new \DateTimeImmutable();
    }

    public function verifyCredentials(string $identifier, string $password): ?UserDTO
    {

        try {
            // 1. Lockout gate — refuse before any DB/hash work.
            if ($this->isLockedOut($identifier)) {
                $this->audit->record('user.login.locked_out', meta: ['id' => self::pseudonymise($identifier)]);
                return null;
            }
            $user = $this->repository->findByIdentifier($identifier);
            [$membership, $accessible] = $this->resolveMembership($user, 'user.login.no_membership', ['id' => self::pseudonymise($identifier)]);
            if (!$accessible) {
                return null;
            }

            // 2. Timing-safe: run a hash comparison even when the user is unknown.
            $hash = $user?->passwordHash() ?? self::DECOY_HASH;
            $ok = $this->hasher->check($password, $hash);


            if (!$ok || $user === null || !$user->canLogin()) {
                $this->recordLoginFailure($identifier);
                $this->audit->record('user.login.failed', meta: ['id' => self::pseudonymise($identifier)]);


                return null;
            }
            // 3. Success — clear failures and transparently upgrade the hash if the
            //    cost factor changed since it was created.
            $this->clearLoginFailures($identifier);
            if ($this->hasher->needsRehash($hash)) {
                try {
                    $this->repository->persistRehash($user->id(), $this->hasher->make($password));
                    $this->audit->record('user.password.rehashed', userId: $user->id());
                } catch (\Throwable) {
                    // A rehash failure must never block a valid login.
                }
            }


            return UserDTO::fromEntity($user, $membership, $this->resolveProfile($user));

        } catch (\Throwable $e) {
            throw $this->wrap($e, 'user.verify_credentials.failed');
        }

    }

    public function findByIdentifier(string $identifier, bool $checkMembership = false): ?UserDTO
    {
        if ($identifier === '') {
            return null;
        }

        $user = $this->repository->findByIdentifier($identifier);
        if ($user === null) {
            return null;
        }

        $membership = null;
        if ($checkMembership) {
            [$membership, $accessible] = $this->resolveMembership($user, 'user.login.no_membership', ['id' => self::pseudonymise($identifier)]);
            if (!$accessible) {
                return null;
            }
        }

        return UserDTO::fromEntity($user, $membership, $this->resolveProfile($user));
    }

    /** No authorization check by design — see the SECURITY note on the published contract. */
    public function resetPassword(string $userId, string $newPassword): bool
    {
        $user = $this->repository->find($userId);
        if ($user === null) {
            return false;
        }

        $this->assertNotBreached($newPassword);

        $this->collector->beginCollection();
        $this->transaction->begin();
        try {
            $user->changePassword($this->hasher->make($newPassword));
            $user->commitChanges();
            $pending = $this->flushEvents($user);
            $this->repository->update($user);
            // Invalidate outstanding "remember me" cookies after a reset.
            $this->repository->updateRememberToken($userId, null);
            $this->transaction->commit();
        } catch (\Throwable $e) {
            $this->transaction->rollback();
            $this->collector->discard();
            throw $this->wrap($e, 'user.password.reset_failed', ['id' => $userId]);
        }

        $this->collector->release();
        $this->deliver($pending);
        $this->audit->record('user.password.reset', userId: $userId);

        return true;
    }

    public function findByRememberToken(string $token): ?UserDTO
    {
        if ($token === '') {
            return null;
        }

        $user = $this->repository->findByRememberToken(hash('sha256', $token));
        if ($user === null || !$user->canLogin()) {
            return null;
        }

        [$membership, $accessible] = $this->resolveMembership($user, 'user.find_by_token.no_membership', ['id' => self::pseudonymise($token)]);
        if (!$accessible) {
            return null;
        }

        return UserDTO::fromEntity($user, $membership, $this->resolveProfile($user));
    }

    /**
     * @throws SecurityException when $checkMembership is true, the service is
     *         tenant-scoped, and this user has no active membership in it.
     */
    public function cycleRememberToken(string $userId, bool $checkMembership = false): string
    {
        if ($checkMembership) {
            $this->assertMembership($userId, 'user.remember_token.cycle.no_membership');
        }

        $plaintext = bin2hex(random_bytes(32));
        $this->repository->updateRememberToken($userId, hash('sha256', $plaintext));

        return $plaintext;
    }

    /** @throws SecurityException same condition as {@see cycleRememberToken()}. */
    public function clearRememberToken(string $userId, bool $checkMembership = false): void
    {
        if ($checkMembership) {
            $this->assertMembership($userId, 'user.remember_token.clear.no_membership');
        }

        $this->repository->updateRememberToken($userId, null);
    }

    public function delete(string $id, bool $checkMembership = false): bool
    {
        $this->requireSelfOrPermission($id, 'user:delete-any');

        $user = $this->repository->find($id);

        if ($user === null) {
            return false;
        }
        if ($checkMembership) {
            [, $accessible] = $this->resolveMembership($user, 'user.delete.no_membership', ['id' => self::pseudonymise($id)]);
            if (!$accessible) {
                return false;
            }
        }

        $this->collector->beginCollection();
        $this->transaction->begin();
        try {
            $deleted = $this->repository->delete($id);
            if (!$deleted) {
                $this->transaction->rollback();
                $this->collector->discard();
                return false;
            }

            $user->markDeleted();
            $pending = $this->flushEvents($user);
            $this->transaction->commit();
        } catch (\Throwable $e) {
            $this->transaction->rollback();
            $this->collector->discard();
            throw $this->wrap($e, 'user.delete.failed', ['id' => $id]);
        }

        $this->collector->release();
        if (count($pending) > 0) {
            $this->deliver($pending);
        }
        $this->audit->record('user.deleted', userId: $id);

        return true;
    }

    public function lockoutStatus(string $userId): bool
    {
        $this->requirePermission('user:unlock');

        $user = $this->repository->find($userId);
        if ($user === null) {
            return false;
        }

        // Login can be attempted with either identifier, and the lockout
        // counter is keyed by whichever string was actually submitted — so a
        // user is "locked out" if EITHER of their two identifiers is.
        return $this->isLockedOut($user->username()) || $this->isLockedOut($user->email());
    }

    public function clearLockout(string $userId): bool
    {
        $this->requirePermission('user:unlock');

        $user = $this->repository->find($userId);
        if ($user === null) {
            return false;
        }

        $this->clearLoginFailures($user->username());
        $this->clearLoginFailures($user->email());
        $this->audit->record('user.lockout.cleared', userId: $userId);

        return true;
    }

    // ─── internals ──────────────────────────────────────────────────────────

    /**
     * Collect the entity's domain events and write their integration
     * counterparts to the outbox — all inside the active transaction. Returns
     * the written rows keyed by outbox id so the caller can dispatch them
     * in-process after commit (see deliver()).
     *
     * @return array<int, IntegrationEventContract>
     */
    private function flushEvents(User $user, string $originTenant = '', array $profile = []): array
    {
        $pending = [];

        foreach ($user->releaseEvents() as $event) {
            $this->collector->collect($event);

            $integration = $this->toIntegration($event, $originTenant, $profile);
            if ($integration !== null) {
                $pending[$this->outbox->write($integration)] = $integration;
            }
        }

        return $pending;
    }

    /**
     * Dispatch the just-committed integration events in-process and mark their
     * outbox rows dispatched. The EventBus isolates listener failures, so a bad
     * subscriber never blocks the mark. The relay therefore only re-delivers
     * rows a crash between commit and dispatch left pending — at-least-once with
     * no double-fire on the happy path.
     *
     * @param array<int, IntegrationEventContract> $pending
     */
    private function deliver(array $pending): void
    {
        foreach ($pending as $id => $event) {
            $this->eventBus->dispatch($event);
            $this->outbox->markDispatched($id);
        }
    }

    private function toIntegration(DomainEventContract $event, string $originTenant = '', array $profile = []): ?IntegrationEventContract
    {
        return match (true) {
            $event instanceof UserRegisteredDomainEvent => new UserRegisteredIntegrationEvent(
                userId: $event->userId->value(),
                username: $event->username->value(),
                email: $event->email->value(),
                occurredAt: $event->occurredAt->format(\DateTimeInterface::RFC3339),
                tenantId: $originTenant,
                profile: $profile,
            ),
            $event instanceof UserUpdatedDomainEvent => new UserUpdatedIntegrationEvent(
                userId: $event->userId->value(),
                changed: $event->changed,
                occurredAt: $event->occurredAt->format(\DateTimeInterface::RFC3339),
            ),
            $event instanceof UserDeletedDomainEvent => new UserDeletedIntegrationEvent(
                userId: $event->userId->value(),
                occurredAt: $event->occurredAt->format(\DateTimeInterface::RFC3339),
            ),
            default => null,
        };
    }

    private function wrap(\Throwable $e, string $code, array $context = []): \Throwable
    {
        // Preserve typed domain/security/validation faults so the kernel maps
        // them to the right HTTP status (409/422/403) instead of a blanket 500.
        if (
            $e instanceof ServiceException
            || $e instanceof ValidationException
            || $e instanceof SecurityException
            || $e instanceof \AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\DomainException
            || $e instanceof \AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\OptimisticLockException
        ) {
            return $e;
        }

        return new ServiceException($code, layer: 'service.user', context: $context, previous: $e);
    }

    // ─── credential screening ────────────────────────────────────────────────

    /**
     * Reject a password known to appear in a breach corpus (NIST 800-63B).
     * No-op when screening is disabled. The checker itself fails open on a
     * provider outage, so this only ever throws on a confirmed breach hit.
     */
    private function assertNotBreached(string $plain): void
    {
        if ($this->breachChecker !== null && $this->breachChecker->isBreached($plain)) {
            throw new ValidationException([
                'password' => 'This password has appeared in a known data breach. Please choose a different one.',
            ]);
        }
    }

    // ─── authorization ──────────────────────────────────────────────────────

    private function requirePermission(string $permission): void
    {
        if (!$this->identity->hasPermission($permission)) {
            throw new SecurityException('user.forbidden', layer: 'service.user', context: ['permission' => $permission]);
        }
    }

    private function requireSelfOrPermission(string $targetUserId, string $permission): void
    {
        if ($this->identity->isGuest()) {
            throw new SecurityException('user.unauthenticated', layer: 'service.user');
        }
        if (hash_equals($this->identity->userId, $targetUserId)) {
            return;
        }
        $this->requirePermission($permission);
    }

    // ─── tenant read composition (membership / profile) ────────────────────────

    /**
     * Resolve this user's active membership in the current tenant, when the
     * service is tenant-scoped (both a MembershipServiceContract and a
     * tenantId are wired). Every caller that needs membership data for a
     * response, or needs to gate access on it, goes through here instead of
     * repeating the same lookup — it used to be copy-pasted across five
     * methods with subtly different edge-case handling.
     *
     * @param array<string, mixed> $auditMeta merged into the audit record when access is denied
     * @return array{0: ?TenantSummary, 1: bool} [membership, accessible]
     *         accessible=false means the service IS tenant-scoped and this
     *         user has NO active membership in it — the caller must treat
     *         this exactly like "not found" (denial is already audited here).
     *         accessible=true with membership=null means either membership
     *         checking isn't active for this request (no tenant context) or
     *         $user was null (nothing to check).
     */
    private function resolveMembership(?User $user, string $auditAction, array $auditMeta = []): array
    {
        if ($this->membership === null || $this->tenantId === null || $user === null) {
            return [null, true];
        }

        $membership = $this->membership->activeMember($user->id(), $this->tenantId);
        if ($membership === null) {
            $this->audit->record($auditAction, meta: [...$auditMeta, 'tenantId' => $this->tenantId]);
            return [null, false];
        }

        return [$membership, true];
    }

    /** @throws SecurityException when tenant-scoped and $userId has no active membership. */
    private function assertMembership(string $userId, string $auditAction): void
    {
        if ($this->membership === null || $this->tenantId === null) {
            return;
        }

        if ($this->membership->activeMember($userId, $this->tenantId) === null) {
            $this->audit->record($auditAction, meta: ['id' => self::pseudonymise($userId), 'tenantId' => $this->tenantId]);
            throw new SecurityException('user.membership_required', layer: 'service.user', context: ['tenantId' => $this->tenantId]);
        }
    }

    /** The tenant-side profile (full name, avatar) for a user, when a reader is wired. Best-effort. */
    private function resolveProfile(?User $user): ?UserProfile
    {
        if ($user === null || $this->profiles === null) {
            return null;
        }

        return $this->profiles->getProfile($user->id(), $this->tenantId ?? '');
    }

    // ─── lockout (CachePort) ─────────────────────────────────────────────────

    private function isLockedOut(string $identifier): bool
    {
        return (int) ($this->cache->get($this->lockKey($identifier)) ?? 0) >= self::MAX_LOGIN_FAILURES;
    }

    private function recordLoginFailure(string $identifier): void
    {
        $key = $this->lockKey($identifier);
        if (!$this->cache->has($key)) {
            $this->cache->set($key, 0, self::LOCKOUT_WINDOW);
        }
        $this->cache->increment($key);
    }

    private function clearLoginFailures(string $identifier): void
    {
        $this->cache->delete($this->lockKey($identifier));
    }

    private function lockKey(string $identifier): string
    {
        // Hash the identifier (PII). Identity is global (central users table).
        return 'user:login:fail:' . hash('sha256', mb_strtolower($identifier));
    }

    /** Short, non-reversible tag for audit logs (no raw email/username). */
    private static function pseudonymise(string $identifier): string
    {
        return substr(hash('sha256', mb_strtolower($identifier)), 0, 12);
    }
}
