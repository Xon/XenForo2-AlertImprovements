<?php

namespace SV\AlertImprovements\Cli\Command\Rebuild;

use SV\AlertImprovements\Job\AlertTotalRebuild;
use Symfony\Component\Console\Input\InputOption;
use XF\Cli\Command\Rebuild\AbstractRebuildCommand;

class RebuildAlertTotals extends AbstractRebuildCommand
{

    protected function getRebuildName() : string
    {
        return 'sv-alert-totals';
    }

    protected function getRebuildDescription() : string
    {
        return 'Rebuilds alert totals';
    }

    protected function getRebuildClass() : string
    {
        return AlertTotalRebuild::class;
    }

    protected function configureOptions(): void
    {
        parent::configureOptions();
        $this->addOption(
            'pendingRebuilds',
            null,
            InputOption::VALUE_REQUIRED,
            'Rebuild all users or just users with pending rebuilds after alert pruning',
            true
        );
    }
}