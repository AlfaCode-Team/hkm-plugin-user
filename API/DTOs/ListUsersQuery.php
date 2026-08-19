<?php

declare(strict_types=1);

namespace Plugins\User\API\DTOs;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;

/**
 * Keyset-pagination query for listing users.
 *
 *   GET /ajx/users?limit=50&after=<last user_id seen>&q=jane&verified=1
 *
 * Keyset (cursor) pagination is O(1) at any depth — unlike OFFSET, which scans
 * and skips. `after` is the opaque user_id of the last row from the previous
 * page (results are ordered by user_id DESC, which is time-ordered via ULID).
 *
 * `search` and `verified` narrow the WHERE clause without disturbing the
 * cursor mechanics — the ordering column (user_id) is untouched, so paging
 * through a filtered result set stays stable.
 */
final readonly class ListUsersQuery
{
    public const DEFAULT_LIMIT = 25;
    public const MAX_LIMIT     = 100;

    /** Matches the `email` column width — a longer term can never match anyway. */
    private const MAX_SEARCH_LENGTH = 150;

    public function __construct(
        public int $limit,
        public ?string $after,
        /** Case-insensitive substring match against username OR email. */
        public ?string $search = null,
        /** null = either state, true = verified only, false = unverified only. */
        public ?bool $verified = null,
    ) {}

    public static function fromRequest(Request $request): self
    {
        $limit = (int) $request->input('limit', self::DEFAULT_LIMIT);
        $limit = max(1, min($limit, self::MAX_LIMIT));

        $after = trim((string) $request->input('after', ''));
        // user_id is Crockford base32 ULID — reject anything else as a cursor.
        if ($after === '' || !preg_match('/^[0-9A-HJKMNP-TV-Z]{1,31}$/', $after)) {
            $after = null;
        }

        $search = trim((string) $request->input('q', ''));
        $search = $search === '' ? null : mb_substr($search, 0, self::MAX_SEARCH_LENGTH);

        $verifiedRaw = $request->input('verified');
        $verified = ($verifiedRaw === null || $verifiedRaw === '')
            ? null
            : filter_var($verifiedRaw, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);

        return new self(limit: $limit, after: $after, search: $search, verified: $verified);
    }
}
