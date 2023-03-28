<?php

namespace SV\AlertImprovements\XF\Repository;

use SV\AlertImprovements\Globals;
use SV\AlertImprovements\ISummarizeAlert;
use SV\AlertImprovements\XF\Finder\UserAlert as ExtendedUserAlertFinder;
use XF\Db\AbstractAdapter;
use XF\Db\DeadlockException;
use SV\AlertImprovements\XF\Entity\UserAlert as ExtendedUserAlertEntity;
use SV\AlertImprovements\XF\Entity\User as ExtendedUserEntity;
use XF\Entity\User;
use XF\Entity\UserAlert as UserAlertEntity;
use XF\Mvc\Entity\AbstractCollection;
use XF\Mvc\Entity\ArrayCollection;
use XF\Mvc\Entity\Finder;

use function array_column;
use function array_keys;
use function array_slice, count, is_array;
use function max;
use function min;
use function preg_match;
use function uasort;

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

    protected function getIgnoreAlertCutOffs(): array
    {
        $viewedAlertExpiryDays = (int)($this->options()->alertExpiryDays ?? 4);
        $viewedCutOff = \XF::$time - $viewedAlertExpiryDays * 86400;

        $unviewedAlertExpiryDays = (int)(\XF::options()->svUnviewedAlertExpiryDays ?? 30);
        $unviewedCutOff = \XF::$time - $unviewedAlertExpiryDays * 86400;

        return [$viewedCutOff, $unviewedCutOff];
    }

    public function summarizeAlertsForUser(User $user, int $summaryAlertViewDate)
    {
        $userId = (int)$user->user_id;

        // reaction summary alerts really can't be merged, so wipe all summary alerts, and then try again
        $this->db()->executeTransaction(function (AbstractAdapter $db) use ($userId) {

            [$viewedCutOff, $unviewedCutOff] = $this->getIgnoreAlertCutOffs();

            $db->query("
                DELETE FROM xf_user_alert
                WHERE alerted_user_id = ? AND summerize_id IS NULL AND `action` LIKE '%_summary' AND (view_date >= ? OR (view_date = 0 and event_date >= ?))
            ", [$userId, $viewedCutOff, $unviewedCutOff]);

            $db->query('
                UPDATE xf_user_alert
                SET summerize_id = NULL
                WHERE alerted_user_id = ? AND summerize_id IS NOT NULL AND (view_date >= ? OR (view_date = 0 and event_date >= ?))
            ', [$userId, $viewedCutOff, $unviewedCutOff]);
        }, AbstractAdapter::ALLOW_DEADLOCK_RERUN);

        // do summarization outside the above transaction
        $this->summarizeAlertsForUserInternal($userId,  true, $summaryAlertViewDate);

        // update alert counters last and not in a large transaction
        $hasChange1 = $this->updateUnreadCountForUserId($userId);
        $hasChange2 = $this->updateUnviewedCountForUserId($userId);
        if ($hasChange1 || $hasChange2)
        {
            $this->refreshUserAlertCounters($user);
        }
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
        /** @var ExtendedUserAlertFinder $finder */
        $finder = $this->finder('XF:UserAlert')
                       ->where(['alert_id', $alertId])
                       ->whereAddOnActive([
                           'column' => 'depends_on_addon_id'
                       ]);
        if ($userId !== 0)
        {
            $finder->where(['alerted_user_id', $userId]);
        }
        else
        {
            $finder->whereImpossible();
        }

        $skipExpiredAlerts = Globals::$skipExpiredAlerts ?? true;
        if ($skipExpiredAlerts)
        {
            [$viewedCutOff, $unviewedCutOff] = $this->getIgnoreAlertCutOffs();
            $finder->indexHint('use', 'alertedUserId_eventDate');
            $finder->whereOr([
                ['view_date', '>=', $viewedCutOff],
            ], [
                ['view_date', '=', 0],
                ['event_date', '>=', $unviewedCutOff],
            ]);
        }

        $finder->markUnviewableAsUnread();

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
        $finder->markUnviewableAsUnread();
        $finder->undoUserJoin();

        $showUnreadOnly = Globals::$showUnreadOnly ?? false;
        if ($showUnreadOnly)
        {
            $finder->showUnreadOnly();
        }

        $skipExpiredAlerts = Globals::$skipExpiredAlerts ?? true;
        if ($skipExpiredAlerts)
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

        if (!(Globals::$forSummarizedAlertView ?? false))
        {
            $finder->where(['summerize_id', null]);
            return $finder;
        }

        $doAlertPopupRewrite = Globals::$doAlertPopupRewrite ?? false;
        $skipSummarize = (Globals::$skipSummarize ?? false) || !$this->canSummarizeAlerts();

        if ($skipSummarize && !$doAlertPopupRewrite)
        {
            return $finder;
        }

        // invoked when alert pop-up
        return $finder->shimSource(function ($limit, $offset) use ($doAlertPopupRewrite, $skipSummarize, $showUnreadOnly, $userId, $finder, $cutOff) {
            if ($offset !== 0)
            {
                return null;
            }
            if ($limit === 0)
            {
                return [];
            }

            if (!$skipSummarize)
            {
                // summarize & do not mark as read, this will be done at a later step and allow the just-read logic to work
                $unviewedAlerts = $this->summarizeAlertsForUserInternal($userId,  false, 0);
                // no alerts where summarized
                if ($unviewedAlerts === null)
                {
                    if ($doAlertPopupRewrite)
                    {
                        // in alert pop-up, ensure unread alerts are preferred over read alerts
                        return $finder->forceUnreadFirst()
                                      ->fetch($limit);
                    }

                    return null;
                }
                $unviewedAlerts = array_slice($unviewedAlerts, 0, $limit, true);
                $unviewedAlerts = $finder->materializeAlerts($unviewedAlerts);

                // summarization only applied to unread alerts, as such there may be read alerts which need fetching
                $viewedAlerts = $finder->where('view_date', '>', 0)
                                 ->fetch($limit)
                                 ->toArray();

                // need to preserve keys, so don't use array_merge
                $alerts = $unviewedAlerts + $viewedAlerts;
                $alerts = array_slice($alerts, 0, $limit, true);

                // in alert pop-up, ensure unread alerts are preferred over read alerts
                if (!$doAlertPopupRewrite)
                {
                    uasort($alerts,
                        function ($a, $b) {
                            if ($a['event_date'] === $b['event_date'])
                            {
                                return ($a['alert_id'] < $b['alert_id']) ? 1 : -1;
                            }

                            return ($a['event_date'] < $b['event_date']) ? 1 : -1;
                        }
                    );
                }

                return $alerts;
            }
            else if ($doAlertPopupRewrite)
            {
                // in alert pop-up, ensure unread alerts are preferred over read alerts
                return $finder->forceUnreadFirst()
                              ->fetch($limit);
            }

            return null;
        });
    }

    /**
     * @param int  $userId
     * @param bool $ignoreReadState
     * @param int  $summaryAlertViewDate
     * @return array|null
     * @throws \Exception
     */
    protected function summarizeAlertsForUserInternal(int $userId, bool $ignoreReadState, int $summaryAlertViewDate): ?array
    {
        if ($userId !== \XF::visitor()->user_id)
        {
            /** @var User $user */
            $user = $this->finder('XF:User')
                         ->where('user_id', $userId)
                         ->fetchOne();

            return \XF::asVisitor(
                $user,
                function () use ($ignoreReadState, $summaryAlertViewDate) {
                    return $this->summarizeAlerts($ignoreReadState, $summaryAlertViewDate);
                }
            );
        }

        return $this->summarizeAlerts($ignoreReadState, $summaryAlertViewDate);
    }

    public function insertUnsummarizedAlerts(ExtendedUserAlertEntity $summaryAlert)
    {
        /** @var ExtendedUserEntity $user */
        $user = $summaryAlert->Receiver;
        if (!$user || !$summaryAlert->is_summary)
        {
            return;
        }

        $this->db()->executeTransaction(function (AbstractAdapter $db) use ($user, $summaryAlert) {
            $summaryId = $summaryAlert->alert_id;
            $userId = $user->user_id;
            $db->fetchOne('SELECT user_id FROM xf_user WHERE user_id = ? FOR UPDATE', $userId);
            $summaryAlert->delete(true, false);

            // Make alerts visible
            $unreadIncrement = $db->query('
                UPDATE IGNORE xf_user_alert
                SET read_date = 0
                WHERE alerted_user_id = ? AND summerize_id = ? AND read_date <> 0
            ', [$userId, $summaryId])->rowsAffected();

            $unviewedIncrement = $db->query('
                UPDATE IGNORE xf_user_alert
                SET view_date = 0
                WHERE alerted_user_id = ? AND summerize_id = ? AND view_date <> 0
            ', [$userId, $summaryId])->rowsAffected();

            $db->query('
                UPDATE IGNORE xf_user_alert
                SET summerize_id = NULL
                WHERE alerted_user_id = ? AND summerize_id = ?
            ', [$userId, $summaryId]);

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

        $alertsSummarize = \XF::options()->svAlertsSummarize ?? false;
        if (!$alertsSummarize)
        {
            return false;
        }

        /** @var ExtendedUserEntity $visitor */
        $visitor = \XF::visitor();
        $summarizeThreshold = (int)max(2, $visitor->Option->sv_alerts_summarize_threshold);

        return ($visitor->alerts_unviewed >= $summarizeThreshold) || ($visitor->alerts_unread >= $summarizeThreshold);
    }

    /**
     * @param bool $ignoreReadState
     * @param int  $summaryAlertViewDate
     * @return array
     */
    protected function getFinderForSummarizeAlerts(int $userId): ExtendedUserAlertFinder
    {
        /** @noinspection PhpIncompatibleReturnTypeInspection */
        return $this->finder('XF:UserAlert')
                    ->where('alerted_user_id', $userId)
                    ->whereAddOnActive([
                        'column' => 'depends_on_addon_id'
                    ])
                    ->order('event_date', 'desc');
    }

    public function summarizeAlerts(bool $ignoreReadState, int $summaryAlertViewDate): array
    {
        // TODO : finish summarizing alerts
        $xfOptions = \XF::options();
        /** @var ExtendedUserEntity $visitor */
        $visitor = \XF::visitor();
        $userId = (int)$visitor->user_id;
        $option = $visitor->Option;
        $summarizeThreshold = $option->sv_alerts_summarize_threshold;

        $finder = $this->getFinderForSummarizeAlerts($userId);
        if (!$ignoreReadState)
        {
            $finder->showUnreadOnly();
        }
        /** @noinspection PhpRedundantOptionalArgumentInspection */
        $finder->where('summerize_id', null);

        $skipExpiredAlerts = Globals::$skipExpiredAlerts ?? true;
        if ($skipExpiredAlerts)
        {
            [$viewedCutOff, $unviewedCutOff] = $this->getIgnoreAlertCutOffs();
            $finder->indexHint('use', 'alertedUserId_eventDate');
            if ($ignoreReadState)
            {
                $finder->whereOr([
                    ['view_date', '>=', $viewedCutOff],
                ], [
                    ['view_date', '=', 0],
                    ['event_date', '>=', $unviewedCutOff],
                ]);
            }
            else
            {
                $finder->where('event_date', '>=', $unviewedCutOff);
            }
        }

        $svAlertsSummerizeLimit = (int)($xfOptions->svAlertsSummerizeLimit ?? 0);
        if ($svAlertsSummerizeLimit > 0)
        {
            $finder->limit($svAlertsSummerizeLimit);
        }

        $alerts = $finder->fetchRaw();

        $outputAlerts = [];

        // build the list of handlers at once, and exclude based
        $handlers = $this->getAlertHandlersForConsolidation();
        // nothing to be done
        $userHandler = empty($handlers['user']) ? null : $handlers['user'];
        if (empty($handlers) || ($userHandler && count($handlers) === 1))
        {
            return $alerts;
        }

        // collect alerts into groupings by content/id
        $groupedContentAlerts = [];
        $groupedUserAlerts = [];
        $groupedAlerts = false;
        foreach ($alerts as $id => $item)
        {
            if ((!$ignoreReadState && $item['view_date']) ||
                empty($handlers[$item['content_type']]) ||
                preg_match('/^.*_summary$/', $item['action']))
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
        foreach ($groupedContentAlerts as $contentType => &$contentIds)
        {
            $handler = $handlers[$contentType];
            foreach ($contentIds as $contentId => $alertGrouping)
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
            foreach ($groupedUserAlerts as $senderUserId => &$perUserAlerts)
            {
                if (!$summarizeThreshold || $perUserAlerts['c'] < $summarizeThreshold)
                {
                    unset($groupedUserAlerts[$senderUserId]);
                    continue;
                }

                $userAlertGrouping = [];
                foreach ($perUserAlerts['d'] as $contentType => $contentIds)
                {
                    foreach ($contentIds as $contentId => $alertGrouping)
                    {
                        foreach ($alertGrouping as $id => $alert)
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
                    foreach ($userAlertGrouping as $id => $alert)
                    {
                        unset($groupedContentAlerts[$alert['content_type_map']][$alert['content_id_map']][$id]);
                    }
                    $groupedAlerts = true;
                }
            }
        }

        // output ungrouped alerts
        unset($contentIds);
        foreach ($groupedContentAlerts as $contentType => $contentIds)
        {
            foreach ($contentIds as $contentId => $alertGrouping)
            {
                foreach ($alertGrouping as $alertId => $alert)
                {
                    $outputAlerts[$alertId] = $alert;
                }
            }
        }

        // update alert totals
        if ($groupedAlerts)
        {
            $hasChange1 = $this->updateUnreadCountForUserId($userId);
            $hasChange2 = $this->updateUnviewedCountForUserId($userId);
            if ($hasChange1 || $hasChange2)
            {
                $this->refreshUserAlertCounters($visitor);
            }
        }

        uasort(
            $outputAlerts,
            function ($a, $b) {
                if ($a['event_date'] === $b['event_date'])
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
     * @noinspection PhpDocMissingThrowsInspection
     */
    protected function insertSummaryAlert(ISummarizeAlert $handler, int $summarizeThreshold, string $contentType, int $contentId, array $alertGrouping, int &$grouped, array &$outputAlerts, string $groupingStyle, int $senderUserId, int $summaryAlertViewDate): bool
    {
        $grouped = 0;
        if (!$summarizeThreshold || count($alertGrouping) < $summarizeThreshold)
        {
            return false;
        }
        $lastAlert = \reset($alertGrouping);

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

        if ($lastAlert['action'] === 'reaction')
        {
            foreach ($alertGrouping as $alert)
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
                        foreach ($extraData as $extraDataKey => $extraDataValue)
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

        // ensure reactions are sorted
        if (isset($summaryAlert['extra_data']['reaction_id']))
        {
            $reactionCounts = new ArrayCollection($summaryAlert['extra_data']['reaction_id']);

            $addOns = \XF::app()->container('addon.cache');
            if (isset($addOns['SV/ContentRatings']))
            {
                /** @var \SV\ContentRatings\XF\Repository\Reaction $reactionRepo */
                $reactionRepo = $this->app()->repository('XF:Reaction');
                $reactions = $reactionRepo->getReactionsAsEntities();
                $reactionIds = $reactions->keys();
            }
            else
            {
                $reactions = $this->app()->get('reactions');
                $reactionIds = ($reactions instanceof AbstractCollection)
                    ? $reactions->keys()
                    : array_keys($reactions);
            }
            $reactionCounts = $reactionCounts->sortByList($reactionIds);

            $summaryAlert['extra_data']['reaction_id'] = $reactionCounts->toArray();
        }

        $summaryAlert = $handler->summarizeAlerts($summaryAlert, $alertGrouping, $groupingStyle);
        if (empty($summaryAlert))
        {
            return false;
        }

        $summerizeId = $rowsAffected = null;
        $db = $this->db();
        $batchIds = array_column($alertGrouping, 'alert_id');

        // depending on context; insertSummaryAlert may be called inside a transaction or not so we want to re-run deadlocks immediately if there is no transaction otherwise allow the caller to run
        $updateAlerts = function () use ($db, $batchIds, $summaryAlert, &$alert, &$rowsAffected, &$summerizeId) {
            // database update, saving this ensure xf_user/xf_user_alert table lock ordering is consistent
            /** @var ExtendedUserAlertEntity $alert */
            $alert = $this->em->create('XF:UserAlert');
            $alert->bulkSet($summaryAlert);
            $alert->save(true, false);
            // we need to treat this as unread for the current request so it can display the way we want
            $alert->setOption('force_unread_in_ui', true);
            $summerizeId = $alert->alert_id;

            // hide the non-summary alerts
            $stmt = $db->query('
                UPDATE xf_user_alert
                SET summerize_id = ?, view_date = if(view_date = 0, ?, view_date), read_date = if(read_date = 0, ?, read_date)
                WHERE alert_id IN (' . $db->quote($batchIds) . ')
            ', [$summerizeId, \XF::$time, \XF::$time]);
            $rowsAffected = $stmt->rowsAffected();

            return $stmt->rowsAffected();
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
    public function getAlertHandlersForConsolidation(): array
    {
        $optOuts = \XF::visitor()->Option->alert_optout;
        $handlers = $this->getAlertHandlers();
        unset($handlers['bookmark_post_alt']);
        foreach ($handlers as $key => $handler)
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
    public function markUserAlertsViewed(User $user, $viewDate = null)
    {
        $this->markUserAlertsRead($user, $viewDate);
    }

    public function markUserAlertViewed(UserAlertEntity $alert, $viewDate = null)
    {
        $this->markUserAlertRead($alert, $viewDate);
    }

    /**
     * @param User|ExtendedUserEntity $user
     * @param null|int                $readDate
     * @noinspection PhpDocMissingThrowsInspection
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
            /** @noinspection PhpUnusedLocalVariableInspection */
            [$viewedCutOff, $unviewedCutOff] = $this->getIgnoreAlertCutOffs();
            // table lock ordering required is [xf_user, xf_user_alert] to avoid deadlocks
            // update both view_date/read_date together to ensure they stay consistent
            $db->query('UPDATE xf_user SET alerts_unviewed = 0, alerts_unread = 0 WHERE user_id = ?', [$userId]);
            $db->query('UPDATE xf_user_alert 
                SET view_date = ?, read_date = ? 
                WHERE alerted_user_id = ? 
                AND (read_date = 0 or view_date = 0) and event_date >= ?
            ', [$readDate, $readDate, $userId, $unviewedCutOff]);
        }, AbstractAdapter::ALLOW_DEADLOCK_RERUN);

        $user->setAsSaved('alerts_unviewed', 0);
        $user->setAsSaved('alerts_unread', 0);
    }

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

        [$viewedCutOff, $unviewedCutOff] = $this->getIgnoreAlertCutOffs();

        $ids = $db->quote($alertIds);
        /** @noinspection SqlWithoutWhere */
        $stmt = $db->query('
                UPDATE IGNORE xf_user_alert
                SET view_date = 0 ' . $disableAutoReadSql . '
                WHERE alerted_user_id = ? AND alert_id IN (' . $ids . ') AND (view_date >= ? OR (view_date = 0 and event_date >= ?))
            ', [$userId, $viewedCutOff, $unviewedCutOff]
        );
        $viewRowsAffected = $stmt->rowsAffected();

        /** @noinspection SqlWithoutWhere */
        $stmt = $db->query('
                UPDATE IGNORE xf_user_alert
                SET read_date = 0 ' . $disableAutoReadSql . '
                WHERE alerted_user_id = ? AND alert_id IN (' . $ids . ') AND (view_date >= ? OR (view_date = 0 and event_date >= ?))
            ', [$userId, $viewedCutOff, $unviewedCutOff]
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

            $alerts_unviewed = min($this->svUserMaxAlertCount, $user->alerts_unviewed + $viewRowsAffected);
            $alerts_unread = min($this->svUserMaxAlertCount, $user->alerts_unread + $readRowsAffected);
        }
        catch (DeadlockException $e)
        {
            $db->query('
                UPDATE xf_user
                SET alerts_unviewed = LEAST(alerts_unviewed + ?, ' . $this->svUserMaxAlertCount . '),
                    alerts_unread = LEAST(alerts_unread + ?, ' . $this->svUserMaxAlertCount . ')
                WHERE user_id = ?
            ', [$viewRowsAffected, $readRowsAffected, $userId]
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

        [$viewedCutOff, $unviewedCutOff] = $this->getIgnoreAlertCutOffs();
        // Do a select first to reduce the amount of rows that can be touched for the update.
        // This hopefully reduces contention as must of the time it should just be a select, without any updates
        $alertIds = $db->fetchAllColumn(
            '
            SELECT alert_id
            FROM xf_user_alert
            WHERE alerted_user_id = ?
            AND (view_date = 0) ' . $autoMarkReadFilter . '
            AND event_date < ?
            AND content_type IN (' . $db->quote($contentType) . ')
            AND content_id IN (' . $db->quote($contentIds) . ")
            AND (view_date >= ? OR (view_date = 0 and event_date >= ?))
            {$actionFilter}
        ", [$userId, $viewDate, $viewedCutOff, $unviewedCutOff]
        ); // do not bother selecting `read_date = 0 OR`

        if (count($alertIds) === 0)
        {
            return;
        }

        $this->markAlertIdsAsReadAndViewed($user, $alertIds, $viewDate);
    }


    /**
     * @param UserAlertEntity|ExtendedUserAlertEntity $alert
     * @param int|null                                $readDate
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

        /** @noinspection PhpUnusedLocalVariableInspection */
        [$viewedCutOff, $unviewedCutOff] = $this->getIgnoreAlertCutOffs();

        $count = min($this->svUserMaxAlertCount, (int)$db->fetchOne('
            SELECT COUNT(alert_id) 
            FROM xf_user_alert
            WHERE alerted_user_id = ? AND view_date = 0 AND summerize_id IS NULL AND event_date >= ?
        ', [$userId, $unviewedCutOff]));

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

        [$viewedCutOff, $unviewedCutOff] = $this->getIgnoreAlertCutOffs();

        $count = min($this->svUserMaxAlertCount, (int)$db->fetchOne('
            SELECT COUNT(alert_id) 
            FROM xf_user_alert
            WHERE alerted_user_id = ? AND read_date = 0 AND summerize_id IS NULL AND (view_date >= ? OR (view_date = 0 and event_date >= ?))
        ', [$userId, $viewedCutOff, $unviewedCutOff]));

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
}
