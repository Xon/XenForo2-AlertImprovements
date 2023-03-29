<?php

namespace SV\AlertImprovements\Repository;

use SV\AlertImprovements\Globals;
use SV\AlertImprovements\ISummarizeAlert;
use SV\AlertImprovements\XF\Entity\User as ExtendedUserEntity;
use SV\AlertImprovements\XF\Entity\UserAlert as ExtendedUserAlertEntity;
use SV\AlertImprovements\XF\Finder\UserAlert as ExtendedUserAlertFinder;
use SV\AlertImprovements\XF\Repository\UserAlert;
use XF\Db\AbstractAdapter;
use XF\Mvc\Entity\AbstractCollection;
use XF\Mvc\Entity\ArrayCollection;
use XF\Mvc\Entity\Repository;
use function array_column;
use function array_keys;
use function count;
use function is_array;
use function max;
use function preg_match;
use function uasort;

class AlertSummarization extends Repository
{
    public function canSummarizeAlerts(): bool
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

    public function resummarizeAlertsForUser(ExtendedUserEntity $user, int $summaryAlertViewDate)
    {
        $userId = (int)$user->user_id;

        // reaction summary alerts really can't be merged, so wipe all summary alerts, and then try again
        $this->db()->executeTransaction(function (AbstractAdapter $db) use ($userId) {

            [$viewedCutOff, $unviewedCutOff] = $this->getAlertRepo()->getIgnoreAlertCutOffs();

            $db->query("
                DELETE alert FROM xf_user_alert as alert use index (alertedUserId_eventDate)
                WHERE alerted_user_id = ? AND summerize_id IS NULL AND `action` LIKE '%_summary' AND (view_date >= ? OR (view_date = 0 and event_date >= ?))
            ", [$userId, $viewedCutOff, $unviewedCutOff]);

            $db->query('
                UPDATE xf_user_alert use index (alertedUserId_eventDate)
                SET summerize_id = NULL
                WHERE alerted_user_id = ? AND summerize_id IS NOT NULL AND (view_date >= ? OR (view_date = 0 and event_date >= ?))
            ', [$userId, $viewedCutOff, $unviewedCutOff]);
        }, AbstractAdapter::ALLOW_DEADLOCK_RERUN);

        // do summarization outside the above transaction
        $this->summarizeAlertsForUser($user,  true, $summaryAlertViewDate);

        // update alert counters last and not in a large transaction
        $hasChange1 = $this->getAlertRepo()->updateUnreadCountForUserId($userId);
        $hasChange2 = $this->getAlertRepo()->updateUnviewedCountForUserId($userId);
        if ($hasChange1 || $hasChange2)
        {
            $this->getAlertRepo()->refreshUserAlertCounters($user);
        }
    }

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

    public function summarizeAlertsForUser(ExtendedUserEntity $user, bool $ignoreReadState, int $summaryAlertViewDate): ?array
    {
        // TODO : finish summarizing alerts
        $xfOptions = \XF::options();
        $userId = (int)$user->user_id;
        $option = $user->Option;
        assert($option !== null);
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
            [$viewedCutOff, $unviewedCutOff] = $this->getAlertRepo()->getIgnoreAlertCutOffs();
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
                foreach ($perUserAlerts['d'] as $contentType => &$contentIds)
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
            $hasChange1 = $this->getAlertRepo()->updateUnreadCountForUserId($userId);
            $hasChange2 = $this->getAlertRepo()->updateUnviewedCountForUserId($userId);
            if ($hasChange1 || $hasChange2)
            {
                $this->getAlertRepo()->refreshUserAlertCounters($user);
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
            $svUserMaxAlertCount = $this->getAlertRepo()->getSvUserMaxAlertCount();
            $db->query('
                UPDATE xf_user
                SET alerts_unread = LEAST(alerts_unread + ?, ?),
                    alerts_unviewed = LEAST(alerts_unviewed + ?, ?)
                WHERE user_id = ?
            ', [$unreadIncrement, $svUserMaxAlertCount, $unviewedIncrement, $svUserMaxAlertCount, $userId]);

            $user->setAsSaved('alerts_unread', $user->alerts_unread + $unreadIncrement);
            $user->setAsSaved('alerts_unread', $user->alerts_unviewed + $unviewedIncrement);
        });
    }

    /**
     * @return \XF\Alert\AbstractHandler[]|ISummarizeAlert[]
     */
    public function getAlertHandlersForConsolidation(): array
    {
        $optOuts = \XF::visitor()->Option->alert_optout;
        $handlers = $this->getAlertRepo()->getAlertHandlers();
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

    protected function getAlertRepo(): UserAlert
    {
        /** @noinspection PhpIncompatibleReturnTypeInspection */
        return $this->repository('XF:UserAlert');
    }
}