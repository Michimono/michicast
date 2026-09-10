@if ($organizations->count() > 1)
    <form class="flex items-center gap-2" method="POST" action="{{ route('current-organization.update') }}">
        @csrf
        @method('PATCH')

        <label class="sr-only" for="organization">Organization</label>

        <select
            class="rounded-md border border-line bg-paper-raised px-2 py-1 text-sm text-ink focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-accent"
            id="organization"
            name="organization"
        >
            @foreach ($organizations as $option)
                <option value="{{ $option->id }}" @selected($option->is($organization))>{{ $option->name }}</option>
            @endforeach
        </select>

        <button class="text-sm text-muted transition-colors hover:text-ink" type="submit">Switch</button>
    </form>
@else
    <span class="text-sm text-muted">{{ $organization->name }}</span>
@endif
