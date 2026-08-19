<?php

declare(strict_types=1);

namespace Plugins\User\API\DTOs;

use Plugins\Tenancy\API\DTOs\TenantSummary;
use Plugins\User\Domain\Entities\User;
use Plugins\User\Domain\Entities\UserProfile;

/**
 * Outward-facing representation of a user. Deliberately omits the password hash
 * and remember token — those credentials NEVER cross the API boundary.
 */
final readonly class UserDTO
{
    public function __construct(
        public string $id,
        public string $username,
        public string $email,
        public bool $emailVerified,
        public string $createdAt,
        public array $roles = [],
        public array $permissions = [],
        public ?string $tenantId = null,
        public ?string $joinedAt = null,
        // "First Last" from the TENANT user_profiles table. The central `users`
        // row carries no name, so this is '' until a tenant-aware flow attaches
        // it via withFullName() (e.g. UserService::find with a membership).
        public string $fullName = '',

        public ?string $avatarUrl = null,
    ) {
    }



    /**
     * $membership/$profile are composed here rather than stored on the entity —
     * the User aggregate stays pure (Domain has zero imports outside Domain/,
     * and TenantSummary is another plugin's published DTO). Callers that
     * resolved tenant membership/profile data for this user pass it in;
     * everyone else gets the '' / [] defaults.
     */
    public static function fromEntity(User $user, ?TenantSummary $membership = null, ?UserProfile $profile = null): self
    {
        return new self(
            id: $user->id(),
            username: $user->username(),
            email: $user->email(),
            fullName: $profile?->fullName() ?? '',
            avatarUrl: $profile?->avatarUrl(),
            emailVerified: $user->isEmailVerified(),
            roles: $membership !== null ? [$membership->role] : [],
            joinedAt: $membership?->joinedAt,
            tenantId: $membership?->tenantId,
            createdAt: $user->createdAt()->format(\DateTimeInterface::RFC3339),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'username' => $this->username,
            'email' => $this->email,
            'fullName' => $this->fullName,
            'emailVerified' => $this->emailVerified,
            'createdAt' => $this->createdAt,
            'avatarUrl' => $this->avatarUrl,
            'roles' => $this->roles,
            'permissions' => $this->permissions,
            'joinedAt' => $this->joinedAt,
            'tenantId' => $this->tenantId,
        ];
    }
}
