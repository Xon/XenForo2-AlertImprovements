<?php

namespace SV\AlertImprovements\Job;

use SV\AlertImprovements\XF\Repository\UserAlert;
use XF\Db\AbstractAdapter;
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

    protected $jobDefaultData = [
        'pendingRebuilds' => false,
    ];

    /** @noinspection PhpMissingReturnTypeInspection */
    protected function setupData(array $data)
    {
        if ($this->repo === null)
        {
            $this->repo = \XF::repository('XF:UserAlert');
        }

        $this->defaultData = array_merge($this->jobDefaultData, $this->defaultData);

        return parent::setupData($data);
    }

    /** @noinspection PhpMissingReturnTypeInspection */
    protected function getNextIds($start, $batch)
    {
        $db = $this->app->db();

        if (empty($this->data['pendingRebuilds']))
        {
            return $db->fetchAllColumn($db->limit(
                "
				SELECT user_id
				FROM xf_user
				WHERE user_id > ? AND is_banned = 0 AND user_state NOT IN ('rejected', 'disabled')
				ORDER BY user_id
			", $batch
            ), $start);
        }

        if (empty($this->data['pruneRebuildTable']))
        {
            $db->executeTransaction(function() use ($db){
                $db->query("
                    DELETE pendingRebuild 
                    FROM xf_sv_user_alert_rebuild AS pendingRebuild
                    LEFT JOIN xf_user ON xf_user.user_id = pendingRebuild.user_id 
                    WHERE xf_user.user_id IS NULL OR user_state IN ('rejected', 'disabled')
                ");
            }, AbstractAdapter::ALLOW_DEADLOCK_RERUN);
            $this->data['pruneRebuildTable'] = true;
        }

        return $db->fetchAllColumn($db->limit(
            "
				SELECT pendingRebuild.user_id
				FROM xf_sv_user_alert_rebuild as pendingRebuild
				INNER JOIN xf_user on xf_user.user_id = pendingRebuild.user_id 
				ORDER BY pendingRebuild.rebuild_date, pendingRebuild.user_id
			", $batch
        ));
    }

    protected function rebuildById($id)
    {
        $this->repo->updateUnviewedCountForUserId($id);
        $this->repo->updateUnreadCountForUserId($id);
    }

    protected function getStatusType(): \XF\Phrase
    {
        return \XF::phrase('alerts');
    }
}
