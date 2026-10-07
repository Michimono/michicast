<x-layout title="Invitation">
    <x-card heading="Invitation" :kicker="$invitation->organization->name" eared class="max-w-md">
        @if ($invitation->isAccepted())
            <p class="text-sm text-ink">This invitation has already been used.</p>
        @elseif ($invitation->isExpired())
            <p class="text-sm text-ink">This invitation has expired.</p>
        @else
            <p class="text-sm text-ink">
                You were invited to {{ $invitation->organization->name }} as {{ $invitation->role->label() }}.
            </p>

            @auth
                @if (strtolower(auth()->user()->email) === $invitation->email)
                    <form method="POST" action="{{ route('invitation-acceptances.update', $token) }}">
                        @csrf
                        @method('PATCH')

                        <x-button>Accept invitation</x-button>
                    </form>
                @else
                    <p class="text-sm text-ink">
                        This invitation was sent to {{ $invitation->email }}. Log out and continue as that person.
                    </p>
                @endif
            @else
                <p class="text-sm text-muted">Log in or create an account to accept. Use the address this invitation was sent to.</p>

                <div class="flex flex-wrap gap-3">
                    <x-button href="{{ route('session.create') }}">Log in</x-button>
                    @if (app(\App\Support\RegistrationSettings::class)->acceptsRegistrations())
                        <x-button href="{{ route('users.create') }}" variant="ghost" :paw="false">Create account</x-button>
                    @endif
                </div>
            @endauth
        @endif
    </x-card>
</x-layout>
