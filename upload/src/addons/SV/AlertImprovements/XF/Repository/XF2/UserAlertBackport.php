<?php


namespace SV\AlertImprovements\XF\Repository\XF2;

use SV\AlertImprovements\XF\Repository\XFCP_UserAlertBackport;
use XF\Entity\User as UserEntity;
use XF\Entity\UserAlert as UserAlertEntity;

class UserAlertBackport extends XFCP_UserAlertBackport
{
    /**
     * @param UserAlertEntity $alert
     * @param int|null $readDate
     *
     * @throws \XF\Db\DeadlockException
     */
    public function markUserAlertRead(UserAlertEntity $alert, $readDate = null)
    {
        if ($readDate === null)
        {
            $readDate = \XF::$time;
        }

        if (!$alert->isUnread())
        {
            return;
        }

        $user = $alert->Receiver;

        $db = $this->db();

        $db->executeTransaction(function() use ($db, $alert, $user, $readDate)
        {
            $db->update('xf_user_alert',
                ['view_date' => $readDate],
                'alert_id = ?',
                $alert->alert_id
            );

            $db->query("
                UPDATE xf_user
                SET alerts_unread = GREATEST(0, alerts_unread - ?)
                WHERE user_id = ?
            ", [1, $alert->alerted_user_id]);

            $this->svSyncUserAlertUnreadCount($user);

        }, \XF\Db\AbstractAdapter::ALLOW_DEADLOCK_RERUN);
    }

    public function markUserAlertUnread(UserAlertEntity $alert, bool $disableAutoRead = true)
    {
        if ($alert->isUnread())
        {
            return;
        }

        $user = $alert->Receiver;

        $db = $this->db();

        $db->executeTransaction(function() use ($db, $alert, $user, $disableAutoRead)
        {
            $update = ['view_date' => 0];
            if ($disableAutoRead)
            {
                $update['auto_read'] = 0;
            }

            $db->update('xf_user_alert',
                $update,
                'alert_id = ?',
                $alert->alert_id
            );

            $db->query("
                UPDATE xf_user
                SET alerts_unread = LEAST(alerts_unread + ?, 65535)
                WHERE user_id = ?
            ", [1, $alert->alerted_user_id]);

            $this->svSyncUserAlertUnreadCount($user);

        }, \XF\Db\AbstractAdapter::ALLOW_DEADLOCK_RERUN);
    }

    protected function svSyncUserAlertUnreadCount($user)
    {
        if (!$user || !($user instanceof UserEntity))
        {
            return;
        }

        $alertsUnread = $this->db()->fetchOne("
            SELECT alerts_unread
            FROM xf_user
            WHERE user_id = ?
        ", $user->user_id);

        $user->setAsSaved('alerts_unread', $alertsUnread);
    }
}