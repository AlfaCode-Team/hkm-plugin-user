<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\User;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Plugins\User\API\DTOs\ListUsersQuery;

/**
 * Pure request-parsing coverage for ListUsersQuery::fromRequest(). The actual
 * WHERE-clause filtering lives in UserRepository::paginate() (real SQL against
 * a real DatabasePort) and isn't exercised here — this plugin has no fake that
 * simulates arbitrary SQL, so that half is verified by reading the query, not
 * by a test in this suite.
 */
#[CoversClass(ListUsersQuery::class)]
final class ListUsersQueryTest extends TestCase
{
    /**
     * FakeRequest only populates the BODY bag, and the kernel's Request reads
     * GET/HEAD input from the QUERY bag instead (getInputSource()) — so a real
     * GET simulation here would need a query-string helper FakeRequest doesn't
     * offer. The method a real /ajx/users request uses doesn't matter for what
     * this test targets (fromRequest()'s parsing), so POST is fine.
     */
    private function query(array $params): ListUsersQuery
    {
        return ListUsersQuery::fromRequest(FakeRequest::with($params));
    }

    public function test_defaults_when_nothing_is_provided(): void
    {
        $q = $this->query([]);

        $this->assertSame(ListUsersQuery::DEFAULT_LIMIT, $q->limit);
        $this->assertNull($q->after);
        $this->assertNull($q->search);
        $this->assertNull($q->verified);
    }

    public function test_limit_is_clamped_to_the_valid_range(): void
    {
        $this->assertSame(1, $this->query(['limit' => 0])->limit);
        $this->assertSame(1, $this->query(['limit' => -5])->limit);
        $this->assertSame(ListUsersQuery::MAX_LIMIT, $this->query(['limit' => 999])->limit);
    }

    public function test_a_malformed_cursor_is_dropped_rather_than_trusted(): void
    {
        $this->assertNull($this->query(['after' => 'not-a-ulid!'])->after);
        $this->assertNull($this->query(['after' => ''])->after);
        $this->assertSame('01J000000000000000000000A', $this->query(['after' => '01J000000000000000000000A'])->after);
    }

    public function test_search_term_is_trimmed_and_length_capped(): void
    {
        $this->assertNull($this->query(['q' => '   '])->search);
        $this->assertSame('jane', $this->query(['q' => '  jane  '])->search);
        $this->assertSame(150, mb_strlen($this->query(['q' => str_repeat('x', 500)])->search));
    }

    public function test_verified_accepts_common_truthy_and_falsy_forms(): void
    {
        $this->assertTrue($this->query(['verified' => '1'])->verified);
        $this->assertTrue($this->query(['verified' => 'true'])->verified);
        $this->assertFalse($this->query(['verified' => '0'])->verified);
        $this->assertFalse($this->query(['verified' => 'false'])->verified);
    }

    public function test_verified_is_null_when_absent_or_unrecognised(): void
    {
        $this->assertNull($this->query([])->verified);
        $this->assertNull($this->query(['verified' => ''])->verified);
        $this->assertNull($this->query(['verified' => 'maybe'])->verified);
    }
}
