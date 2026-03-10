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

    protected $jobDefaultData = [
        'pendingRebuilds' => false,
        'pruneRebuildTable' => true,
    ];

    protected function setupData(array $data): array
    {
        if ($this->repo === null)
        {
            $this->repo = Helper::repository(UserAlertRepo::class);
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

    protected $skipUserStates = [
        'rejected',
        'disabled',
        '', // user does not exist
        'banned', // pseudo-state
    ];

    protected function rebuildById($id): void
    {
        $id = (int)$id;
        $userState = (string)\XF::db()->fetchOne("select if(is_banned, 'banned', user_state) from xf_user where user_id = ?", $id);
        if (in_array($userState, $this->skipUserStates, true))
        {
            $this->repo->cleanupPendingAlertRebuild($id);
            return;
        }

        $this->repo->updateUnviewedCountForUserId($id);
        $this->repo->updateUnreadCountForUserId($id);
        $this->repo->cleanupAlertSummariesForUserId($id);
    }

    protected function getStatusType(): string
    {
        return \XF::phrase('user') . ' - ' . \XF::phrase('alerts');
    }
}