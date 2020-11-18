<?php

namespace SV\AlertImprovements\XF\Repository;

use XF\Db\AbstractAdapter;
use XF\Db\AbstractStatement;

/**
 * Class UserAlert
 *
 * @package SV\AlertImprovements\XF\Repository
 */
class UserAlertPatch extends XFCP_UserAlertPatch
{
    protected $svBatchLimit = 50000;

    public function pruneViewedAlertsBatch(int $cutOff, float $startTime, float $maxRunTime): bool
    {
        if (!$cutOff)
        {
            return false;
        }
        $db = $this->db();
        do
        {
            /** @var AbstractStatement $statement */
            $statement = $db->executeTransaction(function (AbstractAdapter $db) use ($cutOff) {
                return $db->query("DELETE FROM xf_user_alert WHERE view_date > 0 AND view_date < ? LIMIT {$this->svBatchLimit}", $cutOff);
            }, AbstractAdapter::ALLOW_DEADLOCK_RERUN);

            if (microtime(true) - $startTime >= $maxRunTime)
            {
                return true;
            }
        }
        while ($statement && $statement->rowsAffected() >= $this->svBatchLimit);

        return false;
    }

    public function pruneUnviewedAlertsBatch(int $cutOff, float $startTime, float $maxRunTime): bool
    {
        if (!$cutOff)
        {
            return false;
        }
        do
        {
            /** @var AbstractStatement $statement */
            $statement = $this->db()->executeTransaction(function (AbstractAdapter $db) use ($cutOff) {
                return $db->query("DELETE FROM xf_user_alert WHERE view_date = 0 AND event_date < ? LIMIT {$this->svBatchLimit}", $cutOff);
            }, AbstractAdapter::ALLOW_DEADLOCK_RERUN);

            if (microtime(true) - $startTime >= $maxRunTime)
            {
                return true;
            }
        }
        while ($statement && $statement->rowsAffected() >= $this->svBatchLimit);

        return false;
    }

    public function pruneViewedAlerts($cutOff = null)
    {
        if ($cutOff === null)
        {
            $cutOff = \XF::$time - $this->options()->alertExpiryDays * 86400;
        }
        \XF::app()->jobManager()->enqueueLater('sViewedAlertCleanup', \XF::$time + 1, 'SV\AlertImprovements:ViewedAlertCleanup', [
            'cutOff' => $cutOff,
        ], false);
    }

    public function pruneUnviewedAlerts($cutOff = null)
    {
        if ($cutOff === null)
        {
            $unviewedAlertExpiryDays = isset(\XF::options()->svUnviewedAlertExpiryDays) ? \XF::options()->svUnviewedAlertExpiryDays : 30;
            $cutOff = \XF::$time - $unviewedAlertExpiryDays * 86400;
        }
        \XF::app()->jobManager()->enqueueLater('svUnviewedAlertCleanup', \XF::$time + 1, 'SV\AlertImprovements:UnviewedAlertCleanup', [
            'cutOff' => $cutOff,
        ], false);
    }


    /**
     * XF2.1 shim
     *
     * @param int|null $cutOff
     */
    public function pruneReadAlerts($cutOff = null)
    {
        $this->pruneViewedAlerts($cutOff);
    }

    /**
     * XF2.1 shim
     *
     * @param int|null $cutOff
     */
    public function pruneUnreadAlerts($cutOff = null)
    {
        $this->pruneUnviewedAlerts($cutOff);
    }

}