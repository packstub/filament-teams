<?php

namespace Packstub\Teams\Commands;

use Illuminate\Console\Command;
use Packstub\Teams\Teams;

class PruneInvitationsCommand extends Command
{
    protected $signature = 'teams:prune-invitations';

    protected $description = 'Delete expired team invitations and accepted ones past the retention period';

    public function handle(Teams $teams): int
    {
        $count = $teams->pruneInvitations();

        $this->components->info("Pruned {$count} invitation(s).");

        return self::SUCCESS;
    }
}
