<x-layout title="Change role">
    <x-card heading="Change role" :kicker="$member->organization->name" class="max-w-md">
        <p class="text-sm text-ink">
            What {{ $member->user->name }} may do in {{ $member->organization->name }}.
        </p>

        <form class="flex flex-col gap-4" method="POST" action="{{ route('members.role.update', $member) }}">
            @csrf
            @method('PATCH')

            <x-select
                name="role"
                label="Role"
                :value="$member->role->value"
                :options="$roles"
                hint="Owners and admins manage members. Editors manage projects. Viewers only read."
            />

            <x-button>Save role</x-button>
        </form>

        <a class="text-sm text-accent underline hover:no-underline" href="{{ route('members.index') }}">
            Back to members
        </a>
    </x-card>
</x-layout>
