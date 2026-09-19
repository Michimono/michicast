{{--
    Social sign-in has no backend yet. Both providers are real disabled buttons
    rather than links styled grey, so they cannot be followed, and one visible
    line says why instead of leaving the state to colour alone.
--}}
@php
    $classes = 'flex items-center justify-center gap-2 rounded-md border border-line bg-paper-raised px-3 py-2 text-sm font-medium text-muted opacity-60 grayscale cursor-not-allowed';
@endphp

<div>
    <div class="flex items-center gap-4">
        <span class="h-px flex-1 bg-line"></span>

        <span class="text-sm text-muted">Or continue with</span>

        <span class="h-px flex-1 bg-line"></span>
    </div>

    <div class="mt-4 grid grid-cols-2 gap-3">
        <button type="button" disabled aria-describedby="social-auth-unavailable" class="{{ $classes }}">
            <x-icons.google />

            Google
        </button>

        <button type="button" disabled aria-describedby="social-auth-unavailable" class="{{ $classes }}">
            <x-icons.github />

            GitHub
        </button>
    </div>

    <p id="social-auth-unavailable" class="mt-3 text-center text-sm text-muted">
        Google and GitHub sign-in are not available yet.
    </p>
</div>
