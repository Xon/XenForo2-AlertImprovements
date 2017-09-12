<?php

namespace SV\AlertImprovements\XF\Repository;


use SV\AlertImprovements\Globals;
use XF\Entity\User;

class UserAlert extends XFCP_UserAlert
{
    public function markUserAlertsRead(User $user, $viewDate = null)
    {
        Globals::$markedAlertsRead = true;
        parent::markUserAlertsRead($user, $viewDate);
    }

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

    /**
     * @param User $user
     * @param int $alertId
     * @param bool $readStatus
     *
     * @return \XF\Entity\UserAlert
     */
    public function changeAlertStatus(User $user, $alertId, $readStatus)
    {
        $db = $this->db();
        $db->beginTransaction();

        /** @var \XF\Entity\UserAlert $alert */
        $alert = $this->finder('XF:UserAlert')
                      ->where(['alert_id', $alertId])
                      ->where(['alerted_user_id', $user->user_id])
                      ->fetchOne();
        if (empty($alert) || $readStatus === ($alert->view_date !== 0))
        {
            @$db->rollback();
            return $alert;
        }

        $alert->fastUpdate('view_date', $readStatus ? \XF::$time : 0);

        if ($readStatus)
        {
            $db->query(
                "
                UPDATE xf_user
                SET alerts_unread = GREATEST(0, cast(alerts_unread as signed) - 1)
                WHERE user_id = ?
            ", $user->user_id
            );
        }
        else
        {
            $db->query("
                update xf_user
                set alerts_unread = LEAST(alerts_unread + 1, 65535)
                where user_id = ?
            ", $user->user_id);
        }

        $db->commit();

        $user->alerts_unread = $user->alerts_unread + ($readStatus ? -1 : 1);
    }
}
