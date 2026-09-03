<?php

declare(strict_types=1);

namespace Plugins\User\Application\Services;

use AlfacodeTeam\PhpServicePlatform\Kernel\Events\EventBus;
use Plugins\User\API\IntegrationEvents\GenericIntegrationEvent;
use Plugins\User\Infrastructure\Persistence\OutboxRepository;

/**
 * OutboxRelayService — drains pending rows from the outbox to the EventBus.
 *
 * Layering: this is the SERVICE that owns the delivery policy; it consumes the
 * {@see OutboxRepository} for all persistence and the {@see EventBus} for
 * dispatch — it never touches DatabasePort directly.
 *
 * Delivery is AT-LEAST-ONCE: an event is dispatched first, then marked
 * dispatched. If the process dies between the two, the row stays pending and is
 * re-sent on the next run — consumers must dedupe on the event_id (the UUID is
 * the idempotency key). After the repository's max attempts a row is parked as
 * failed.
 */
final class OutboxRelayService
{
    public function __construct(
        private readonly OutboxRepository $outbox,
        private readonly EventBus $eventBus,
    ) {}

    /** Relay up to $limit pending events. Returns the number dispatched. */
    public function relayBatch(int $limit = 100): int
    {
        $dispatched = 0;

        foreach ($this->outbox->pending($limit) as $row) {
            try {
                $payload = json_decode((string) $row['payload'], true, 512, JSON_THROW_ON_ERROR);

                $failures = $this->eventBus->dispatch(new GenericIntegrationEvent(
                    name:    (string) $row['event_name'],
                    version: (string) $row['event_version'],
                    payload: is_array($payload) ? $payload : [],
                ));

                // A listener that threw is NOT a delivery. dispatch() isolates
                // subscriber failures, and this line used to read that isolation
                // as success: the row was marked dispatched, never retried, and
                // the work the listener existed to do was lost — with
                // `status=1, attempts=1, last_error=NULL` recording a clean
                // delivery. That is exactly how a tenant membership went missing
                // while every table said the event was fine.
                //
                // Parking it as failed instead puts the row back in the retry
                // path, so the same event is redelivered once the cause is
                // fixed, and lands in the failed pile if it never is. Redelivery
                // is safe by contract: this outbox is at-least-once and its
                // consumers dedupe on the event id.
                if ($failures !== []) {
                    throw new \RuntimeException(self::describe($failures));
                }

                $this->outbox->markDispatched((int) $row['id']);
                $dispatched++;
            } catch (\Throwable $e) {
                $this->outbox->markFailed((int) $row['id'], (int) $row['attempts'] + 1, $e->getMessage());
            }
        }

        return $dispatched;
    }

    /**
     * One line naming every listener that failed and why — this is what lands in
     * the row's `last_error`, so it has to be enough to act on without a log.
     *
     * @param array<class-string, \Throwable> $failures
     */
    private static function describe(array $failures): string
    {
        $parts = [];
        foreach ($failures as $listener => $error) {
            $parts[] = $listener . ': ' . $error->getMessage();
        }

        return 'Listener(s) failed — ' . implode(' | ', $parts);
    }
}
