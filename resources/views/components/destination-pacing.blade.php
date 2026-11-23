@props(['count' => null, 'minutes' => null, 'queue' => null])

<div class="flex flex-col gap-5 border-t border-line pt-6">
    <div>
        <h3 class="text-base font-medium text-ink">Send pace</h3>
        <p class="mt-1 max-w-prose text-sm text-muted">Leave the rate empty to send each message as soon as it is ready. A limit holds messages and sends them in order, shared by every broadcast that uses this destination.</p>
    </div>

    <div class="grid gap-5 sm:grid-cols-3">
        <x-input
            name="rate_limit_count"
            type="number"
            label="Messages"
            size="lg"
            :value="$count"
            min="1"
            max="1000"
            inputmode="numeric"
            hint="How many may be sent in the window."
        />
        <x-input
            name="rate_limit_minutes"
            type="number"
            label="Minutes"
            size="lg"
            :value="$minutes"
            min="1"
            max="10080"
            inputmode="numeric"
            hint="Length of that window."
        />
        <x-input
            name="max_queue_size"
            type="number"
            label="Max waiting"
            size="lg"
            :value="$queue"
            min="1"
            max="100000"
            inputmode="numeric"
            hint="When this many are already waiting, the next message fails because the destination queue is full. Leave empty for no cap."
        />
    </div>
</div>
