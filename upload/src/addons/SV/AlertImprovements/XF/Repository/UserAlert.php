<?php

namespace SV\AlertImprovements\XF\Repository;

use SV\AlertImprovements\Globals;
use SV\AlertImprovements\Repository\AlertSummarization;
use SV\AlertImprovements\XF\Entity\User as ExtendedUserEntity;
use SV\AlertImprovements\XF\Entity\UserAlert as ExtendedUserAlertEntity;
use SV\AlertImprovements\XF\Finder\UserAlert as ExtendedUserAlertFinder;
use XF\Db\AbstractAdapter;
use XF\Db\DeadlockException;
use XF\Entity\User;
use XF\Entity\UserAlert as UserAlertEntity;
use XF\Mvc\Entity\AbstractCollection;
use XF\Mvc\Entity\Finder;
use function array_keys;
use function assert;
use function count;
use function is_array;
use function max;
use function min;

/**
 * Class UserAlert
 *
 * @package SV\AlertImprovements\XF\Repository
 */
class UserAlert extends XFCP_UserAlert
{
    /** @var int */
    protected $svUserMaxAlertCount = 65535;

    public function getSvUserMaxAlertCount(): int
    {
        return $this->svUserMaxAlertCount;
    }

    public function getIgnoreAlertCutOffs(): array
    {
        if (!Globals::isSkippingExpiredAlerts())
        {
            return [0, 0];
        }
        $viewedAlertExpiryDays = (int)($this->options()->alertExpiryDays ?? 4);
        $viewedCutOff = \XF::$time - $viewedAlertExpiryDays * 86400;

        $unviewedAlertExpiryDays = (int)(\XF::options()->svUnviewedAlertExpiryDays ?? 30);
        $unviewedCutOff = \XF::$time - $unviewedAlertExpiryDays * 86400;

        return [$viewedCutOff, $unviewedCutOff];
    }

    public function refreshUserAlertCounters(User $user)
    {
        $row = $this->db()->fetchRow('SELECT alerts_unviewed, alerts_unread FROM xf_user WHERE user_id = ?', $user->user_id);
        if ($row)
        {
            $user->setAsSaved('alerts_unviewed', $row['alerts_unviewed']);
            $user->setAsSaved('alerts_unread', $row['alerts_unread']);
        }
    }

    /**
     * @param int       $userId
     * @param int[]|int $alertId
     * @return ExtendedUserAlertFinder|Finder
     */
    public function findAlertByIdsForUser(int $userId, $alertId): ExtendedUserAlertFinder
    {
        if (!is_array($alertId))
        {
            $alertId = [$alertId];
        }
        /** @var ExtendedUserAlertFinder $finder */
        $finder = $this->finder('XF:UserAlert')
                       ->where(['alert_id', $alertId]);
        if ($userId !== 0)
        {
            $finder->where(['alerted_user_id', $userId]);
        }
        else
        {
            $finder->whereImpossible();
        }

        if (Globals::isSkippingExpiredAlerts())
        {
            [$viewedCutOff, $unviewedCutOff] = $this->getIgnoreAlertCutOffs();
            $finder->whereOr([
                ['view_date', '>=', $viewedCutOff],
            ], [
                ['view_date', '=', 0],
                ['event_date', '>=', $unviewedCutOff],
            ]);
        }

        $finder
            ->markUnviewableAsUnread()
            ->forValidContentTypes()
            ->undoUserJoin()
        ;

        return $finder;
    }

