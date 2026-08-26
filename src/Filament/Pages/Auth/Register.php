<?php

namespace Packstub\Teams\Filament\Pages\Auth;

use Filament\Auth\Pages\Register as BaseRegister;
use Filament\Schemas\Components\Component;
use Packstub\Teams\Facades\Teams;

/**
 * Drop-in registration page: when the visitor followed an invitation link,
 * the invited e-mail is prefilled and locked so the account they create
 * is the one the invitation is for. Use it with `->registration(Register::class)`.
 */
class Register extends BaseRegister
{
    protected function getEmailFormComponent(): Component
    {
        $component = parent::getEmailFormComponent();
        $invitation = Teams::pendingInvitationFromSession();

        if ($invitation && ! $invitation->isLink()) {
            $component
                ->default($invitation->email)
                ->readOnly()
                ->dehydrated()
                ->helperText(__('packstub-teams::teams.register.invited_email'));
        }

        return $component;
    }
}
