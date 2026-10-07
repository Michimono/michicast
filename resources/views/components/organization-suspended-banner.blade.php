<div class="rounded-md border border-state-fail/30 bg-state-fail/5 px-4 py-3 text-sm text-state-fail" role="status">
    <p class="font-medium">This organization is suspended.</p>
    <p class="mt-1">You can still view it, but changes and broadcasts are disabled until an admin reinstates it.</p>
    @if (auth()->user()?->isPlatformAdmin() && filled($organization->suspension_reason))
        <p class="mt-2 font-mono text-xs text-muted">Reason: {{ $organization->suspension_reason }}</p>
    @endif
</div>