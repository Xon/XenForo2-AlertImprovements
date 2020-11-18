<?php

namespace SV\AlertImprovements\XF\Repository;

use SV\AlertImprovements\Globals;
use SV\AlertImprovements\ISummarizeAlert;
use SV\AlertImprovements\XF\Finder\UserAlert as ExtendedUserAlertFinder;
use XF\Db\AbstractAdapter;
use XF\Db\DeadlockException;
use SV\AlertImprovements\XF\Entity\UserAlert as ExtendedUserAlertEntity;
use XF\Entity\User;
use XF\Entity\UserAlert as UserAlertEntity;
use XF\Mvc\Entity\Finder;

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

    public function summarizeAlertsForUser(User $user)
    {
        // post rating summary alerts really can't me merged, so wipe all summary alerts, and then try again
        $this->db()->executeTransaction(function (AbstractAdapter $db) use ($user) {

            $db->query("
                DELETE FROM xf_user_alert
                WHERE alerted_user_id = ? AND summerize_id IS NULL AND `action` LIKE '%_summary'
            ", $user->user_id);

            $db->query('
                UPDATE xf_user_alert
                SET summerize_id = NULL
                WHERE alerted_user_id = ? AND summerize_id IS NOT NULL
            ', $user->user_id);

            $this->updateUnviewedCountForUser($user);
            $this->updateUnreadCountForUser($user);
        }, AbstractAdapter::ALLOW_DEADLOCK_RERUN);

        // do summerization outside the above transaction
        $this->checkSummarizeAlertsForUser($user->user_id, true, true, \XF::$time);
    }

    /**
     * @param User $user
     * @param int  $alertId
     * @return ExtendedUserAlertFinder|Finder
     */
    public function findAlertForUser(User $user, int $alertId): ExtendedUserAlertFinder
    {
        /** @var ExtendedUserAlertFinder $finder */
        $finder = $this->finder('XF:UserAlert')
                    ->where(['alert_id', $alertId])
                    ->whereAddOnActive([
                        'column' => 'depends_on_addon_id'
                    ]);
        if ($user->user_id)
        {
            $finder->where(['alerted_user_id', $user->user_id]);
        }
        else
        {
            $finder->whereImpossible();
        }

        return $finder;
    }

    /**
     * @param int      $userId
     * @param null|int $cutOff
     * @return Finder
     * @noinspection PhpMissingParamTypeInspection
     */
    public function findAlertsForUser($userId, $cutOff = null)
    {
        /** @var ExtendedUserAlertFinder $finder */
        $finder = parent::findAlertsForUser($userId, $cutOff);
        if (!Globals::$skipSummarizeFilter)
        {
            $finder->where(['summerize_id', null]);
        }

        if (Globals::$showUnreadOnly)
        {
            $finder->whereOr([
                ['read_date', '=', 0],
                ['view_date', '=', 0]
            ]);
        }

        if (Globals::$skipSummarize)
        {
            return $finder;
        }

        $finder->shimSource(
            function ($limit, $offset) use ($userId, $finder) {
                if ($offset !== 0)
                {
                    return null;
                }
                $alerts = $this->checkSummarizeAlertsForUser($userId, false, !Globals::$showUnreadOnly, \XF::$time);

                if ($alerts === null)
                {
                    return null;
                }
                if ($limit > 0)
                {
                    $alerts = array_slice($alerts, 0, $limit, true);
                }

                return $finder->materializeAlerts($alerts);
            }
        );

        return $finder;
    }

    /**
     * @param int  $userId
     * @param bool $force
     * @param bool $ignoreReadState
     * @param int  $summaryAlertViewDate
     * @return array|null
     * @throws \Exception
     */
    protected function checkSummarizeAlertsForUser(int $userId, bool $force, bool $ignoreReadState, int $summaryAlertViewDate)
    {
        if ($userId !== \XF::visitor()->user_id)
        {
            /** @var User $user */
            $user = $this->finder('XF:User')
                         ->where('user_id', $userId)
                         ->fetchOne();

            return \XF::asVisitor(
                $user,
                function () use ($force, $ignoreReadState, $summaryAlertViewDate) {
                    return $this->checkSummarizeAlerts($force, $ignoreReadState, $summaryAlertViewDate);
                }
            );
        }

        return $this->checkSummarizeAlerts($force, $ignoreReadState, $summaryAlertViewDate);
    }

    /**
     * @param bool $force
     * @param bool $ignoreReadState
     * @param int  $summaryAlertViewDate
     * @return null|array
     */
    protected function checkSummarizeAlerts(bool $force, bool $ignoreReadState, int $summaryAlertViewDate)
    {
        if ($force || $this->canSummarizeAlerts())
        {
            return $this->summarizeAlerts($ignoreReadState, $summaryAlertViewDate);
        }

        return null;
    }

    public function insertUnsummarizedAlerts(User $user, int $summaryId)
    {
        $this->db()->executeTransaction(function (AbstractAdapter $db) use ($user, $summaryId) {
            $userId = $user->user_id;
            // Delete summary alert
            /** @var ExtendedUserAlertEntity $summaryAlert */
            $summaryAlert = $this->finder('XF:UserAlert')
                                 ->where('alert_id', $summaryId)
                                 ->fetchOne();
            if (!$summaryAlert)
            {
                return;
            }
            $summaryAlert->delete(false, false);

            $db->query('select user_id from xf_user where user_id = ? for update', $userId);

            // Make alerts visible
            $unreadIncrement = $db->query('
                UPDATE IGNORE xf_user_alert
                SET summerize_id = NULL, read_date = 0
                WHERE alerted_user_id = ? AND summerize_id = ? AND read_date <> 0
            ', [$userId, $summaryId])->rowsAffected();

            $unviewedIncrement = $db->query('
                UPDATE IGNORE xf_user_alert
                SET summerize_id = NULL, view_date = 0
                WHERE alerted_user_id = ? AND summerize_id = ? AND view_date <> 0
            ', [$userId, $summaryId])->rowsAffected();

            // Reset unread alerts counter
            $db->query('
                UPDATE xf_user
                SET alerts_unread = LEAST(alerts_unread + ?, ' . $this->svUserMaxAlertCount . '),
                    alerts_unviewed = LEAST(alerts_unviewed + ?, ' . $this->svUserMaxAlertCount . ')
                WHERE user_id = ?
            ', [$unreadIncrement, $unviewedIncrement, $userId]);

            $user->setAsSaved('alerts_unread', $user->alerts_unread + $unreadIncrement);
            $user->setAsSaved('alerts_unread', $user->alerts_unviewed + $unviewedIncrement);
        });
    }

    protected function canSummarizeAlerts(): bool
    {
        if (Globals::$skipSummarize)
        {
            return false;
        }

        if (empty(\XF::options()->svAlertsSummarize))
        {
            return false;
        }

        $visitor = \XF::visitor();
        /** @var \SV\AlertImprovements\XF\Entity\UserOption $option */
        $option = $visitor->Option;
        $summarizeThreshold = $option->sv_alerts_summarize_threshold;
        $summarizeUnreadThreshold = $summarizeThreshold * 2 > 25 ? 25 : $summarizeThreshold * 2;

        return $visitor->alerts_unviewed > $summarizeUnreadThreshold;
    }

    public function summarizeAlerts(bool $ignoreReadState, int $summaryAlertViewDate): array
    {
        // TODO : finish summarizing alerts
        $xfOptions = \XF::options();
        $visitor = \XF::visitor();
        $userId = $visitor->user_id;
        /** @var \SV\AlertImprovements\XF\Entity\UserOption $option */
        $option = $visitor->Option;
        $summarizeThreshold = $option->sv_alerts_summarize_threshold;

        /** @var ExtendedUserAlertFinder $finder */
        $finder = $this->finder('XF:UserAlert')
                       ->where('alerted_user_id', $userId)
                       ->whereAddOnActive([
                           'column' => 'depends_on_addon_id'
                       ])
                       ->order('event_date', 'desc');
        if (!$ignoreReadState)
        {
            $finder->whereOr([
                ['read_date', '=', 0],
                ['view_date', '=', 0]
            ]);
        }
        $finder->where('summerize_id', null);

        if (!empty($xfOptions->svAlertsSummerizeLimit) && $xfOptions->svAlertsSummerizeLimit > 0)
        {
            $finder->limit($xfOptions->svAlertsSummerizeLimit);
        }

        /** @var array $alerts */
        $alerts = $finder->fetchRaw();

        $outputAlerts = [];

        // build the list of handlers at once, and exclude based
        $handlers = $this->getAlertHandlersForConsolidation();
        // nothing to be done
        $userHandler = empty($handlers['user']) ? null : $handlers['user'];
        if (empty($handlers) || ($userHandler && count($handlers) == 1))
        {
            return $alerts;
        }

        // collect alerts into groupings by content/id
        $groupedContentAlerts = [];
        $groupedUserAlerts = [];
        $groupedAlerts = false;
        foreach ($alerts AS $id => $item)
        {
            if ((!$ignoreReadState && $item['view_date']) ||
                empty($handlers[$item['content_type']]) ||
                (bool)preg_match('/^.*_summary$/', $item['action']))
            {
                $outputAlerts[$id] = $item;
                continue;
            }
            $handler = $handlers[$item['content_type']];
            if (!$handler->canSummarizeItem($item))
            {
                $outputAlerts[$id] = $item;
                continue;
            }

            $contentType = $item['content_type'];
            $contentId = $item['content_id'];
            $contentUserId = $item['user_id'];
            if ($handler->consolidateAlert($contentType, $contentId, $item))
            {
                $groupedContentAlerts[$contentType][$contentId][$id] = $item;

                if ($userHandler && $userHandler->canSummarizeItem($item))
                {
                    if (!isset($groupedUserAlerts[$contentUserId]))
                    {
                        $groupedUserAlerts[$contentUserId] = ['c' => 0, 'd' => []];
                    }
                    $groupedUserAlerts[$contentUserId]['c'] += 1;
                    $groupedUserAlerts[$contentUserId]['d'][$contentType][$contentId][$id] = $item;
                }
            }
            else
            {
                $outputAlerts[$id] = $item;
            }
        }

        // determine what can be summerised by content types. These require explicit support (ie a template)
        $grouped = 0;
        foreach ($groupedContentAlerts AS $contentType => &$contentIds)
        {
            $handler = $handlers[$contentType];
            foreach ($contentIds AS $contentId => $alertGrouping)
            {
                if ($this->insertSummaryAlert(
                    $handler, $summarizeThreshold, $contentType, $contentId, $alertGrouping, $grouped, $outputAlerts,
                    'content', 0, $summaryAlertViewDate
                ))
                {
                    unset($contentIds[$contentId]);
                    $groupedAlerts = true;
                }
            }
        }

        // see if we can group some alert by user (requires deap knowledge of most content types and the template)
        if ($userHandler)
        {
            foreach ($groupedUserAlerts AS $senderUserId => &$perUserAlerts)
            {
                if (!$summarizeThreshold || $perUserAlerts['c'] < $summarizeThreshold)
                {
                    unset($groupedUserAlerts[$senderUserId]);
                    continue;
                }

                $userAlertGrouping = [];
                foreach ($perUserAlerts['d'] AS $contentType => &$contentIds)
                {
                    foreach ($contentIds AS $contentId => $alertGrouping)
                    {
                        foreach ($alertGrouping AS $id => $alert)
                        {
                            if (isset($groupedContentAlerts[$contentType][$contentId][$id]))
                            {
                                $alert['content_type_map'] = $contentType;
                                $alert['content_id_map'] = $contentId;
                                $userAlertGrouping[$id] = $alert;
                            }
                        }
                    }
                }
                if ($userAlertGrouping && $this->insertSummaryAlert(
                        $userHandler, $summarizeThreshold, 'user', $userId, $userAlertGrouping, $grouped, $outputAlerts,
                        'user', $senderUserId, $summaryAlertViewDate
                    ))
                {
                    foreach ($userAlertGrouping AS $id => $alert)
                    {
                        unset($groupedContentAlerts[$alert['content_type_map']][$alert['content_id_map']][$id]);
                    }
                    $groupedAlerts = true;
                }
            }
        }

        // output ungrouped alerts
        foreach ($groupedContentAlerts AS $contentType => $contentIds)
        {
            foreach ($contentIds AS $contentId => $alertGrouping)
            {
                foreach ($alertGrouping AS $alertId => $alert)
                {
                    $outputAlerts[$alertId] = $alert;
                }
            }
        }

        // update alert totals
        if ($groupedAlerts)
        {
            $this->updateUnreadCountForUser($visitor);
            $this->updateUnviewedCountForUser($visitor);
        }

        uasort(
            $outputAlerts,
            function ($a, $b) {
                if ($a['event_date'] == $b['event_date'])
                {
                    return ($a['alert_id'] < $b['alert_id']) ? 1 : -1;
                }

                return ($a['event_date'] < $b['event_date']) ? 1 : -1;
            }
        );

        return $outputAlerts;
    }

    /**
     * @param ISummarizeAlert           $handler
     * @param int                       $summarizeThreshold
     * @param string                    $contentType
     * @param int                       $contentId
     * @param ExtendedUserAlertEntity[] $alertGrouping
     * @param int                       $grouped
     * @param ExtendedUserAlertEntity[] $outputAlerts
     * @param string                    $groupingStyle
     * @param int                       $senderUserId
     * @param int                       $summaryAlertViewDate
     * @return bool
     */
    protected function insertSummaryAlert(ISummarizeAlert $handler, int $summarizeThreshold, string $contentType, int $contentId, array $alertGrouping, int &$grouped, array &$outputAlerts, string $groupingStyle, int $senderUserId, int $summaryAlertViewDate) : bool
    {
        $grouped = 0;
        if (!$summarizeThreshold || count($alertGrouping) < $summarizeThreshold)
        {
            return false;
        }
        $lastAlert = reset($alertGrouping);

        // inject a grouped alert with the same content type/id, but with a different action
        $summaryAlert = [
            'depends_on_addon_id' => 'SV/AlertImprovements',
            'alerted_user_id'     => $lastAlert['alerted_user_id'],
            'user_id'             => $senderUserId,
            'username'            => $senderUserId ? $lastAlert['username'] : 'Guest',
            'content_type'        => $contentType,
            'content_id'          => $contentId,
            'action'              => $lastAlert['action'] . '_summary',
            'event_date'          => $lastAlert['event_date'],
            'view_date'           => $summaryAlertViewDate,
            'read_date'           => $summaryAlertViewDate,
            'extra_data'          => [],
        ];
        $contentTypes = [];

        if ($lastAlert['action'] === 'rating')
        {
            foreach ($alertGrouping AS $alert)
            {
                if (!empty($alert['extra_data']) && $alert['action'] === $lastAlert['action'])
                {
                    if (!isset($contentTypes[$alert['content_type']]))
                    {
                        $contentTypes[$alert['content_type']] = 0;
                    }
                    $contentTypes[$alert['content_type']]++;

                    $extraData = @\json_decode($alert['extra_data'], true);

                    if (is_array($extraData))
                    {
                        foreach ($extraData AS $extraDataKey => $extraDataValue)
                        {
                            if (empty($summaryAlert['extra_data'][$extraDataKey][$extraDataValue]))
                            {
                                $summaryAlert['extra_data'][$extraDataKey][$extraDataValue] = 1;
                            }
                            else
                            {
                                $summaryAlert['extra_data'][$extraDataKey][$extraDataValue]++;
                            }
                        }
                    }
                }
            }

            if (isset($summaryAlert['extra_data']['reaction_id']))
            {
                $reactionId = $summaryAlert['extra_data']['reaction_id'];
                if (is_array($reactionId) && count($reactionId) === 1)
                {
                    $likeRatingId = (int)$this->app()->options()->svContentRatingsLikeRatingType;

                    if ($likeRatingId && !empty($reactionId[$likeRatingId]))
                    {
                        $summaryAlert['extra_data']['likes'] = $reactionId[$likeRatingId];
                    }
                }
            }
        }
        else if ($lastAlert['action'] === 'like')
        {
            $likesCounter = 0;
            foreach ($alertGrouping AS $alert)
            {
                if ($alert['action'] === 'like')
                {
                    if (!isset($contentTypes[$alert['content_type']]))
                    {
                        $contentTypes[$alert['content_type']] = 0;
                    }
                    $contentTypes[$alert['content_type']]++;
                    $likesCounter++;
                }
            }
            $summaryAlert['extra_data']['likes'] = $likesCounter;
        }
        else if ($lastAlert['action'] === 'reaction')
        {
            foreach ($alertGrouping AS $alert)
            {
                if (!empty($alert['extra_data']) && $alert['action'] === $lastAlert['action'])
                {
                    if (!isset($contentTypes[$alert['content_type']]))
                    {
                        $contentTypes[$alert['content_type']] = 0;
                    }
                    $contentTypes[$alert['content_type']]++;

                    $extraData = @\json_decode($alert['extra_data'], true);

                    if (is_array($extraData))
                    {
                        foreach ($extraData AS $extraDataKey => $extraDataValue)
                        {
                            if (empty($summaryAlert['extra_data'][$extraDataKey][$extraDataValue]))
                            {
                                $summaryAlert['extra_data'][$extraDataKey][$extraDataValue] = 1;
                            }
                            else
                            {
                                $summaryAlert['extra_data'][$extraDataKey][$extraDataValue]++;
                            }
                        }
                    }
                }
            }
        }

        if ($contentTypes)
        {
            $summaryAlert['extra_data']['ct'] = $contentTypes;
        }

        if ($summaryAlert['extra_data'] === false)
        {
            $summaryAlert['extra_data'] = [];
        }

        $summaryAlert = $handler->summarizeAlerts($summaryAlert, $alertGrouping, $groupingStyle);
        if (empty($summaryAlert))
        {
            return false;
        }

        $summerizeId = $rowsAffected = null;
        $db = $this->db();
        $batchIds = \array_column($alertGrouping, 'alert_id');

        // depending on context; insertSummaryAlert may be called inside a transaction or not so we want to re-run deadlocks immediately if there is no transaction otherwise allow the caller to run
        $updateAlerts = function () use ($db, $batchIds, $summaryAlert, &$alert, &$rowsAffected, &$summerizeId) {
            // database update
            /** @var ExtendedUserAlertEntity $alert */
            $alert = $this->em->create('XF:UserAlert');
            $alert->bulkSet($summaryAlert);
            $alert->save(true, false);
            $summerizeId = $alert->alert_id;

            // hide the non-summary alerts
            $stmt = $db->query('
                UPDATE xf_user_alert
                SET summerize_id = ?, view_date = if(view_date = 0, ?, view_date), read_date = if(read_date = 0, ?, read_date)
                WHERE alert_id IN (' . $db->quote($batchIds) . ')
            ', [$summerizeId, \XF::$time, \XF::$time]);
            $rowsAffected = $stmt->rowsAffected();

            return $rowsAffected;
        };

        if ($db->inTransaction())
        {
            $updateAlerts();
        }
        else
        {
            $db->executeTransaction($updateAlerts, AbstractAdapter::ALLOW_DEADLOCK_RERUN);
        }

        // add to grouping
        $grouped += $rowsAffected;
        $outputAlerts[$summerizeId] = $alert->toArray();

        return true;
    }

    /**
     * @return \XF\Alert\AbstractHandler[]|ISummarizeAlert[]
     */
    public function getAlertHandlersForConsolidation()
    {
        $optOuts = \XF::visitor()->Option->alert_optout;
        $handlers = $this->getAlertHandlers();
        unset($handlers['bookmark_post_alt']);
        foreach ($handlers AS $key => $handler)
        {
            /** @var ISummarizeAlert $handler */
            if (!($handler instanceof ISummarizeAlert) || !$handler->canSummarizeForUser($optOuts))
            {
                unset($handlers[$key]);
            }
        }

        return $handlers;
    }

    /**
     * @param User $user
     * @param null|int   $viewDate
     */
    public function markUserAlertsViewed(User $user, $viewDate = null)
    {
        $this->markUserAlertsRead($user, $viewDate);
    }

    public function markUserAlertViewed(UserAlertEntity $alert, $viewDate = null)
    {
        $this->markUserAlertRead($alert, $viewDate);
    }

    /**
     * @param User $user
     * @param null|int   $readDate
     */
    public function markUserAlertsRead(User $user, $readDate = null)
    {
        if (Globals::$skipMarkAlertsRead || !$user->user_id)
        {
            return;
        }

        if ($readDate === null)
        {
            $readDate = \XF::$time;
        }

        $db = $this->db();
        $db->executeTransaction(function() use ($db, $readDate, $user)
        {
            $db->update('xf_user_alert', ['view_date' => $readDate], "alerted_user_id = ? AND view_date = 0", $user->user_id);
            $db->update('xf_user_alert', ['read_date' => $readDate], "alerted_user_id = ? AND read_date = 0", $user->user_id);

            $user->alerts_unviewed = 0;
            $user->alerts_unread = 0;
            $user->save(true, false);
        }, AbstractAdapter::ALLOW_DEADLOCK_RERUN);
    }

    public function autoMarkUserAlertsRead(\XF\Mvc\Entity\AbstractCollection $alerts, User $user, $readDate = null)
    {
        if (Globals::$skipMarkAlertsRead)
        {
            return;
        }

        $alerts = $alerts->filter(function(UserAlertEntity $alert)
        {
            return $alert->getHandler() === null || ($alert->isUnread() && $alert->auto_read);
        });

        $this->markSpecificUserAlertsRead($alerts, $user, $readDate);
    }

    protected function markSpecificUserAlertsRead(\XF\Mvc\Entity\AbstractCollection $alerts, User $user, int $readDate = null)
    {
        $userId = $user->user_id;
        if (!$userId || !$alerts->count())
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
            /** @var UserAlertEntity $alert */
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

    public function markUserAlertsReadForContent($contentType, $contentIds, $onlyActions = null, User $user = null, $viewDate = null)
    {
        if (!is_array($contentIds))
        {
            $contentIds = [$contentIds];
        }
        if ($onlyActions && !is_array($onlyActions))
        {
            $onlyActions = [$onlyActions];
        }

        $this->markAlertsReadForContentIds($contentType, $contentIds, $onlyActions, 0, $user ?: \XF::visitor(), $viewDate);
    }

    /**
     * @param User  $user
     * @param int[] $alertIds
     * @param int   $readDate
     * @param bool  $updateAlertEntities
     */
    public function markAlertIdsAsReadAndViewed(User $user, array $alertIds, int $readDate, bool $updateAlertEntities = false)
    {
        if (!\count($alertIds))
        {
            return;
        }

        $userId = $user->user_id;
        $db = $this->db();
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

            $alerts_unviewed = max(0,$user->alerts_unviewed - $viewRowsAffected);
            $alerts_unread = max(0,$user->alerts_unread - $readRowsAffected);
        }
            /** @noinspection PhpRedundantCatchClauseInspection */
        catch (DeadlockException $e)
        {
            $db->query('
                UPDATE xf_user
                SET alerts_unviewed = GREATEST(0, cast(alerts_unviewed AS SIGNED) - ?),
                    alerts_unread = GREATEST(0, cast(alerts_unread AS SIGNED) - ?)
                WHERE user_id = ?
            ', [$viewRowsAffected, $readRowsAffected, $userId]
            );

            $row = $db->fetchRow('select alerts_unviewed, alerts_unread from xf_user where user_id = ?', $userId);
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
                    $alert->setAsSaved('view_date', $readDate);
                    $alert->setAsSaved('read_date', $readDate);
                }
            }
        }

        $user->setAsSaved('alerts_unviewed', $alerts_unviewed);
        $user->setAsSaved('alerts_unread', $alerts_unread);
    }

    /**
     * @param User  $user
     * @param int[] $alertIds
     * @param bool  $disableAutoRead
     * @param bool  $updateAlertEntities
     */
    public function markAlertIdsAsUnreadAndUnviewed(User $user, array $alertIds, bool $disableAutoRead = false, bool $updateAlertEntities = false)
    {
        if (!\count($alertIds))
        {
            return;
        }

        $disableAutoReadSql = $disableAutoRead ? ', auto_read = 0 ' : '';
        $userId = $user->user_id;
        $db = $this->db();
        $ids = $db->quote($alertIds);
        /** @noinspection SqlWithoutWhere */
        $stmt = $db->query('
                UPDATE IGNORE xf_user_alert
                SET view_date = 0 ' . $disableAutoReadSql . '
                WHERE alerted_user_id = ? AND alert_id IN (' . $ids . ')
            ', [$userId]
        );
        $viewRowsAffected = $stmt->rowsAffected();

        /** @noinspection SqlWithoutWhere */
        $stmt = $db->query('
                UPDATE IGNORE xf_user_alert
                SET read_date = 0 ' . $disableAutoReadSql . '
                WHERE alerted_user_id = ? AND alert_id IN (' . $ids . ')
            ', [$userId]
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
                SET alerts_unviewed = LEAST(alerts_unviewed + ?, ' . $this->svUserMaxAlertCount . '),
                    alerts_unread = LEAST(alerts_unread + ?, ' . $this->svUserMaxAlertCount . ')
                WHERE user_id = ?
            ', [$viewRowsAffected, $readRowsAffected, $userId]
            );

            $alerts_unviewed = min($this->svUserMaxAlertCount,$user->alerts_unviewed + $viewRowsAffected);
            $alerts_unread = min($this->svUserMaxAlertCount,$user->alerts_unread + $readRowsAffected);
        }
            /** @noinspection PhpRedundantCatchClauseInspection */
        catch (DeadlockException $e)
        {
            $db->query('
                UPDATE xf_user
                SET alerts_unviewed = LEAST(alerts_unviewed + ?, ' . $this->svUserMaxAlertCount . '),
                    alerts_unread = LEAST(alerts_unread + ?, ' . $this->svUserMaxAlertCount . ')
                WHERE user_id = ?
            ', [$viewRowsAffected, $readRowsAffected, $userId]
            );

            $row = $db->fetchRow('select alerts_unviewed, alerts_unread from xf_user where user_id = ?', $userId);
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

        if ($maxXFVersion && \XF::$versionId > $maxXFVersion)
        {
            return;
        }

        if (empty($contentIds))
        {
            return;
        }

        $user = $user ?: \XF::visitor();
        $userId = $user->user_id;
        if (!$userId || !$user->alerts_unread)
        {
            return;
        }

        $viewDate = $viewDate ?: \XF::$time;

        $db = $this->db();

        $actionFilter = $actions ? ' AND action in (' . $db->quote($actions) . ') ' : '';
        $autoMarkReadFilter = $respectAutoMarkRead ? ' AND auto_read = 1 ' : '';

        // Do a select first to reduce the amount of rows that can be touched for the update.
        // This hopefully reduces contention as must of the time it should just be a select, without any updates
        $alertIds = $db->fetchAllColumn(
            '
            SELECT alert_id
            FROM xf_user_alert
            WHERE alerted_user_id = ?
            AND (read_date = 0 OR view_date = 0) ' . $autoMarkReadFilter. '
            AND event_date < ?
            AND content_type IN (' . $db->quote($contentType) . ')
            AND content_id IN (' . $db->quote($contentIds) . ")
            {$actionFilter}
        ", [$userId, $viewDate]
        );

        if (empty($alertIds))
        {
            return;
        }

        $this->markAlertIdsAsReadAndViewed($user, $alertIds, $viewDate);
    }


    /**
     * @param UserAlertEntity $alert
     * @param int|null $readDate
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
     */
    public function updateUnviewedCountForUser(User $user)
    {
        $userId = $user->user_id;
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

        $db->fetchOne('select user_id from xf_user where user_id = ? for update', [$userId]);

        $count = min($this->svUserMaxAlertCount, (int)$db->fetchOne('
            SELECT COUNT(alert_id) 
            FROM xf_user_alert
            WHERE alerted_user_id = ? AND view_date = 0 AND summerize_id IS NULL
        ', [$userId]));

        $statement = $db->query('
            UPDATE xf_user
            SET alerts_unviewed = ?
            WHERE alerts_unviewed != ? AND user_id = ? 
        ', [$count, $count, $userId]);

        if (!$inTransaction)
        {
            $db->commit();
        }

        if (!$statement->rowsAffected())
        {
            return false;
        }

        // this doesn't need to be in a transaction as it is an advisory read
        $count = $db->fetchOne('
            SELECT alerts_unviewed 
            FROM xf_user 
            WHERE user_id = ?
        ', $userId);
        $user->setAsSaved('alerts_unviewed', $count);

        return $statement->rowsAffected() > 0;
    }

    /**
     * @param User $user
     * @return bool
     */
    public function updateUnreadCountForUser(User $user)
    {
        $userId = $user->user_id;
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

        $db->fetchOne('select user_id from xf_user where user_id = ? for update', [$userId]);

        $count = min($this->svUserMaxAlertCount, (int)$db->fetchOne('
            SELECT COUNT(alert_id) 
            FROM xf_user_alert
            WHERE alerted_user_id = ? AND read_date = 0 AND summerize_id IS NULL
        ', [$userId]));

        $statement = $db->query('
            UPDATE xf_user
            SET alerts_unread = ?
            WHERE alerts_unread != ? AND user_id = ? 
        ', [$count, $count, $userId]);

        if (!$inTransaction)
        {
            $db->commit();
        }

        if (!$statement->rowsAffected())
        {
            return false;
        }

        // this doesn't need to be in a transaction as it is an advisory read
        $count = $db->fetchOne('
            SELECT alerts_unread 
            FROM xf_user 
            WHERE user_id = ?
        ', $userId);
        $user->setAsSaved('alerts_unread', $count);

        return true;
    }
}
