<x-mail::message>
# Join {{ $invitation->organization->name }}

{{ $invitation->inviter->name }} invited you to {{ $invitation->organization->name }} as {{ $invitation->role->label() }}.

<x-mail::button :url="$acceptUrl">
Accept invitation
</x-mail::button>

This invitation expires in {{ \App\Models\OrganizationInvitation::EXPIRES_AFTER_DAYS }} days.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
