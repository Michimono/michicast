<div class="rounded-md border border-state-fail/30 bg-state-fail/5 px-4 py-3 text-sm text-state-fail" role="status">
    <p class="font-medium">A usage quota is exhausted, so broadcasts may stop until it resets.</p>
    <p class="mt-1">
        <a
            class="text-accent underline hover:no-underline"
            href="{{ route('organization-settings.edit', $organization) }}#usage"
        >View usage</a>
    </p>
</div>