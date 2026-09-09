<x-layout title="Change password">
    <x-card heading="Change your password" class="max-w-md">
        <form class="flex flex-col gap-4" method="POST" action="{{ route('profile.password.update') }}">
            @csrf
            @method('PATCH')

            <x-input name="current_password" label="Current password" type="password" autocomplete="current-password" required />
            <x-input name="password" label="New password" type="password" autocomplete="new-password" required />
            <x-input name="password_confirmation" label="Confirm new password" type="password" autocomplete="new-password" required />

            <x-button>Update password</x-button>
        </form>

        <p class="text-sm text-muted">
            <a class="text-accent underline hover:no-underline" href="{{ route('profile.edit') }}">Back to your profile</a>
        </p>
    </x-card>
</x-layout>
