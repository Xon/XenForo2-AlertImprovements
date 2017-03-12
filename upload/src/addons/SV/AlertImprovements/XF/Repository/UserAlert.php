<?php

namespace SV\AlertImprovements\XF\Repository;

use XF\Mvc\Entity\Finder;
use XF\Mvc\Entity\Repository;

class UserAlert extends XFCP_UserAlert
{
	public function markAlertsReadForContentIds($contentType, array $contentIds)
	{
		if (empty($contentIds))
		{
			return;
		}

		$visitor = \XF::visitor();

		$db = $this->db();
		$db->beginTransaction();

		$contentIds = $db->fetchAllColumn("
            SELECT content_id
            FROM xf_user_alert
            WHERE alerted_user_id = ? 
            AND view_date = 0 
            AND event_date < ? 
            AND content_type in (". $db->quote($contentType) .") 
            AND content_id in (". $db->quote($contentIds) .")
        ", array($visitor->user_id, \XF::$time));

		if (empty($contentIds))
		{
			return;
		}

		$stmt = $db->query("
            UPDATE IGNORE xf_user_alert
            SET view_date = ?
            WHERE alerted_user_id = ?
            and view_date = 0
            and event_date < ?
            and content_type in (". $db->quote($contentType) .")
            and content_id in (". $db->quote($contentIds) .")
        ", array(\XF::$time, $visitor->user_id, \XF::$time));

		$rowsAffected = $stmt->rowsAffected();

		if ($rowsAffected)
		{
			try
			{
				$db->query("
                    UPDATE xf_user
                    SET alerts_unread = GREATEST(0, cast(alerts_unread AS SIGNED) - ?)
                    WHERE user_id = ?
                ", array($rowsAffected, $visitor->user_id));
			}
			catch(\Exception $e)
			{
				// todo: xon
				throw $e;
			}

			$visitor->alerts_unread -= $rowsAffected;
			if ($visitor->alerts_unread < 0)
			{
				$visitor->alerts_unread = 0;
			}
		}
	}

	public function markAlertUnread(\XF\Entity\User $user, $alertId)
	{
		$db = $this->db();
		$db->beginTransaction();

		$db->update('xf_user_alert',
			['view_date' => 0],
			'alerted_user_id = ? AND alert_id = ?',
			[$user->user_id, $alertId]
		);

		$user->alerts_unread += 1;
		$user->save(true, false);

		$db->commit();
	}
}