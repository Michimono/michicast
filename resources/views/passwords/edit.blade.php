<x-layout title="Set a new password">
    <x-card heading="Set a new password" class="max-w-md">
        <form class="flex flex-col gap-4" method="POST" action="{{ route('passwords.update', $token) }}">
            @csrf
            @method('PUT')

            <x-input name="email" label="Email address" type="email" autocomplete="email" :value="$email" required />
            <x-input name="password" label="New password" type="password" autocomplete="new-password" autofocus required />
            <x-input name="password_confirmation" label="Confirm new password" type="password" autocomplete="new-password" required />

            <x-button>Save new password</x-button>
        </form>
    </x-card>
</x-layout>
