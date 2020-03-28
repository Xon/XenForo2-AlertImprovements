<?php

namespace SV\AlertImprovements\XF\Repository;

use SV\AlertImprovements\Globals;
use SV\AlertImprovements\ISummarizeAlert;
use XF\Db\AbstractAdapter;
use XF\Db\DeadlockException;
use SV\AlertImprovements\XF\Entity\UserAlert as Alerts;
use XF\Entity\User;
use XF\Mvc\Entity\Finder;

/**
 * Class UserAlert
 *
 * @package SV\AlertImprovements\XF\Repository
 */
class UserAlert extends XFCP_UserAlert
{
    /**
     * @param int $userId
     */
    public function summarizeAlertsForUser($userId)
    {
        // post rating summary alerts really can't me merged, so wipe all summary alerts, and then try again
        $this->db()->executeTransaction(function (AbstractAdapter $db) use ($userId) {

            $db->query("
                DELETE FROM xf_user_alert
                WHERE alerted_user_id = ? AND summerize_id IS NULL AND `action` LIKE '%_summary'
            ", $userId);

            $db->query('
                UPDATE xf_user_alert
                SET summerize_id = NULL
                WHERE alerted_user_id = ? AND summerize_id IS NOT NULL
            ', $userId);

            $db->query('
                UPDATE xf_user
                SET alerts_unread = (SELECT count(*) FROM xf_user_alert WHERE alerted_user_id = xf_user.user_id AND view_date = 0)
                WHERE user_id = ?
            ', $userId);

            $this->checkSummarizeAlertsForUser($userId, true, true, \XF::$time);
        }, AbstractAdapter::ALLOW_DEADLOCK_RERUN);
    }

    /**
     * @param int      $userId
     * @param null|int $cutOff
     * @return Finder
     */
    public function findAlertsForUser($userId, $cutOff = null)
    {
        /** @var \SV\AlertImprovements\XF\Finder\UserAlert $finder */
        $finder = parent::findAlertsForUser($userId, $cutOff);
        if (!Globals::$skipSummarizeFilter)
        {
            $finder->where(['summerize_id', null]);
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
                $alerts = $this->checkSummarizeAlertsForUser($userId);

                if ($alerts === null)
                {
                    return null;
                }

                $alerts = array_slice($alerts, 0, $limit, true);

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
     * @return array[]|mixed
     * @throws \Exception
     */
    protected function checkSummarizeAlertsForUser($userId, $force = false, $ignoreReadState = false, $summaryAlertViewDate = 0)
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
    protected function checkSummarizeAlerts($force = false, $ignoreReadState = false, $summaryAlertViewDate = 0)
    {
        if ($force || $this->canSummarizeAlerts())
        {
            return $this->summarizeAlerts($ignoreReadState, $summaryAlertViewDate);
        }

        return null;
    }

    /**
     * @param User $user
     * @param int  $summaryId
     */
    public function insertUnsummarizedAlerts($user, $summaryId)
    {
        $this->db()->executeTransaction(function (AbstractAdapter $db) use ($user, $summaryId) {
            $userId = $user->user_id;
            // Delete summary alert
            /** @var Alerts $summaryAlert */
            $summaryAlert = $this->finder('XF:UserAlert')
                                 ->where('alert_id', $summaryId)
                                 ->fetchOne();
            if (!$summaryAlert)
            {
                $db->commit();

                return;
            }
            $summaryAlert->delete(false, false);

            // Make alerts visible
            $stmt = $db->query('
                UPDATE xf_user_alert
                SET summerize_id = NULL
                WHERE alerted_user_id = ? AND summerize_id = ?
            ', [$userId, $summaryId]);

            // Reset unread alerts counter
            $increment = $stmt->rowsAffected();
            $db->query('
                UPDATE xf_user SET
                alerts_unread = alerts_unread + ?
                WHERE user_id = ?
                    AND alerts_unread < 65535
            ', [$increment, $userId]);

            $alerts_unread = $user->alerts_unread + $increment;
            $user->setAsSaved('alerts_unread', $alerts_unread);
        });
    }

    /**
     * @return bool
     */
    protected function canSummarizeAlerts()
    {
        if (Globals::$skipSummarize)
        {
            return false;
        }

        if (empty(\XF::options()->sv_alerts_summerize))
        {
            return false;
        }

        $visitor = \XF::visitor();
        /** @var \SV\AlertImprovements\XF\Entity\UserOption $option */
        $option = $visitor->Option;
        $summarizeThreshold = $option->sv_alerts_summarize_threshold;
        $summarizeUnreadThreshold = $summarizeThreshold * 2 > 25 ? 25 : $summarizeThreshold * 2;

        return $visitor->alerts_unread > $summarizeUnreadThreshold;
    }

    /**
     * Summarizes alerts, does not use entities due to the overhead
     *
     * @param bool $ignoreReadState
     * @param int  $summaryAlertViewDate
     * @return array
     */
    public function summarizeAlerts($ignoreReadState, $summaryAlertViewDate)
    {
        // TODO : finish summarizing alerts
        $visitor = \XF::visitor();
        $userId = $visitor->user_id;
        /** @var \SV\AlertImprovements\XF\Entity\UserOption $option */
        $option = $visitor->Option;
        $summarizeThreshold = $option->sv_alerts_summarize_threshold;

        /** @var \SV\AlertImprovements\XF\Finder\UserAlert $finder */
        $finder = $this->finder('XF:UserAlert')
                       ->where('alerted_user_id', $userId)
                       ->order('event_date', 'desc');

        $finder->where('summerize_id', null);

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
        foreach ($groupedContentAlerts AS $contentType => &$contentIds)
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
            $db = $this->db();

            $db->query('
                UPDATE xf_user
                SET alerts_unread = (SELECT COUNT(*)
                    FROM xf_user_alert
                    WHERE alerted_user_id = ? AND view_date = 0 AND summerize_id IS NULL)
                WHERE user_id = ?
            ', [$visitor->user_id, $visitor->user_id]);

            // this doesn't need to be in a transaction as it is an advisory read
            $alerts_unread = $db->fetchOne(
                '
                    SELECT alerts_unread 
                    FROM xf_user 
                    WHERE user_id = ?
                ', $visitor->user_id
            );
            $visitor->setAsSaved('alerts_unread', $alerts_unread);
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
     * @param ISummarizeAlert $handler
     * @param int             $summarizeThreshold
     * @param string          $contentType
     * @param int             $contentId
     * @param Alerts[]        $alertGrouping
     * @param int             $grouped
     * @param Alerts[]        $outputAlerts
     * @param string          $groupingStyle
     * @param int             $senderUserId
     * @param int             $summaryAlertViewDate
     * @return bool
     */
    protected function insertSummaryAlert($handler, $summarizeThreshold, $contentType, $contentId, array $alertGrouping, &$grouped, array &$outputAlerts, $groupingStyle, $senderUserId, $summaryAlertViewDate)
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

                    if (\XF::$versionId < 2010010)
                    {
                        $extraData = @\unserialize($alert['extra_data']);
                    }
                    else
                    {
                        $extraData = @\json_decode($alert['extra_data'], true);
                    }

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

                    if (\XF::$versionId < 2010010)
                    {
                        $extraData = @\unserialize($alert['extra_data']);
                    }
                    else
                    {
                        $extraData = @\json_decode($alert['extra_data'], true);
                    }

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
            /** @var Alerts $alert */
            $alert = $this->em->create('XF:UserAlert');
            $alert->bulkSet($summaryAlert);
            $alert->save(true, false);
            $summerizeId = $alert->alert_id;

            // hide the non-summary alerts
            $stmt = $db->query(
                '
            UPDATE xf_user_alert
            SET summerize_id = ?, view_date = if(view_date = 0, ?, view_date)
            WHERE alert_id IN (' . $db->quote($batchIds) . ')
        ', [$summerizeId, \XF::$time]
            );
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
     * @param User     $user
     * @param null|int $viewDate
     */
    public function markUserAlertsRead(User $user, $viewDate = null)
    {
        if (Globals::$skipMarkAlertsRead)
        {
            return;
        }

        Globals::$markedAlertsRead = true;
        parent::markUserAlertsRead($user, $viewDate);
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

        $this->markAlertsReadForContentIds($contentType, $contentIds, $onlyActions, 0, $user, $viewDate);
    }

    /**
     * @param string        $contentType
     * @param int[]         $contentIds
     * @param string[]|null $actions
     * @param int           $maxXFVersion
     * @param User|null     $user
     * @param int|null      $viewDate
     * @throws \XF\Db\Exception
     */
    public function markAlertsReadForContentIds($contentType, array $contentIds, array $actions = null, $maxXFVersion = 0, User $user = null, $viewDate = null)
    {
        if ($maxXFVersion && \XF::$versionId > $maxXFVersion)
        {
            return;
        }

        if (empty($contentIds))
        {
            return;
        }

        $visitor = $user ? $user : \XF::visitor();
        $userId = $visitor->user_id;
        if (!$userId || !$visitor->alerts_unread)
        {
            return;
        }

        $viewDate = $viewDate ? $viewDate : \XF::$time;

        $db = $this->db();

        $actionFilter = $actions ? ' AND action in (' . $db->quote($actions) . ') ' : '';

        // Do a select first to reduce the amount of rows that can be touched for the update.
        // This hopefully reduces contention as must of the time it should just be a select, without any updates
        $alertIds = $db->fetchAllColumn(
            '
            SELECT alert_id
            FROM xf_user_alert
            WHERE alerted_user_id = ?
            AND view_date = 0
            AND event_date < ?
            AND content_type IN (' . $db->quote($contentType) . ')
            AND content_id IN (' . $db->quote($contentIds) . ")
            {$actionFilter}
        ", [$userId, \XF::$time]
        );

        if (empty($alertIds))
        {
            return;
        }

        $stmt = $db->query(
            '
            UPDATE IGNORE xf_user_alert
            SET view_date = ?
            WHERE view_date = 0 AND alert_id IN (' . $db->quote($alertIds) . ')
        ', [$viewDate]
        );

        $rowsAffected = $stmt->rowsAffected();

        if ($rowsAffected)
        {
            try
            {
                $db->query(
                    '
                    UPDATE xf_user
                    SET alerts_unread = GREATEST(0, cast(alerts_unread AS SIGNED) - ?)
                    WHERE user_id = ?
                ', [$rowsAffected, $visitor->user_id]
                );
            }
                /** @noinspection PhpRedundantCatchClauseInspection */
            catch (DeadlockException $e)
            {
                if ($db->inTransaction())
                {
                    $db->rollback();

                    // why the hell are we inside a transaction?
                    \XF::logException($e, false, 'Unexpected transaction; ');
                    $rowsAffected = 0;
                    $alerts_unread = $db->fetchOne(
                        '
                            SELECT COUNT(*)
                            FROM xf_user_alert
                            WHERE alerted_user_id = ? AND view_date = 0 AND summerize_id IS NULL',
                        [$visitor->user_id]
                    );
                    $visitor->setAsSaved('alerts_unread', $alerts_unread);
                }
                else
                {
                    $db->query(
                        '
                            UPDATE xf_user
                            SET alerts_unread = GREATEST(0, cast(alerts_unread AS SIGNED) - ?)
                            WHERE user_id = ?
                        ', [$rowsAffected, $visitor->user_id]
                    );
                }
            }

            $alerts_unread = $visitor->alerts_unread - $rowsAffected;
            if ($alerts_unread < 0)
            {
                $alerts_unread = 0;
            }
            $visitor->setAsSaved('alerts_unread', $alerts_unread);
        }
    }

    /**
     * @param User $user
     * @param      $alertId
     * @return \SV\AlertImprovements\XF\Finder\UserAlert|Finder
     */
    public function findAlertForUser(User $user, $alertId)
    {
        return $this->finder('XF:UserAlert')
                    ->where(['alert_id', $alertId])
                    ->where(['alerted_user_id', $user->user_id]);
    }

    /**
     * @param User $user
     * @param int  $alertId
     * @param bool $readStatus
     * @return Alerts
     */
    public function changeAlertStatus(User $user, $alertId, $readStatus)
    {
        $db = $this->db();
        $db->beginTransaction();

        /** @var Alerts $alert */
        $alert = $this->findAlertForUser($user, $alertId)->fetchOne();
        if (empty($alert) || $readStatus === ($alert->view_date !== 0))
        {
            $db->commit();

            return $alert;
        }

        $alert->fastUpdate('view_date', $readStatus ? \XF::$time : 0);

        if ($readStatus)
        {
            $db->query(
                '
                UPDATE xf_user
                SET alerts_unread = GREATEST(0, cast(alerts_unread AS SIGNED) - 1)
                WHERE user_id = ?
            ', $user->user_id
            );
        }
        else
        {
            $db->query(
                '
                UPDATE xf_user
                SET alerts_unread = LEAST(alerts_unread + 1, 65535)
                WHERE user_id = ?
            ', $user->user_id
            );
        }

        $db->commit();

        $user->setAsSaved('alerts_unread', $user->alerts_unread + ($readStatus ? -1 : 1));

        return $alert;
    }
}
