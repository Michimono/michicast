<?php

namespace App\Mail;

use App\Models\OrganizationInvitation;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class OrganizationInvitationMail extends Mailable
{
    use SerializesModels;

    public function __construct(
        public OrganizationInvitation $invitation,
        public string $acceptUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Join '.$this->invitation->organization->name,
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.organization-invitation',
        );
    }
}
