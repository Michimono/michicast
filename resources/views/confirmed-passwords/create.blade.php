<x-layout title="Confirm password" centered :footer="false">
    <div class="flex flex-col items-center gap-5">
        <x-brand.mark variant="cat" class="h-16" />

        <div class="flex flex-col items-center gap-3">
            <h1 class="font-display text-3xl tracking-tight">Confirm your password</h1>

            <span class="house-fill h-0.5 w-16 rounded-full"></span>
        </div>
    </div>

    <x-card eared>
        <p class="text-sm text-muted">
            This is a sensitive area. Enter your password to continue.
        </p>

        <form class="flex flex-col gap-5" method="POST" action="{{ route('password.confirm.store') }}">
            @csrf

            <x-input name="password" label="Password" type="password" autocomplete="current-password" autofocus required />

            <x-button class="w-full">Confirm</x-button>
        </form>
    </x-card>
</x-layout>