<?php

declare(strict_types=1);

namespace Plugins\User\Infrastructure\Http\Stages;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Response;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Http\Contracts\HttpStageContract;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\CachePort;
use Plugins\User\API\Contracts\UserServiceContract;

/**
 * Withhold ONE route from an account whose email address is not verified.
 *
 * This is the enforcement half of soft verification. Signing in no longer
 * requires a verified address (see User::canLogin()) — the account works, at a
 * reduced level — so something has to say which actions the reduced level
 * excludes. That something is this stage, named per route:
 *
 *   { "method": "POST", "path": "/things", "handler": "…@create",
 *     "filters": ["auth", "verified"] }
 *
 * DECLARATIVE ONLY, on purpose. There is no env path list and no global hook:
 * a route is gated because it said so, which keeps the decision next to the
 * route it applies to and leaves every other route paying nothing. Pair it with
 * `auth` — this stage answers "is the address proven?", never "who is this?".
 *
 * The refusal is the same shape the sign-in surface already speaks
 * (`auth.email_unverified` + `verifyUrl`), so a client that handles one handles
 * both; a genuine browser navigation is sent to the verification page instead,
 * because a raw 403 body is not a page.
 */
final class RequireVerifiedEmailStage implements HttpStageContract
{
    /** Where an unverified person is sent to finish the job. */
    private const VERIFY_URL = '/verify-email';

    /**
     * Verification is one-way and permanent, so a positive answer can be cached
     * hard. A NEGATIVE one never is: the moment someone verifies, the next
     * request must see it, and the requests that would benefit from a cached
     * "no" are exactly the ones being refused anyway.
     */
    private const VERIFIED_TTL = 900;
    private const CACHE_PREFIX = 'user:verified:';

    public function handle(Request $request, callable $next): Response
    {
        if (!in_array('verified', (array) $request->attribute('active_filters'), true)) {
            return $next($request);
        }

        $identity = $request->identity();
        if ($identity === null || $identity->isGuest()) {
            // Fail closed. Verification cannot be established without knowing
            // who is asking, and this stage never authenticates — that is the
            // `auth` filter's job, and a route wanting both must declare both.
            return Response::unauthorized('Authentication is required to access this resource.');
        }

        if ($this->isVerified($request, $identity->userId)) {
            return $next($request);
        }

        return $this->refuse($request);
    }

    /**
     * True when the account's address is confirmed. Unknown user → false.
     *
     * Dependencies come from the REQUEST container at handle time, not from a
     * constructor: the pipeline builds filter stages with no arguments.
     */
    private function isVerified(Request $request, string $userId): bool
    {
        $cache = $this->resolve($request, CachePort::class);
        $key   = self::CACHE_PREFIX . $userId;

        if ($cache instanceof CachePort && $cache->has($key)) {
            return true;
        }

        $users = $this->resolve($request, UserServiceContract::class);
        if (!$users instanceof UserServiceContract) {
            // The route gated itself on a plugin it did not load. Refusing every
            // request would be a silent outage; letting them through would make
            // the gate a no-op. Refuse — a route that asks for `verified` and
            // cannot check is a misconfiguration, and failing closed is the only
            // answer that cannot leak the action it was meant to withhold.
            return false;
        }

        // isAuth: true — an infrastructure read of the caller's OWN record, not
        // a user-facing lookup. The authorized path would happen to pass (the
        // identity IS the target), but relying on that would make this stage
        // break the day the check changes.
        if ($users->find($userId, false, true)?->emailVerified !== true) {
            return false;
        }

        $cache?->set($key, true, self::VERIFIED_TTL);

        return true;
    }

    /** @return object|null the binding, or null when unavailable in this request. */
    private function resolve(Request $request, string $id): ?object
    {
        $container = $request->container();
        if ($container === null || !$container->has($id)) {
            return null;
        }

        $resolved = $container->make($id);

        return is_object($resolved) ? $resolved : null;
    }

    /**
     * A page navigation gets the verification PAGE; anything programmatic gets
     * the machine-readable refusal. Same split, and same reasoning, as
     * SecurityFilters' RequireAuthStage: a Pageflow visit follows a redirect
     * client-side even though it is an XHR, and a full page load asks for HTML
     * without expecting JSON.
     */
    private function refuse(Request $request): Response
    {
        $isPageflow = (string) ($request->header('X-Pageflow') ?? '') !== '';
        $wantsHtml  = str_contains(strtolower((string) ($request->header('Accept') ?? '')), 'text/html');

        if ($isPageflow || ($wantsHtml && !$request->expectsJson())) {
            return Response::redirect(self::VERIFY_URL);
        }

        return Response::json([
            'error' => [
                'code'      => 'auth.email_unverified',
                'message'   => 'Verify your email address to use this feature. '
                    . 'Open the link we emailed you, or request a new one.',
                'verifyUrl' => self::VERIFY_URL,
            ],
        ], 403);
    }
}