    /**
     * @param int      $userId
     * @param null|int $cutOff
     * @return Finder
     */
    public function findAlertsForUser($userId, $cutOff = null)
    {
        /** @var ExtendedUserAlertFinder $finder */
        $finder = parent::findAlertsForUser($userId, null);
        if ($userId === 0)
        {
            return $finder;
        }
        $user = $this->app()->find('XF:User', $userId);
        assert($user instanceof ExtendedUserEntity);

        $finder
            ->markUnviewableAsUnread()
            ->forValidContentTypes()
            ->undoUserJoin()
        ;

        $showUnreadOnly = Globals::$showUnreadOnly ?? false;
        if ($showUnreadOnly)
        {
            $finder->showUnreadOnly();
        }

        if (Globals::isSkippingExpiredAlerts())
        {
            [$viewedCutOff, $unviewedCutOff] = $this->getIgnoreAlertCutOffs();
            $finder->indexHint('use', 'alertedUserId_eventDate');
            if ($showUnreadOnly)
            {
                $finder->where('event_date', '>=', $unviewedCutOff);
            }
            else
            {
                $finder->whereOr([
                    ['view_date', '>=', $viewedCutOff],
                ], [
                    ['view_date', '=', 0],
                    ['event_date', '>=', $unviewedCutOff],
                ]);
            }
        }
        else if ($cutOff)
        {
            // for completeness, as this argument isn't used in stock XF
            $finder->whereOr(
                [
                    // The addon essentially ignores read_date, so don't bother selecting on it.
                    // This also improves index selectivity
                    //['read_date', '=', 0],
                    ['view_date', '=', 0],
                    ['view_date', '>=', $cutOff]
                ]
            );
        }

        if (Globals::$forSummarizedAlertView ?? false)
        {
            return $finder;
        }
        else
        {
            $finder->where(['summerize_id', null]);
        }

        if (Globals::$doAlertPopupRewrite ?? false)
        {
            // in alert pop-up, ensure unread alerts are preferred over read alerts
            $finder->forceUnreadFirst();
        }

        $skipSummarize = (Globals::$skipSummarize ?? false) || !$this->getAlertSummarizationRepo()->canSummarizeAlerts();
        if ($skipSummarize || $this->db()->inTransaction())
        {
            return $finder;
        }

        return $finder->shimSource(function ($limit, $offset) use ($user) {
            if ($offset !== 0)
            {
                return null;
            }
            if ($limit === 0)
            {
                return [];
            }

            $this->getAlertSummarizationRepo()->summarizeAlertsForUser($user,  false, 0);

            return null;
        });
    }

