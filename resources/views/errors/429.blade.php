@php
    $retryAfter = (int) ($exception->getHeaders()['Retry-After'] ?? 60);
@endphp

<x-layout title="Slow down" centered>
    <x-card heading="Too many attempts" kicker="Slow down" eared>
        <p class="text-sm text-muted">
            You have used this action too often in a short time. Limits apply to you and to your whole organization, so nobody can exhaust a shared allowance.
        </p>

        <p class="text-sm text-ink" role="alert">
            Try again in {{ $retryAfter }} {{ str('second')->plural($retryAfter) }}.
        </p>

        <p>
            <x-button href="{{ url()->previous() }}">Go back</x-button>
        </p>
    </x-card>
</x-layout>
