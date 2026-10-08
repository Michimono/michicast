@php
    // After a failed submit, focus lands on the form-level error or the first invalid field.
    $focus = match (true) {
        $errors->has('organization') => null,
        $errors->has('name') => 'name',
        $errors->has('timezone') => 'timezone',
        default => 'name',
    };

    // Sources and destinations Michicast actually ships (config/michicast.php).
    $steps = [
        ['icon' => 'source', 'label' => 'Sources', 'description' => 'RSS, websites and more'],
        ['icon' => 'mapping', 'label' => 'Mapping', 'description' => 'Shape your content'],
        ['icon' => 'destination', 'label' => 'Destinations', 'description' => 'Telegram chats'],
    ];
@endphp

{{--
    A first organization gets the focused onboarding page: no app chrome, its own
    lockup, the workflow strip and a way out at the foot. A member adding another
    organization keeps the app header and a plainer form.
--}}
<x-layout
    :title="$firstOrganization ? 'Create your organization' : 'New organization'"
    :focused="$firstOrganization"
    :centered="! $firstOrganization"
    :footer="false"
>
    @if ($firstOrganization)
        <a class="mx-auto flex items-center gap-3 rounded-md focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-accent" href="{{ route('dashboard') }}">
            <x-brand.mark variant="face" class="h-8 shrink-0" />

            <span class="font-display text-2xl tracking-tight">{{ config('app.name') }}</span>
        </a>
    @endif

    <div class="flex flex-col items-center gap-5 text-center">
        <p class="inline-flex items-center gap-2 font-mono text-[0.6875rem] tracking-[0.12em] text-accent uppercase">
            <span class="house-fill size-1.5 rounded-full" aria-hidden="true"></span>
            {{ $firstOrganization ? 'Your workspace starts here' : 'Another workspace' }}
        </p>

        @if ($firstOrganization)
            <h1 class="max-w-xl font-display text-4xl leading-[1.1] tracking-tight text-balance sm:text-5xl">
                Good things happen when everything connects.
            </h1>

            <span class="house-fill h-0.5 w-16 rounded-full" aria-hidden="true"></span>

            <p class="max-w-lg text-base text-pretty text-muted sm:text-lg">
                Bring your sources together. Shape the content. Send it where it needs to go — automatically.
            </p>
        @else
            <h1 class="font-display text-4xl tracking-tight">New organization</h1>

            <span class="house-fill h-0.5 w-16 rounded-full" aria-hidden="true"></span>

            <p class="text-sm text-muted">Each organization keeps its own broadcasts, members and plan.</p>
        @endif
    </div>

    @if ($firstOrganization)
        <ol class="grid gap-3 min-[600px]:grid-cols-3 min-[600px]:gap-8" aria-label="How {{ config('app.name') }} works">
            @foreach ($steps as $step)
                <li class="relative flex items-center gap-4 rounded-card border border-line bg-paper-raised p-4 text-left min-[600px]:flex-col min-[600px]:gap-3 min-[600px]:border-0 min-[600px]:bg-transparent min-[600px]:p-0 min-[600px]:text-center">
                    <span class="grid size-11 shrink-0 place-items-center rounded-lg border border-line bg-paper-raised text-accent">
                        <x-dynamic-component :component="'icons.'.$step['icon']" />
                    </span>

                    <span class="flex flex-col gap-0.5">
                        <span class="text-sm font-medium text-ink">{{ $step['label'] }}</span>
                        <span class="text-sm text-muted">{{ $step['description'] }}</span>
                    </span>

                    @unless ($loop->last)
                        <x-icons.arrow-right class="absolute top-3 -right-4 hidden translate-x-1/2 text-line-strong min-[600px]:block" />
                    @endunless
                </li>
            @endforeach
        </ol>
    @endif

    <x-card eared class="mx-auto w-full max-w-[33.75rem] shadow-[0_2px_4px_rgb(18_50_79/0.03),0_12px_32px_rgb(18_50_79/0.05)] sm:p-8">
        <div class="flex flex-col gap-6">
            @if ($firstOrganization)
                <div class="flex flex-col gap-2">
                    <h2 class="font-display text-2xl tracking-tight">Create your organization</h2>

                    <p class="text-sm text-muted">
                        Broadcasts, sources and destinations all live inside an organization. Start with one; you can create more later.
                    </p>
                </div>
            @endif

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
                    size="lg"
                    placeholder="e.g. Pokémon News"
                    hint="Choose a name for the workspace where your broadcasts, sources and destinations will live."
                    autocomplete="organization"
                    maxlength="255"
                    :autofocus="$focus === 'name'"
                    required
                />

                <x-select
                    name="timezone"
                    label="Timezone"
                    size="lg"
                    :value="$timezone"
                    :options="$timezones"
                    hint="Broadcast schedules use this timezone, regardless of who views them."
                    :autofocus="$focus === 'timezone'"
                />

                <p class="sr-only" aria-live="polite" data-pending-status="Creating organization…"></p>

                <x-button class="w-full" size="lg" :paw="false" data-pending-submit>
                    {{-- Both labels share one grid cell so the button keeps its size while it waits. --}}
                    <span class="grid">
                        <span class="col-start-1 row-start-1 inline-flex items-center justify-center gap-2" data-pending-idle>
                            Create organization
                            <svg class="size-4 transition-transform group-hover:translate-x-0.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path d="M5 12h14" />
                                <path d="m13 6 6 6-6 6" />
                            </svg>
                        </span>
                        <span class="invisible col-start-1 row-start-1" data-pending-busy>Creating organization…</span>
                    </span>
                </x-button>
            </form>

            <p class="flex items-center justify-center gap-2 border-t border-line pt-5 text-sm text-muted">
                <span class="grid size-5 place-items-center rounded-full bg-state-ok/10 text-state-ok" aria-hidden="true">
                    <svg class="size-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <path d="m5 12.5 4.5 4.5L19 7.5" />
                    </svg>
                </span>
                You can change these details later.
            </p>
        </div>
    </x-card>

    @if ($firstOrganization)
        <footer class="flex flex-col items-center gap-2 text-center text-sm text-muted">
            <p>A simpler way to keep everything connected.</p>

            {{-- The app header is gone here, so the way out lives at the foot. --}}
            <form class="flex flex-wrap items-center justify-center gap-x-1.5" method="POST" action="{{ route('session.destroy') }}">
                @csrf
                @method('DELETE')

                <span>Signed in as {{ auth()->user()->email }}.</span>

                <button class="cursor-pointer underline hover:no-underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent" type="submit">
                    Log out
                </button>
            </form>
        </footer>
    @endif
</x-layout>