    /**
     * @param User|ExtendedUserEntity $user
     * @param null|int                $readDate
     * @noinspection PhpDocMissingThrowsInspection
     * @noinspection PhpMissingParentCallCommonInspection
     */
    public function markUserAlertsRead(User $user, $readDate = null)
    {
        $userId = (int)$user->user_id;
        if ($userId === 0 || Globals::$skipMarkAlertsRead)
        {
            return;
        }

        if ($readDate === null)
        {
            $readDate = \XF::$time;
        }

        $db = $this->db();
        $db->executeTransaction(function () use ($db, $readDate, $userId) {
            if (Globals::isSkippingExpiredAlerts())
            {
                $skipExpiredAlertSql =  '  and event_date >= ? ';
                /** @noinspection PhpUnusedLocalVariableInspection */
                [$viewedCutOff, $unviewedCutOff] = $this->getIgnoreAlertCutOffs();
                $args = [$readDate, $readDate, $userId, $unviewedCutOff];
            }
            else
            {
                $skipExpiredAlertSql = '';
                $args = [$readDate, $readDate, $userId];
            }
            // table lock ordering required is [xf_user, xf_user_alert] to avoid deadlocks
            // update both view_date/read_date together to ensure they stay consistent
            $db->query('UPDATE xf_user SET alerts_unviewed = 0, alerts_unread = 0 WHERE user_id = ?', [$userId]);
            $db->query('UPDATE xf_user_alert 
                SET view_date = ?, read_date = ? 
                WHERE alerted_user_id = ? 
                AND (read_date = 0 or view_date = 0) '.$skipExpiredAlertSql.'
            ', $args);
        }, AbstractAdapter::ALLOW_DEADLOCK_RERUN);

        $user->setAsSaved('alerts_unviewed', 0);
        $user->setAsSaved('alerts_unread', 0);
    }

    /** @noinspection PhpMissingParentCallCommonInspection */
    public function autoMarkUserAlertsRead(AbstractCollection $alerts, User $user, $readDate = null)
    {
        if (Globals::$skipMarkAlertsRead)
        {
            return;
        }

        $alerts = $alerts->filter(function (ExtendedUserAlertEntity $alert) {
            return $alert->getHandler() === null || ($alert->isUnread() && $alert->auto_read);
        });

        $this->markSpecificUserAlertsRead($alerts, $user, $readDate);
    }

    /** @noinspection PhpMissingParentCallCommonInspection */
    protected function markSpecificUserAlertsRead(AbstractCollection $alerts, User $user, int $readDate = null)
    {
        $userId = (int)$user->user_id;
        if ($userId === 0 || $alerts->count() === 0)
        {
            return;
        }

        if ($readDate === null)
        {
            $readDate = \XF::$time;
        }

        $unreadAlertIds = [];
        foreach ($alerts as $alert)
        {
            /** @var ExtendedUserAlertEntity $alert */
            if ($alert->isUnread())
            {
                $unreadAlertIds[] = $alert->alert_id;
                $alert->setAsSaved('view_date', $readDate);
                $alert->setAsSaved('read_date', $readDate);

                // we need to treat this as unread for the current request so it can display the way we want
                $alert->setOption('force_unread_in_ui', true);
            }
        }

        if (!$unreadAlertIds)
        {
            return;
        }

        $this->markAlertIdsAsReadAndViewed($user, $unreadAlertIds, $readDate);
    }

    /**
     * @param string      $contentType
     * @param int|int[]   $contentIds
     * @param string|null $onlyActions
     * @param User|null   $user
     * @param int|null    $readDate
     * @noinspection PhpMissingParentCallCommonInspection
     */
    public function markUserAlertsReadForContent($contentType, $contentIds, $onlyActions = null, User $user = null, $readDate = null)
    {
        if (!is_array($contentIds))
        {
            $contentIds = [$contentIds];
        }
        if ($onlyActions && !is_array($onlyActions))
        {
            $onlyActions = [$onlyActions];
        }

        $this->markAlertsReadForContentIds($contentType, $contentIds, $onlyActions, 0, $user ?? \XF::visitor(), $readDate);
    }

    /**
     * @param User|ExtendedUserEntity $user
     * @param int[]                   $alertIds
     * @param int                     $readDate
     * @param bool                    $updateAlertEntities
     * @noinspection PhpDocMissingThrowsInspection
     */
    public function markAlertIdsAsReadAndViewed(User $user, array $alertIds, int $readDate, bool $updateAlertEntities = false)
    {
        if (count($alertIds) === 0)
        {
            return;
        }

        $userId = (int)$user->user_id;
        $db = $this->db();
        if ($db->inTransaction())
        {
            // Only enforce table lock ordering if this function was called inside a transaction
            // this avoids updating xf_user/xf_user_alert inside a transaction when something else does xf_user_alert/xf_user
            // outside of a transaction, these tables are not linked
            $db->fetchOne('SELECT user_id FROM xf_user WHERE user_id = ? FOR UPDATE', $userId);
        }
        $ids = $db->quote($alertIds);
        $stmt = $db->query('
                UPDATE IGNORE xf_user_alert
                SET view_date = ?
                WHERE view_date = 0 AND alerted_user_id = ? AND alert_id IN (' . $ids . ')
            ', [$readDate, $userId]
        );
        $viewRowsAffected = $stmt->rowsAffected();

        $stmt = $db->query('
                UPDATE IGNORE xf_user_alert
                SET read_date = ?
                WHERE read_date = 0 AND alerted_user_id = ? AND alert_id IN (' . $ids . ')
            ', [$readDate, $userId]
        );
        $readRowsAffected = $stmt->rowsAffected();

        if (!$viewRowsAffected && !$readRowsAffected)
        {
            return;
        }

        try
        {
            $db->query('
                UPDATE xf_user
                SET alerts_unviewed = GREATEST(0, cast(alerts_unviewed AS SIGNED) - ?),
                    alerts_unread = GREATEST(0, cast(alerts_unread AS SIGNED) - ?)
                WHERE user_id = ?
            ', [$viewRowsAffected, $readRowsAffected, $userId]
            );

            $user->setAsSaved('alerts_unviewed', max(0, $user->alerts_unviewed - $viewRowsAffected));
            $user->setAsSaved('alerts_unread', max(0, $user->alerts_unread - $readRowsAffected));
        }
        catch (DeadlockException $e)
        {
            $statement = $db->query('
                UPDATE xf_user
                SET alerts_unviewed = GREATEST(0, cast(alerts_unviewed AS SIGNED) - ?),
                    alerts_unread = GREATEST(0, cast(alerts_unread AS SIGNED) - ?)
                WHERE user_id = ?
            ', [$viewRowsAffected, $readRowsAffected, $userId]
            );

            if ($statement->rowsAffected() > 0)
            {
                $this->refreshUserAlertCounters($user);
            }
        }

        if ($updateAlertEntities)
        {
            $em = $this->em;
            foreach ($alertIds as $alertId)
            {
                /** @var UserAlertEntity $alert */
                $alert = $em->findCached('XF:UserAlert', $alertId);
                if ($alert)
                {
                    $alert->setAsSaved('view_date', $readDate);
                    $alert->setAsSaved('read_date', $readDate);
                }
            }
        }
    }

    /**
     * @param User|ExtendedUserEntity $user
     * @param int[]                   $alertIds
     * @param bool                    $disableAutoRead
     * @param bool                    $updateAlertEntities
     * @noinspection PhpDocMissingThrowsInspection
     */
    public function markAlertIdsAsUnreadAndUnviewed(User $user, array $alertIds, bool $disableAutoRead = false, bool $updateAlertEntities = false)
    {
        if (count($alertIds) === 0)
        {
            return;
        }

        $disableAutoReadSql = $disableAutoRead ? ', auto_read = 0 ' : '';
        $userId = (int)$user->user_id;
        $db = $this->db();
        if ($db->inTransaction())
        {
            // Only enforce table lock ordering if this function was called inside a transaction
            // this avoids updating xf_user/xf_user_alert inside a transaction when something else does xf_user_alert/xf_user
            // outside of a transaction, these tables are not linked
            $db->fetchOne('SELECT user_id FROM xf_user WHERE user_id = ? FOR UPDATE', $userId);
        }

        if (Globals::isSkippingExpiredAlerts())
        {
            $skipExpiredAlertSql =  ' AND (alert.view_date >= ? OR (alert.view_date = 0 AND alert.event_date >= ?)) ';
            [$viewedCutOff, $unviewedCutOff] = $this->getIgnoreAlertCutOffs();
            $args = [$userId, $viewedCutOff, $unviewedCutOff];
        }
        else
        {
            $skipExpiredAlertSql = '';
            $args = [$userId];
        }

        $ids = $db->quote($alertIds);
        /** @noinspection SqlWithoutWhere */
        $stmt = $db->query('
                UPDATE IGNORE xf_user_alert as alert
                SET view_date = 0 ' . $disableAutoReadSql . '
                WHERE alerted_user_id = ? AND alert_id IN (' . $ids . ') '.$skipExpiredAlertSql.'
            ', $args
        );
        $viewRowsAffected = $stmt->rowsAffected();

        /** @noinspection SqlWithoutWhere */
        $stmt = $db->query('
                UPDATE IGNORE xf_user_alert as alert
                SET read_date = 0 ' . $disableAutoReadSql . '
                WHERE alerted_user_id = ? AND alert_id IN (' . $ids . ') '.$skipExpiredAlertSql.'
            ', $args
        );
        $readRowsAffected = $stmt->rowsAffected();

        if (!$viewRowsAffected && !$readRowsAffected)
        {
            return;
        }

        try
        {
            $db->query('
                UPDATE xf_user
                SET alerts_unviewed = LEAST(alerts_unviewed + ?, ?),
                    alerts_unread = LEAST(alerts_unread + ?, ?)
                WHERE user_id = ?
            ', [$viewRowsAffected, $this->svUserMaxAlertCount , $readRowsAffected, $this->svUserMaxAlertCount , $userId]
            );

            $alerts_unviewed = min($this->svUserMaxAlertCount, $user->alerts_unviewed + $viewRowsAffected);
            $alerts_unread = min($this->svUserMaxAlertCount, $user->alerts_unread + $readRowsAffected);
        }
        catch (DeadlockException $e)
        {
            $db->query('
                UPDATE xf_user
                SET alerts_unviewed = LEAST(alerts_unviewed + ?, ?),
                    alerts_unread = LEAST(alerts_unread + ?, ?)
                WHERE user_id = ?
            ', [$viewRowsAffected, $this->svUserMaxAlertCount , $readRowsAffected, $this->svUserMaxAlertCount , $userId]
            );

            $row = $db->fetchRow('SELECT alerts_unviewed, alerts_unread FROM xf_user WHERE user_id = ?', $userId);
            if (!$row)
            {
                return;
            }
            $alerts_unviewed = $row['alerts_unviewed'];
            $alerts_unread = $row['alerts_unread'];
        }

        if ($updateAlertEntities)
        {
            $em = $this->em;
            foreach ($alertIds as $alertId)
            {
                /** @var UserAlertEntity $alert */
                $alert = $em->findCached('XF:UserAlert', $alertId);
                if ($alert)
                {
                    $alert->setAsSaved('view_date', 0);
                    $alert->setAsSaved('read_date', 0);
                    if ($disableAutoRead)
                    {
                        $alert->setAsSaved('auto_read', 0);
                    }
                }
            }
        }

        $user->setAsSaved('alerts_unviewed', $alerts_unviewed);
        $user->setAsSaved('alerts_unread', $alerts_unread);
    }

    /**
     * @param array|AbstractCollection|null $contents
     * @return int[]
     */
    public function getContentIdKeys($contents): array
    {
        if (is_array($contents))
        {
            return array_keys($contents);
        }

        if ($contents instanceof AbstractCollection)
        {
            return $contents->keys();
        }

        return [];
    }

    /**
     * @param string        $contentType
     * @param int[]         $contentIds
     * @param string[]|null $actions
     * @param int           $maxXFVersion
     * @param User|null     $user
     * @param int|null      $viewDate
     * @param bool          $respectAutoMarkRead
     */
    public function markAlertsReadForContentIds(string $contentType, array $contentIds, array $actions = null, int $maxXFVersion = 0, User $user = null, int $viewDate = null, bool $respectAutoMarkRead = false)
    {
        // do not mark alerts as read when prefetching is happening
        if (Globals::isPrefetchRequest())
        {
            return;
        }

        if ($maxXFVersion !== 0&& \XF::$versionId > $maxXFVersion)
        {
            return;
        }

        if (count($contentIds) === 0)
        {
            return;
        }

        $user = $user ?? \XF::visitor();
        $userId = (int)$user->user_id;
        if ($userId === 0 || $user->alerts_unread === 0)
        {
            return;
        }

        $viewDate = $viewDate ?: \XF::$time;

        $db = $this->db();

        $actionFilter = $actions ? ' AND action in (' . $db->quote($actions) . ') ' : '';
        $autoMarkReadFilter = $respectAutoMarkRead ? ' AND auto_read = 1 ' : '';

        if (Globals::isSkippingExpiredAlerts())
        {
            $skipExpiredAlertSql = ' AND (alert.view_date >= ? OR (alert.view_date = 0 AND alert.event_date >= ?)) ';
            [$viewedCutOff, $unviewedCutOff] = $this->getIgnoreAlertCutOffs();
            $args = [$userId, $viewDate, $viewedCutOff, $unviewedCutOff];
        }
        else
        {
            $skipExpiredAlertSql = '';
            $args = [$userId, $viewDate];
        }
        // Do a select first to reduce the amount of rows that can be touched for the update.
        // This hopefully reduces contention as must of the time it should just be a select, without any updates
        $alertIds = $db->fetchAllColumn(
            '
            SELECT alert_id
            FROM xf_user_alert as alert
            WHERE alerted_user_id = ?
            AND (view_date = 0) ' . $autoMarkReadFilter . '
            AND event_date < ?
            AND content_type IN (' . $db->quote($contentType) . ')
            AND content_id IN (' . $db->quote($contentIds) . ')
            '.$skipExpiredAlertSql.'
            '.$actionFilter.'
        ', $args); // do not bother selecting `read_date = 0 OR`

        if (count($alertIds) === 0)
        {
            return;
        }

        $this->markAlertIdsAsReadAndViewed($user, $alertIds, $viewDate);
    }


    /**
     * @param UserAlertEntity|ExtendedUserAlertEntity $alert
     * @param int|null                                $readDate
     * @noinspection PhpMissingParentCallCommonInspection
     */
    public function markUserAlertRead(UserAlertEntity $alert, $readDate = null)
    {
        $user = $alert->Receiver;
        if (!$user || !$alert->isUnread())
        {
            return;
        }

        $readDate = $readDate ?: \XF::$time;
        $this->markAlertIdsAsReadAndViewed($user, [$alert->alert_id], $readDate, true);
    }

    /**
     * @param UserAlertEntity|ExtendedUserAlertEntity $alert
     * @param bool                                    $disableAutoRead
     * @noinspection PhpMissingParentCallCommonInspection
     */
    public function markUserAlertUnread(UserAlertEntity $alert, bool $disableAutoRead = true)
    {
        $user = $alert->Receiver;
        if (!$user || $alert->isUnread())
        {
            return;
        }

        $this->markAlertIdsAsUnreadAndUnviewed($user, [$alert->alert_id], $disableAutoRead, true);
    }

    /**
     * @param User $user
     * @return bool
     * @noinspection PhpMissingReturnTypeInspection
     * @noinspection PhpMissingParentCallCommonInspection
     */
    public function updateUnviewedCountForUser(User $user)
    {
        $userId = $user->user_id;
        $result = $this->updateUnviewedCountForUserId($userId);

        if ($result)
        {
            // this doesn't need to be in a transaction as it is an advisory read
            $count = \XF::db()->fetchOne('
                SELECT alerts_unviewed 
                FROM xf_user 
                WHERE user_id = ?
            ', $userId);
            $user->setAsSaved('alerts_unviewed', $count);
        }
        return $result;
    }

    /**
     * @param User $user
     * @return bool
     * @noinspection PhpMissingReturnTypeInspection
     * @noinspection PhpMissingParentCallCommonInspection
     */
    public function updateUnreadCountForUser(User $user)
    {
        $userId = (int)$user->user_id;
        $result = $this->updateUnreadCountForUserId($userId);

        if ($result)
        {
            // this doesn't need to be in a transaction as it is an advisory read
            $count = $this->db()->fetchOne('
                SELECT alerts_unread 
                FROM xf_user 
                WHERE user_id = ?
            ', $userId);
            $user->setAsSaved('alerts_unread', $count);
        }

        return $result;
    }

    public function updateUnviewedCountForUserId(int $userId): bool
    {
        if ($userId === 0)
        {
            return false;
        }

        $db = \XF::db();
        $inTransaction = $db->inTransaction();
        if (!$inTransaction)
        {
            $db->beginTransaction();
        }

        $userId = (int)$db->fetchOne('SELECT user_id FROM xf_user WHERE user_id = ? FOR UPDATE', [$userId]);
        if ($userId === 0)
        {
            if (!$inTransaction)
            {
                $db->commit();
            }

            return false;
        }

        if (Globals::isSkippingExpiredAlerts())
        {
            $skipExpiredAlertSql =  ' AND event_date >= ? ';
            /** @noinspection PhpUnusedLocalVariableInspection */
            [$viewedCutOff, $unviewedCutOff] = $this->getIgnoreAlertCutOffs();
            $args = [$userId, $unviewedCutOff];
        }
        else
        {
            $skipExpiredAlertSql = '';
            $args = [$userId];
        }

        $count = min($this->svUserMaxAlertCount, (int)$db->fetchOne('
            SELECT COUNT(alert_id) 
            FROM xf_user_alert
            WHERE alerted_user_id = ? AND view_date = 0 AND summerize_id IS NULL '.$skipExpiredAlertSql.'
        ', $args));

        $statement = $db->query('
            UPDATE xf_user
            SET alerts_unviewed = ?
            WHERE alerts_unviewed != ? AND user_id = ? 
        ', [$count, $count, $userId]);

        if (!$inTransaction)
        {
            $db->commit();
        }

        return $statement->rowsAffected() > 0;
    }

    public function updateUnreadCountForUserId(int $userId): bool
    {
        if (!$userId)
        {
            return false;
        }

        $db = \XF::db();
        $inTransaction = $db->inTransaction();
        if (!$inTransaction)
        {
            $db->beginTransaction();
        }

        $userId = $db->fetchOne('SELECT user_id FROM xf_user WHERE user_id = ? FOR UPDATE', [$userId]);
        if (!$userId)
        {
            if (!$inTransaction)
            {
                $db->commit();
            }

            $this->cleanupPendingAlertRebuild($userId);

            return false;
        }

        if (Globals::isSkippingExpiredAlerts())
        {
            $skipExpiredAlertSql =  ' AND (alert.view_date >= ? OR (alert.view_date = 0 AND alert.event_date >= ?)) ';
            [$viewedCutOff, $unviewedCutOff] = $this->getIgnoreAlertCutOffs();
            $args = [$userId, $viewedCutOff, $unviewedCutOff];
        }
        else
        {
            $skipExpiredAlertSql = '';
            $args = [$userId];
        }

        $count = min($this->svUserMaxAlertCount, (int)$db->fetchOne('
            SELECT COUNT(alert_id) 
            FROM xf_user_alert as alert
            WHERE alerted_user_id = ? AND read_date = 0 AND summerize_id IS NULL '.$skipExpiredAlertSql.'
        ', $args));

        $statement = $db->query('
            UPDATE xf_user
            SET alerts_unread = ?
            WHERE alerts_unread != ? AND user_id = ? 
        ', [$count, $count, $userId]);

        if (!$inTransaction)
        {
            $db->commit();
        }

        $this->cleanupPendingAlertRebuild($userId);

        return $statement->rowsAffected() > 0;
    }

    public function insertPendingAlertRebuild(int $userId)
    {
        if (!$userId)
        {
            return;
        }

        $this->db()->query('INSERT IGNORE xf_sv_user_alert_rebuild (user_id, rebuild_date) values (?, unix_timestamp())', [$userId]);
    }

    public function cleanupPendingAlertRebuild(int $userId)
    {
        if (!$userId)
        {
            return;
        }

        $this->db()->query('DELETE FROM xf_sv_user_alert_rebuild WHERE user_id = ?', [$userId]);
    }

    protected function getAlertSummarizationRepo(): AlertSummarization
    {
        /** @noinspection PhpIncompatibleReturnTypeInspection */
        return $this->repository('SV\AlertImprovements:AlertSummarization');
    }
}
