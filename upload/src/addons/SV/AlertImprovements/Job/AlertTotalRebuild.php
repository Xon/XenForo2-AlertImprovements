<?php

namespace SV\AlertImprovements\Job;

use SV\AlertImprovements\XF\Repository\UserAlert;
use XF\Job\AbstractRebuildJob;

/**
 * Class Cache
 *
 * @package SV\AlertImprovements\Job
 */
class AlertTotalRebuild extends AbstractRebuildJob
{
    /** @var UserAlert */
    protected $repo = null;

    protected function getNextIds($start, $batch)
    {
        $db = $this->app->db();

        return $db->fetchAllColumn($db->limit(
            "
				SELECT user_id
				FROM xf_user
				WHERE user_id > ? AND is_banned = 0 AND user_state NOT IN ('moderated', 'rejected', 'disabled')
				ORDER BY user_id
			", $batch
        ), $start);
    }

    protected function rebuildById($id)
    {
        /** @var \XF\Entity\User $user */
        $user = \XF::app()->find('XF:User', $id);
        if (!$user)
        {
            return;
        }
        if ($this->repo === null)
        {
            $this->repo = \XF::repository('XF:UserAlert');
        }

        $this->repo->updateUnviewedCountForUser($user);
        $this->repo->updateUnreadCountForUser($user);
    }

    protected function getStatusType()
    {
        return \XF::phrase('alerts');
    }
}
