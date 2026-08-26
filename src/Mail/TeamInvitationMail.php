<?php

namespace Packstub\Teams\Mail;

use Filament\Facades\Filament;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Packstub\Teams\Models\TeamInvitation;

class TeamInvitationMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public string $acceptUrl;

    public string $teamName;

    public function __construct(public TeamInvitation $invitation, ?string $panel = null)
    {
        $this->acceptUrl = $invitation->acceptUrl($panel);

        $team = $invitation->team;
        $filamentPanel = Filament::getCurrentOrDefaultPanel();
        $this->teamName = $filamentPanel?->hasTenancy() ? Filament::getTenantName($team) : (string) ($team->name ?? $team->getKey());
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('packstub-teams::teams.mail.subject', ['team' => $this->teamName]),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'packstub-teams::mail.invitation',
            with: [
                'teamName' => $this->teamName,
                'acceptUrl' => $this->acceptUrl,
                'inviter' => $this->invitation->inviter,
                'role' => $this->invitation->role,
                'expiresAt' => $this->invitation->expires_at,
            ],
        );
    }
}
