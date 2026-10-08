@php
    // After a failed submit, focus lands on the form-level error or the first invalid field.
    $focus = match (true) {
        $errors->has('organization') => null,
        $errors->has('name') => 'name',
        $errors->has('timezone') => 'timezone',
        default => 'name',
    };
@endphp

<x-layout title="Create organization" centered :footer="false">
    <div class="flex flex-col items-center gap-5 text-center">
        <x-brand.mark variant="cat" class="h-16" />

        <div class="flex flex-col items-center gap-3">
            @if ($firstOrganization)
                <p class="text-xs font-medium uppercase tracking-widest text-muted">Welcome to {{ config('app.name') }}</p>
            @endif

            <h1 class="font-display text-3xl tracking-tight">
                {{ $firstOrganization ? 'Create your organization' : 'New organization' }}
            </h1>

            <span class="house-fill h-0.5 w-16 rounded-full"></span>
        </div>

        @if ($firstOrganization)
            <p class="text-sm text-muted">
                {{ config('app.name') }} picks up new posts from your sources and delivers them to your destinations on a schedule.
                Everything lives inside an organization, so start with one. You can invite your team once it exists.
            </p>
        @else
            <p class="text-sm text-muted">
                Each organization keeps its own broadcasts, members and plan.
            </p>
        @endif
    </div>

    <x-card eared>
        <form
            class="flex flex-col gap-6"
            method="POST"
            action="{{ route('organizations.store') }}"
            data-pending-form
        >
            @csrf

            <input type="hidden" name="submission" value="{{ old('submission', $submission) }}">

            @error('organization')
                <p
                    role="alert"
                    tabindex="-1"
                    data-focus-on-load
                    class="rounded-md border border-state-fail/30 bg-state-fail/5 px-4 py-3 text-sm text-state-fail"
                >{{ $message }}</p>
            @enderror

            <x-input
                name="name"
                label="Organization name"
                placeholder="e.g. Pokémon News"
                autocomplete="organization"
                maxlength="255"
                :autofocus="$focus === 'name'"
                required
            />

            <x-select
                name="timezone"
                label="Timezone"
                :value="$timezone"
                :options="$timezones"
                hint="Broadcast schedules use this timezone, regardless of who views them."
                :autofocus="$focus === 'timezone'"
            />

            <p class="sr-only" aria-live="polite" data-pending-status="Creating organization…"></p>

            <x-button class="w-full" data-pending-submit>
                {{-- Both labels share one grid cell so the button keeps its size while it waits. --}}
                <span class="grid">
                    <span class="col-start-1 row-start-1" data-pending-idle>Create organization</span>
                    <span class="invisible col-start-1 row-start-1" data-pending-busy>Creating organization…</span>
                </span>
            </x-button>
        </form>
    </x-card>
</x-layout>