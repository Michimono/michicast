<?php

namespace App\Support;

use App\Enums\DeliveryStatus;
use App\Models\ContentDelivery;
use App\Models\Destination;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Spreads a destination's deliveries so a burst never exceeds its rate.
 *
 * Slots are at least window/limit apart, and a rolling window never holds
 * more than the limit. The destination row is locked while a slot is chosen
 * so two broadcasts cannot take the same time.
 */
class DestinationPacing
{
    public const QUEUE_FULL_REASON = 'The destination queue is full.';

    public function isActive(Destination $destination): bool
    {
        return $destination->rate_limit_count !== null
            && $destination->rate_limit_minutes !== null
            && $destination->rate_limit_count > 0
            && $destination->rate_limit_minutes > 0;
    }

    /**
     * Keep the delivery pending at the next free slot, or refuse it when the
     * waiting list is already at its cap. An inactive limit sends immediately.
     */
    public function reserve(Destination $destination, ContentDelivery $delivery): DestinationPace
    {
        return DB::transaction(function () use ($destination, $delivery): DestinationPace {
            $locked = Destination::query()->whereKey($destination->getKey())->lockForUpdate()->first();

            if (! $locked instanceof Destination || ! $this->isActive($locked)) {
                return DestinationPace::immediate();
            }

            if ($this->queueIsFull($locked, $delivery)) {
                return DestinationPace::full();
            }

            $slot = $this->nextSlot($locked, $delivery, now());
            $delivery->scheduled_for = $slot;
            $delivery->save();

            return DestinationPace::queued($slot);
        });
    }

    /**
     * Whether sending this delivery now would put the destination over its rate.
     * The caller holds the destination lock.
     */
    public function windowIsFull(Destination $destination, ContentDelivery $except, CarbonInterface $now): bool
    {
        if (! $this->isActive($destination)) {
            return false;
        }

        $start = $now->copy()->subSeconds($this->windowSeconds($destination));
        $limit = (int) $destination->rate_limit_count;

        $delivered = ContentDelivery::query()
            ->where('destination_id', $destination->getKey())
            ->where('status', DeliveryStatus::Delivered->value)
            ->where('finished_at', '>', $start)
            ->where('finished_at', '<=', $now)
            ->count();

        $inFlight = ContentDelivery::query()
            ->where('destination_id', $destination->getKey())
            ->where('status', DeliveryStatus::Pending->value)
            ->whereNull('scheduled_for')
            ->where('attempted_at', '>', $start)
            ->whereKeyNot($except->getKey())
            ->count();

        return ($delivered + $inFlight) >= $limit;
    }

    /**
     * The earliest time this delivery may send without passing the rate or
     * jumping ahead of one already waiting. The caller holds the destination lock.
     */
    public function nextSlot(Destination $destination, ContentDelivery $except, CarbonInterface $now): Carbon
    {
        $window = $this->windowSeconds($destination);
        $limit = max(1, (int) $destination->rate_limit_count);
        $interval = max(1, intdiv($window, $limit));
        $times = $this->occupiedTimes($destination, $except, $now);

        $slot = Carbon::instance($now);

        if ($times !== []) {
            $spaced = Carbon::instance($times[array_key_last($times)])->addSeconds($interval);

            if ($spaced->greaterThan($slot)) {
                $slot = $spaced;
            }
        }

        for ($guard = 0; $guard < 1000 && $this->occupantsAt($times, $slot, $window) >= $limit; $guard++) {
            $advanced = Carbon::instance($this->earliestAt($times, $slot, $window))->addSeconds($window);

            if (! $advanced->greaterThan($slot)) {
                $advanced = $slot->copy()->addSecond();
            }

            $slot = $advanced;
        }

        return $slot;
    }

    private function queueIsFull(Destination $destination, ContentDelivery $except): bool
    {
        $max = $destination->max_queue_size;

        if ($max === null) {
            return false;
        }

        $waiting = ContentDelivery::query()
            ->where('destination_id', $destination->getKey())
            ->where('status', DeliveryStatus::Pending->value)
            ->whereKeyNot($except->getKey())
            ->count();

        return $waiting >= $max;
    }

    /**
     * @return list<CarbonInterface>
     */
    private function occupiedTimes(Destination $destination, ContentDelivery $except, CarbonInterface $now): array
    {
        $start = $now->copy()->subSeconds($this->windowSeconds($destination));

        $rows = ContentDelivery::query()
            ->where('destination_id', $destination->getKey())
            ->whereKeyNot($except->getKey())
            ->where(function (Builder $query) use ($start): void {
                $query->where(function (Builder $query) use ($start): void {
                    $query->where('status', DeliveryStatus::Delivered->value)
                        ->where('finished_at', '>', $start);
                })->orWhere(function (Builder $query) use ($start): void {
                    $query->where('status', DeliveryStatus::Pending->value)
                        ->where(function (Builder $query) use ($start): void {
                            $query->whereNotNull('scheduled_for')
                                ->orWhere('attempted_at', '>', $start);
                        });
                });
            })
            ->get(['status', 'finished_at', 'scheduled_for', 'attempted_at']);

        $times = [];

        foreach ($rows as $row) {
            if ($row->status === DeliveryStatus::Delivered && $row->finished_at !== null) {
                $times[] = $row->finished_at->copy();

                continue;
            }

            if ($row->scheduled_for !== null) {
                $times[] = $row->scheduled_for->copy();

                continue;
            }

            $times[] = $row->attempted_at->copy();
        }

        usort($times, fn (CarbonInterface $left, CarbonInterface $right): int => $left <=> $right);

        return $times;
    }

    /**
     * @param  list<CarbonInterface>  $times
     */
    private function occupantsAt(array $times, CarbonInterface $slot, int $window): int
    {
        $start = $slot->copy()->subSeconds($window);
        $count = 0;

        foreach ($times as $time) {
            if ($time->greaterThan($start) && ! $time->greaterThan($slot)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @param  list<CarbonInterface>  $times
     */
    private function earliestAt(array $times, CarbonInterface $slot, int $window): CarbonInterface
    {
        $start = $slot->copy()->subSeconds($window);
        $earliest = null;

        foreach ($times as $time) {
            if ($time->greaterThan($start) && ! $time->greaterThan($slot) && ($earliest === null || $time->lessThan($earliest))) {
                $earliest = $time;
            }
        }

        return $earliest ?? $slot;
    }

    private function windowSeconds(Destination $destination): int
    {
        return max(1, (int) $destination->rate_limit_minutes) * 60;
    }
}
