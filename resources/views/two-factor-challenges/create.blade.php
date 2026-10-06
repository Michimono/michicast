<x-layout title="Two-factor authentication" centered :footer="false">
    <div class="flex flex-col items-center gap-5">
        <x-brand.mark variant="cat" class="h-16" />

        <div class="flex flex-col items-center gap-3">
            <h1 class="font-display text-3xl tracking-tight">Two-factor authentication</h1>

            <span class="house-fill h-0.5 w-16 rounded-full"></span>
        </div>
    </div>

    <x-card eared>
        <p class="text-sm text-muted">
            Enter a code from your authenticator app, or one of your recovery codes.
        </p>

        <form class="flex flex-col gap-5" method="POST" action="{{ route('two-factor-challenge.store') }}">
            @csrf

            <x-input name="code" label="Authentication code" autocomplete="one-time-code" autofocus required />

            <x-button class="w-full">Continue</x-button>
        </form>
    </x-card>
</x-layout>
