<?php

namespace SV\AlertImprovements\Job;

use XF\Job\AbstractJob;

class ViewedAlertCleanup extends AbstractJob
{
    protected $defaultData = [
        'cutOff' => 0,
        'userIds' => [],
        'pruned' => false,
    ];

    /**
     * @inheritDoc
     */
    public function run($maxRunTime)
    {
        $cutOff = $this->data['cutOff'] ?? 0;
        $cutOff = (int)$cutOff;
        if (!$cutOff)
        {
            return $this->complete();
        }

        $startTime = \microtime(true);
        $db = \XF::db();

        if (empty($this->data['userIds']))
        {
            $userIds = $db->fetchAllColumn('
                SELECT DISTINCT alerted_user_id 
                FROM xf_user_alert 
                WHERE view_date > 0 AND view_date < ? AND alerted_user_id <> 0
            ', $cutOff);

            if (empty($userIds))
            {
                return $this->complete();
            }

            $this->data['userIds'] = $userIds;
            $this->saveIncrementalData();
        }

        if (microtime(true) - $startTime >= $maxRunTime)
        {
            return $this->resume();
        }

        /** @var \SV\AlertImprovements\XF\Repository\UserAlertPatch $alertRepo */
        $alertRepo = \XF::app()->repository('XF:UserAlert');

        if (empty($this->data['pruned']))
        {
            $continue = $alertRepo->pruneViewedAlertsBatch($cutOff, $startTime, $maxRunTime);
            if ($continue)
            {
                $resume = $this->resume();
                $resume->continueDate = \XF::$time = 1;

                return $resume;
            }

            $this->data['pruned'] = true;
            $this->saveIncrementalData();
        }

        if ($this->data['userIds'])
        {
            foreach($this->data['userIds'] as $userId)
            {
                $db->beginTransaction();

                /** @var \XF\Entity\User $user */
                $user = \XF::app()->find('XF:User', $userId);
                if ($user)
                {
                    $alertRepo->updateUnreadCountForUser($user);
                    $alertRepo->updateUnviewedCountForUser($user);
                }
                unset($this->data['userIds'][$userId]);
                $this->saveIncrementalData();

                $db->commit();

                if (microtime(true) - $startTime >= $maxRunTime)
                {
                    return $this->resume();
                }
            }
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
