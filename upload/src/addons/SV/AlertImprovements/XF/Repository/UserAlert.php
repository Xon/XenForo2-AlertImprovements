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

        // Do a select first to reduce the amount of rows that can be touched for the update.
        // This hopefully reduces contention as must of the time it should just be a select, without any updates
        $alertIds = $db->fetchAllColumn("
            SELECT alert_id
            FROM xf_user_alert
            WHERE alerted_user_id = ?
            AND view_date = 0
            AND event_date < ?
            AND content_type IN (" . $db->quote($contentType) . ")
            AND content_id IN (" . $db->quote($contentIds) . ")
        ", array($visitor->user_id, \XF::$time));

        if (empty($alertIds))
        {
            return;
        }

        $stmt = $db->query("
            UPDATE IGNORE xf_user_alert
            SET view_date = ?
            WHERE view_date = 0 AND alert_id IN (" . $db->quote($alertIds) . ")
        ", array(\XF::$time));

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
            catch (\Exception $e)
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

        // avoid race condition as xf_user row isn't selected in this transaction.
        $db->query("
			UPDATE xf_user
			SET alerts_unread = LEAST(alerts_unread + 1, 65535)
			WHERE user_id = ?
		", $user->user_id);

        $db->commit();

        $user->alerts_unread += 1;
    }
}
