<?php

declare(strict_types=1);

namespace Plugins\User\API\Support;

/**
 * The "pending email verification" binding — one browser-scoped marker shared by
 * every surface that participates in the verification flow.
 *
 * It carries a keyed HMAC of the address awaiting verification, so it says two
 * things at once without ever writing the address itself to the client:
 *
 *   1. THIS BROWSER HAS A PENDING VERIFICATION. A page whose only purpose is to
 *      finish a verification (/verify-email) can require the marker and turn
 *      away idle traffic that never registered and holds no emailed token.
 *   2. WHICH ADDRESS IT IS FOR. A resend must not become an open mail cannon:
 *      a browser bound to address A may only ask for a fresh link to A, and the
 *      comparison is constant-time against the hash rather than the address.
 *
 * It is deliberately NOT a credential: holding it proves nothing about inbox
 * control and grants no access. The verification TOKEN, delivered only by email,
 * remains the sole proof — this marker just decides who may see the form and
 * which address a resend may target.
 *
 * Arm it wherever a pending verification is created or re-confirmed:
 *   • public signup (a token was just emailed),
 *   • a sign-in refused because the address is unverified,
 *   • an expired-but-matched token presented on the verify page.
 *
 * @see \Plugins\User\Infrastructure\Http\Controllers\UserController
 */
final class VerificationBinding
{
    /** Cookie name. Queued via the CookieJar, so the stored value is encrypted. */
    public const COOKIE = 'vrf_bind';

    /** Lifetime in MINUTES — long enough to fetch a mail, short enough to lapse. */
    public const MINUTES = 30;

    /**
     * Keyed HMAC of a normalised address. APP_KEY makes it unforgeable by a
     * client, and hashing keeps the raw address off the wire even though the
     * cookie is already encrypted (defence in depth, and it keeps the cookie a
     * fixed 64 bytes regardless of address length).
     */
    public static function hash(string $email): string
    {
        return hash_hmac('sha256', mb_strtolower(trim($email)), (string) env('APP_KEY'));
    }

    /**
     * Whether a cookie value is a well-formed binding, WITHOUT knowing which
     * address it names — what a page gate needs ("is a verification pending in
     * this browser?"). Shape-only: it proves nothing on its own, which is why
     * this can never be the check that guards the verification itself.
     */
    public static function looksBound(?string $cookieValue): bool
    {
        return is_string($cookieValue) && (bool) preg_match('/^[a-f0-9]{64}$/', $cookieValue);
    }

    /** Constant-time match of a stored binding against a submitted address. */
    public static function matches(string $cookieValue, string $email): bool
    {
        return hash_equals($cookieValue, self::hash($email));
    }
}
