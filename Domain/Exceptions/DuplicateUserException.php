<?php

declare(strict_types=1);

namespace Plugins\User\Domain\Exceptions;

/**
 * Raised when a username/email already exists.
 *
 * The unique index is the real guard; this exception is how a TOCTOU race
 * surfaces cleanly.
 *
 * ─── WHY IT EXTENDS \RuntimeException ───────────────────────────────────────
 *
 * The Domain layer has zero imports outside Domain/, so it cannot reach for a
 * kernel exception. It also gains nothing by doing so: this previously extended
 * the kernel's DomainException with a docblock promising "HTTP 409/422", and
 * that promise was never kept — `ErrorStage::statusFor()` matches
 * ValidationException, ServiceException and GatewayException, and everything
 * else falls through to `default => 500`. Kernel DomainException is not in that
 * list, so the class produced exactly the generic 500 it was written to avoid.
 * (It was classified INFO severity, so it never paged anyone, which is why the
 * gap survived.)
 *
 * Deciding the HTTP shape is the Service layer's job. Catch this and rethrow a
 * ValidationException to get a real 422 with field errors:
 *
 *     } catch (DuplicateUserException $e) {
 *         throw new ValidationException(array_fill_keys($e->fields, 'Already taken.'));
 *     }
 */
final class DuplicateUserException extends \RuntimeException
{
    /** @param list<string> $fields */
    public function __construct(public readonly array $fields = ['username', 'email'])
    {
        parent::__construct('A user with that username or email already exists.');
    }
}
