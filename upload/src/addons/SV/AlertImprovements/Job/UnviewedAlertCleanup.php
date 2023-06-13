<?php

namespace SV\AlertImprovements\Job;

use SV\AlertImprovements\XF\Repository\UserAlert;
use SV\AlertImprovements\XF\Repository\UserAlertPatch;
use XF\Db\DeadlockException;
use XF\Db\Exception;
use XF\Job\AbstractJob;
use XF\Job\JobResult;
use function max;
use function microtime;

class UnviewedAlertCleanup extends AbstractJob
{
    protected $defaultData = [
        'cutOff' => 0,
        'recordedUsers' => null,
        'minEventDate' => 0,
        'pruned' => false,
        'batch' => 50000,
    ];

    /**
     * @param float $maxRunTime
     * @return JobResult
     * @throws Exception
     */
    public function run($maxRunTime): JobResult
    {
        $cutOff = $this->data['cutOff'] ?? 0;
        $cutOff = (int)$cutOff;
        if (!$cutOff)
        {
            return $this->complete();
        }

        $startTime = microtime(true);
        $db = \XF::db();

        if ($this->data['recordedUsers'] === null)
        {
            // only fetch a day at a time to avoid the query populating xf_sv_user_alert_rebuild from touching way too many rows
            $cutOffWindow = (int)$db->fetchOne('
                select min(event_date) 
                from xf_user_alert 
                WHERE view_date = 0 AND event_date >= ? AND event_date < ? 
            ', [$this->data['minEventDate'], $cutOff]);

            while ($cutOffWindow <= $cutOff)
            {
                $this->data['minEventDate'] = $cutOffWindow;
                $cutOffWindow = $this->data['minEventDate'] + 86400;
                $this->saveIncrementalData();

                try
                {
                    $db->query('
                        INSERT IGNORE INTO xf_sv_user_alert_rebuild (user_id, rebuild_date)
                        SELECT DISTINCT alerted_user_id, ?
                        FROM xf_user_alert 
                        WHERE view_date = 0 AND event_date <= ?
                    ', [\XF::$time, $cutOffWindow]);
                }
                catch (DeadlockException $e)
                {
                    $db->rollback();
                    // on deadlock resume later
                    $resume = $this->resume();
                    $resume->continueDate = \XF::$time + rand(1, 5);

                    return $resume;
                }

                if (microtime(true) - $startTime >= $maxRunTime)
                {
                    break;
                }
            }

            $this->data['recordedUsers'] = (bool)$db->fetchOne('SELECT 1 FROM xf_sv_user_alert_rebuild LIMIT 1');
            $this->saveIncrementalData();
        }

        if (!$this->data['recordedUsers'])
        {
            return $this->complete();
        }

        if (microtime(true) - $startTime >= $maxRunTime)
        {
            return $this->resume();
        }

        /** @var UserAlertPatch|UserAlert $alertRepo */
        $alertRepo = \XF::app()->repository('XF:UserAlert');

        if (empty($this->data['pruned']))
        {
            $batchSize = max(100, (int)($this->data['batch'] ?? 50000));
            $continue = $alertRepo->pruneUnviewedAlertsBatch($cutOff, $startTime, $maxRunTime, $batchSize);
            $this->data['batch'] = $batchSize;
            if ($continue)
            {
                $resume = $this->resume();
                $resume->continueDate = \XF::$time + 1;

                return $resume;
            }

            $this->data['pruned'] = true;
            $this->saveIncrementalData();
        }

        if ($this->data['recordedUsers'])
        {
            $jm = \XF::app()->jobManager();
            if (!$jm->getUniqueJob('svAlertTotalRebuild'))
            {
                \XF::app()->jobManager()->enqueueUnique('svAlertTotalRebuild', AlertTotalRebuild::class, [
                    'pendingRebuilds' => true,
                ], false);
            }
        }

        return $this->complete();
    }

    public function getStatusMessage(): string
    {
        return '';
    }

    public function canCancel(): bool
    {
        return false;
    }

    public function canTriggerByChoice(): bool
    {
        return false;
    }
}
