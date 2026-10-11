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
        ['icon' => 'destination', 'label' => 'Destinations', 'description' => 'Telegram and more'],
    ];

    // Timezone picker: each option carries a friendly subtitle (offset and abbreviation).
    $zones = array_is_list($timezones) ? $timezones : array_keys($timezones);
    $selectedZone = (string) old('timezone', $timezone);
    $subtitle = static function (string $zone): string {
        try {
            $now = new DateTimeImmutable('now', new DateTimeZone($zone));
        } catch (Exception) {
            return '';
        }

        $offset = 'UTC'.$now->format('P');
        $abbreviation = $now->format('T');

        return ctype_alpha($abbreviation) && $abbreviation !== 'UTC' ? $offset.' · '.$abbreviation : $offset;
    };
    $timezoneDescribed = array_filter(['timezone-hint', $errors->has('timezone') ? 'timezone-error' : null]);
@endphp

{{--
    One card under the normal app header: intro, the workflow row and the form.
    A member adding another organization gets plainer copy and no workflow row.
--}}
<x-layout
    :title="$firstOrganization ? 'Create your organization' : 'New organization'"
    :footer="false"
>
    <x-card eared class="mx-auto w-full max-w-[45rem] shadow-[0_2px_4px_rgb(18_50_79/0.03),0_12px_32px_rgb(18_50_79/0.05)] sm:p-8">
        <div class="flex flex-col gap-6">
            <div class="flex flex-col items-start gap-3">
                @if ($firstOrganization)
                    <h1 class="font-display text-[1.75rem] leading-tight tracking-tight sm:text-3xl">
                        Good things happen when everything connects.
                    </h1>

                    <p class="text-sm text-muted sm:text-base">
                        Bring your sources together. Shape the content. Send it where it needs to go — automatically.
                    </p>
                @else
                    <h1 class="font-display text-3xl leading-tight tracking-tight">New organization</h1>

                    <p class="text-sm text-muted">Each organization keeps its own broadcasts, members and plan.</p>
                @endif
            </div>

            @if ($firstOrganization)
                <ol class="grid gap-3 sm:grid-cols-3 sm:gap-8" aria-label="How {{ config('app.name') }} works">
                    @foreach ($steps as $step)
                        <li class="relative flex items-start gap-3 rounded-xl bg-accent/5 p-4 sm:flex-col sm:gap-2">
                            <x-dynamic-component :component="'icons.'.$step['icon']" class="mt-0.5 shrink-0 text-accent sm:mt-0" />

                            <span class="flex flex-col gap-0.5">
                                <span class="text-sm font-semibold text-ink">{{ $step['label'] }}</span>
                                <span class="text-xs text-muted">{{ $step['description'] }}</span>
                            </span>

                            @unless ($loop->last)
                                <x-icons.arrow-right class="absolute top-1/2 -right-4 hidden translate-x-1/2 -translate-y-1/2 text-line-strong sm:block" />
                            @endunless
                        </li>
                    @endforeach
                </ol>
            @endif

            <section class="flex flex-col gap-5 border-t border-line pt-6" @if ($firstOrganization) aria-labelledby="create-organization-heading" @endif>
                @if ($firstOrganization)
                    <div class="flex flex-col gap-1.5">
                        <h2 id="create-organization-heading" class="font-display text-2xl tracking-tight">Create your organization</h2>

                        <p class="text-sm text-muted">
                            Broadcasts, sources and destinations all live inside an organization. Start with one; you can create more later.
                        </p>
                    </div>
                @endif

                <form
                    class="flex flex-col gap-5"
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
                        autocomplete="organization"
                        maxlength="255"
                        :autofocus="$focus === 'name'"
                        required
                    />

                    {{--
                        A real <select> stretched invisibly over a styled face. app.js swaps the
                        face's name for a searchable combobox that drives the select, so the
                        posted field stays native; without JS the select works as-is.
                    --}}
                    <div class="flex flex-col gap-2">
                        <label class="text-sm font-medium" for="timezone">Timezone</label>

                        <div
                            @class([
                                'relative flex h-14 items-center gap-3 rounded-lg border bg-ink/4 pr-11 pl-4 transition-colors has-[select:focus-visible]:border-accent has-[select:focus-visible]:bg-paper-raised has-[select:focus-visible]:outline-3 has-[select:focus-visible]:outline-offset-2 has-[select:focus-visible]:outline-accent/30 has-[input:focus]:border-accent has-[input:focus]:bg-paper-raised has-[input:focus]:outline-3 has-[input:focus]:outline-offset-2 has-[input:focus]:outline-accent/30',
                                'border-line hover:border-line-strong' => ! $errors->has('timezone'),
                                'border-state-fail' => $errors->has('timezone'),
                            ])
                            data-timezone-picker
                        >
                            <svg class="size-5 shrink-0 text-muted" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <circle cx="12" cy="12" r="8.5" />
                                <path d="M3.5 12h17" />
                                <path d="M12 3.5c2.3 2.4 3.5 5.2 3.5 8.5s-1.2 6.1-3.5 8.5c-2.3-2.4-3.5-5.2-3.5-8.5s1.2-6.1 3.5-8.5z" />
                            </svg>

                            <span class="flex min-w-0 flex-col leading-tight" aria-hidden="true">
                                <span class="truncate text-base text-ink" data-timezone-name>{{ $selectedZone }}</span>
                                <span class="truncate text-xs text-muted" data-timezone-subtitle>{{ $subtitle($selectedZone) }}</span>
                            </span>

                            <svg class="pointer-events-none absolute top-1/2 right-4 size-4 -translate-y-1/2 text-muted" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path d="m8 9 4-4 4 4" />
                                <path d="m8 15 4 4 4-4" />
                            </svg>

                            <select
                                id="timezone"
                                name="timezone"
                                @error('timezone') aria-invalid="true" @enderror
                                aria-describedby="{{ implode(' ', $timezoneDescribed) }}"
                                @if ($focus === 'timezone') autofocus="autofocus" @endif
                                class="absolute inset-0 size-full cursor-pointer appearance-none rounded-lg opacity-0"
                            >
                                @foreach ($zones as $zone)
                                    <option value="{{ $zone }}" @selected($zone === $selectedZone) data-subtitle="{{ $subtitle($zone) }}">{{ $zone }}</option>
                                @endforeach
                            </select>
                        </div>

                        <p id="timezone-hint" class="text-sm text-muted">Broadcast schedules use this timezone, regardless of who views them.</p>

                        @error('timezone')
                            <p id="timezone-error" class="text-sm text-state-fail">{{ $message }}</p>
                        @enderror
                    </div>

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

                <p class="text-center text-xs text-muted">You can change these details later.</p>
            </section>
        </div>
    </x-card>

    @if ($firstOrganization)
        <p class="text-center text-sm text-muted">A simpler way to keep everything connected.</p>
    @endif
</x-layout>
