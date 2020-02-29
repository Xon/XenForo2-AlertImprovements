<?php

namespace SV\AlertImprovements\Job;

use XF\Job\AbstractJob;

/**
 * Class Cache
 *
 * @package SV\AlertImprovements\Job
 */
class AlertCleanup extends AbstractJob
{

    /**
     * @inheritDoc
     */
    public function run($maxRunTime)
    {
        $startTime = \microtime(true);

        /** @var \XF\Repository\UserAlert $alertRepo */
        $alertRepo = \XF::app()->repository('XF:UserAlert');
        $alertRepo->pruneReadAlerts();
        if (microtime(true) - $startTime < $maxRunTime)
        {
            $alertRepo->pruneUnreadAlerts();
        }

        return $this->complete();
    }

    public function getStatusMessage()
    {
        return '';
    }

    public function canCancel()
    {
        return false;
    }

    public function canTriggerByChoice()
    {
        return false;
    }
}
