<?php

namespace App\Jobs;

use App\Lifecycle\LifecycleDispatcher;
use App\Models\LifecycleMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Send one lifecycle email.
 *
 * ## Why one message per job
 *
 * A batch job that sends fifty emails is a job where one bad address fails the other
 * forty-nine, where a retry re-sends the ones that worked, and where the queue's own
 * failed-job record names a batch rather than a customer. One message per job makes the
 * unit of retry the unit of delivery.
 *
 * ## Retries and backoff
 *
 * `$tries` and `backoff()` are the queue's own mechanism, and they are the right one:
 * the worker backs off, and the failed-job table keeps the exception for an operator.
 * `LifecycleDispatcher::attempt()` also schedules its own retry, so a message survives
 * the case where the job is never retried at all — the queue driver is `sync`, the
 * worker died, the job was dropped. The two are complementary rather than duplicated:
 * the job retries *now*, the sweep retries *later*.
 *
 * ## Idempotent by construction
 *
 * The dispatcher claims the row with a conditional status update, so a job delivered
 * twice sends once. This matters because a queue guarantees at-least-once delivery, not
 * exactly-once.
 */
class LifecycleSendMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries;

    public function __construct(public readonly int $messageId)
    {
        $this->tries = (int) config('lifecycle.queue.job_tries', 3);

        // Separate queue from anything a customer is waiting on: a marketing send must
        // never sit ahead of a payment receipt.
        $this->onQueue((string) config('lifecycle.queue.name', 'lifecycle'));
    }

    /** @return array<int,int> */
    public function backoff(): array
    {
        return (array) config('lifecycle.queue.backoff_seconds', [60, 300, 900]);
    }

    public function handle(LifecycleDispatcher $dispatcher): void
    {
        $message = LifecycleMessage::find($this->messageId);

        if (! $message) {
            // The row was deleted (the customer was, cascade). Nothing to send.
            return;
        }

        $result = $dispatcher->attempt($message);

        if ($result['status'] === 'failed' && $this->attempts() < $this->tries) {
            /*
             * Rethrown so the queue records the failure and applies its backoff.
             * Swallowing it would make the job look successful, and the failed-job
             * table is where an operator looks.
             */
            throw new \RuntimeException(
                'Lifecycle message ' . $this->messageId . ' failed: ' . (string) $message->fresh()?->status_message
            );
        }
    }

    public function failed(Throwable $e): void
    {
        // Reached only when the queue has given up. The row keeps its own record and
        // the sweep will pick it up while attempts remain.
        $message = LifecycleMessage::find($this->messageId);

        if ($message && ! $message->isTerminal()) {
            $message->forceFill([
                'status' => LifecycleMessage::STATUS_FAILED,
                'status_message' => mb_substr($e->getMessage(), 0, 500),
                'failed_at' => now(),
            ])->save();
        }
    }
}
