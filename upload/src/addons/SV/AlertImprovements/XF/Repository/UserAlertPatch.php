<?php

namespace SV\AlertImprovements\XF\Repository;

use XF\Db\AbstractAdapter;
use XF\Db\AbstractStatement;
use function max;

/**
 * Class UserAlert
 *
 * @package SV\AlertImprovements\XF\Repository
 */
class UserAlertPatch extends XFCP_UserAlertPatch
{
    public function pruneViewedAlertsBatch(int $cutOff, float $startTime, float $maxRunTime, int &$batchSize): bool
    {
        if (!$cutOff)
        {
            return false;
        }

        $db = $this->db();
        try
        {
            do
            {

                /** @var AbstractStatement $statement */
                $statement = $db->executeTransaction(function (AbstractAdapter $db) use ($cutOff, $batchSize) {
                    return $db->query("DELETE FROM xf_user_alert WHERE view_date > 0 AND view_date < ? LIMIT {$batchSize}", $cutOff);
                }, AbstractAdapter::ALLOW_DEADLOCK_RERUN);


                if (\microtime(true) - $startTime >= $maxRunTime)
                {
                    return true;
                }
            }
            while ($statement && $statement->rowsAffected() >= $batchSize);
        }
        catch (\XF\Db\DeadlockException $e)
        {
            $db->rollback();
            // reduce batch size, and signal to try again
            $batchSize = max((int)($batchSize / 2), 100);
            return true;
        }

        return false;
    }

    public function pruneUnviewedAlertsBatch(int $cutOff, float $startTime, float $maxRunTime, int &$batchSize): bool
    {
        if (!$cutOff)
        {
            return false;
        }

        $db = $this->db();
        retry_with_smaller_batch:
        try
        {
            do
            {
                /** @var AbstractStatement $statement */
                $statement = $db->executeTransaction(function (AbstractAdapter $db) use ($cutOff, $batchSize) {
                    return $db->query("DELETE FROM xf_user_alert WHERE view_date = 0 AND event_date < ? LIMIT {$batchSize}", $cutOff);
                }, AbstractAdapter::ALLOW_DEADLOCK_RERUN);

                if (\microtime(true) - $startTime >= $maxRunTime)
                {
                    return true;
                }
            }
            while ($statement && $statement->rowsAffected() >= $batchSize);
        }
        catch (\XF\Db\DeadlockException $e)
        {
            $db->rollback();
            // reduce batch size, and signal to try again
            $batchSize = max((int)($batchSize / 2), 100);
            return true;
        }

        return false;
    }

    public function pruneViewedAlerts($cutOff = null)
    {
        if ($cutOff === null)
        {
            $viewedAlertExpiryDays = (int)($this->options()->alertExpiryDays ?? 4);
            $cutOff = \XF::$time - $viewedAlertExpiryDays * 86400;
        }
        \XF::app()->jobManager()->enqueueLater('sViewedAlertCleanup', \XF::$time + 1, 'SV\AlertImprovements:ViewedAlertCleanup', [
            'cutOff' => $cutOff,
        ], false);
    }

    public function pruneUnviewedAlerts($cutOff = null)
    {
        if ($cutOff === null)
        {
            $unviewedAlertExpiryDays = (int)(\XF::options()->svUnviewedAlertExpiryDays ?? 30);
            $cutOff = \XF::$time - $unviewedAlertExpiryDays * 86400;
        }
        \XF::app()->jobManager()->enqueueLater('svUnviewedAlertCleanup', \XF::$time + 2*60, 'SV\AlertImprovements:UnviewedAlertCleanup', [
            'cutOff' => $cutOff,
        ], false);
    }
}