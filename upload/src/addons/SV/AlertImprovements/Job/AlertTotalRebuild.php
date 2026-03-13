<?php

namespace SV\AlertImprovements\Job;

use SV\AlertImprovements\XF\Repository\UserAlert as ExtendedUserAlertRepo;
use SV\StandardLib\Helper;
use XF\Db\AbstractAdapter;
use XF\Job\AbstractRebuildJob;
use XF\Phrase;
use XF\Repository\UserAlert as UserAlertRepo;
use function array_merge;
use function in_array;

class AlertTotalRebuild extends AbstractRebuildJob
{
    /** @var ExtendedUserAlertRepo */
    protected $repo = null;
    protected $skipUserAlertTotalsRebuildStates = [];

    protected $jobDefaultData = [
        'pendingRebuilds' => false,
        'pruneRebuildTable' => true,
    ];

    protected function setupData(array $data): array
    {
        if ($this->repo === null)
        {
            $this->repo = Helper::repository(UserAlertRepo::class);
            $this->skipUserAlertTotalsRebuildStates = $this->repo->getSvSkipUserAlertTotalsRebuildStates();
        }

        $this->defaultData = array_merge($this->jobDefaultData, $this->defaultData);

        return parent::setupData($data);
    }

    protected function getNextIds($start, $batch): array
    {
        $db = \XF::db();

        if (!$this->data['pendingRebuilds'])
        {
            if ($this->data['pruneRebuildTable'])
            {
                $db->emptyTable('xf_sv_user_alert_rebuild');
                $this->data['pruneRebuildTable'] = false;
            }

            return $db->fetchAllColumn($db->limit(
                "
				SELECT user_id
				FROM xf_user
				WHERE user_id > ? AND is_banned = 0 AND user_state NOT IN ('rejected', 'disabled')
				ORDER BY user_id
			", $batch
            ), $start);
        }

        return $db->fetchAllColumn($db->limit(
            '
				SELECT pendingRebuild.user_id
				FROM xf_sv_user_alert_rebuild as pendingRebuild
				LEFT JOIN xf_user on xf_user.user_id = pendingRebuild.user_id 
				ORDER BY pendingRebuild.rebuild_date, pendingRebuild.user_id
			', $batch
        ));
    }

    protected function rebuildById($id): void
    {
        $id = (int)$id;
        $db = \XF::db();

        $userState = (string)$db->fetchOne("SELECT IF(is_banned, 'banned', user_state) FROM xf_user WHERE user_id = ?", $id);
        if (!in_array($userState, $this->skipUserAlertTotalsRebuildStates, true))
        {
            $this->repo->updateUnviewedCountForUserId($id);
            $this->repo->updateUnreadCountForUserId($id);
            $this->repo->cleanupAlertSummariesForUserId($id);
        }

        // do this outside the transaction to avoid deadlocks
        $this->repo->cleanupPendingAlertRebuild($id);
    }

    protected function getStatusType(): string
    {
        return \XF::phrase('user') . ' - ' . \XF::phrase('alerts');
    }
}