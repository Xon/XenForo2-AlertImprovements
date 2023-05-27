<?php

namespace SV\AlertImprovements\Repository;

use SV\AlertImprovements\Globals;
use SV\AlertImprovements\ISummarizeAlert;
use SV\AlertImprovements\XF\Entity\User as ExtendedUserEntity;
use SV\AlertImprovements\XF\Entity\UserAlert as ExtendedUserAlertEntity;
use SV\AlertImprovements\XF\Finder\UserAlert as ExtendedUserAlertFinder;
use SV\AlertImprovements\XF\Repository\UserAlert;
use SV\ContentRatings\XF\Repository\Reaction;
use SV\StandardLib\BypassAccessStatus;
use XF\Alert\AbstractHandler;
use XF\Db\AbstractAdapter;
use XF\Db\DeadlockException;
use XF\Db\Exception;
use XF\Mvc\Entity\AbstractCollection;
use XF\Mvc\Entity\ArrayCollection;
use XF\Mvc\Entity\Repository;
use function array_fill_keys;
use function array_key_exists;
use function array_keys;
use function array_sum;
use function assert;
use function count;
use function is_array;
use function json_decode;
use function max;
use function strpos;

class AlertSummarization extends Repository
{
    /** @var int */
    protected $updateAlertBatchSize = 1000;
    /** @var int */
    protected $minimumSummarizeThreshold = 2;

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
        $summarizeThreshold = $visitor->Option->sv_alerts_summarize_threshold ?? 4;
        if ($summarizeThreshold < $this->minimumSummarizeThreshold)
        {
            return false;
        }

        return ($visitor->alerts_unviewed >= $summarizeThreshold) || ($visitor->alerts_unread >= $summarizeThreshold);
    }

    public function resummarizeAlertsForUser(ExtendedUserEntity $user, int $summaryAlertViewDate)
    {
        $userId = (int)$user->user_id;
        // reaction summary alerts really can't be merged, so wipe all summary alerts, and then try again
        $this->db()->executeTransaction(function (AbstractAdapter $db) use ($userId) {
            // polyfill for a lack of a ... operator in php7.2
            $merge = function (array $a, array $b): array {
                foreach ($b as $i) {
                    $a[] = $i;
                }
                return $a;
            };
            if (Globals::isSkippingExpiredAlerts())
            {
                $skipExpiredAlertSql =  ' AND (alert.view_date >= ? OR (alert.view_date = 0 AND alert.event_date >= ?)) ';
                $args = $this->getAlertRepo()->getIgnoreAlertCutOffs();
            }
            else
            {
                $skipExpiredAlertSql = '';
                $args = [];
            }

            $db->fetchOne('SELECT user_id FROM xf_user WHERE user_id = ? FOR UPDATE', $userId);

            // only compute a delta of read/view state changes, as to avoid touching every alert
            $readCount = (int)$db->fetchOne('
                SELECT COUNT(alert.alert_id)
                FROM xf_user_alert AS alert
                JOIN xf_sv_user_alert_summary AS summaryRecord ON summaryRecord.alert_id = alert.alert_id
                WHERE summaryRecord.alerted_user_id = ? 
                  AND alert.alerted_user_id = ? 
                  AND alert.read_date = 0
                  '. $skipExpiredAlertSql .' 
            ', $merge([$userId, $userId], $args));
            $viewCount = (int)$db->fetchOne('
                SELECT COUNT(alert.alert_id)
                FROM xf_user_alert AS alert
                JOIN xf_sv_user_alert_summary AS summaryRecord ON summaryRecord.alert_id = alert.alert_id
                WHERE summaryRecord.alerted_user_id = ? 
                  AND alert.alerted_user_id = ? 
                  AND alert.view_date = 0
                  '. $skipExpiredAlertSql .' 
            ', $merge([$userId, $userId], $args));
            if ($readCount !== 0 && $viewCount !== 0)
            {
                $db->query('
                    UPDATE xf_user
                    SET alerts_unviewed = GREATEST(0, cast(alerts_unviewed AS SIGNED) - ?),
                        alerts_unread = GREATEST(0, cast(alerts_unread AS SIGNED) - ?)
                    WHERE user_id = ?
                ', [$readCount, $viewCount, $userId]
                );
            }

            // MySQL/MariaDb can just pick really horrible index choices for this, and correctly using index hints is challenging
            $db->query('
                UPDATE xf_user_alert AS alert
                SET alert.summerize_id = NULL           
                WHERE alert.alerted_user_id = ? and alert.summerize_id is not null
                  '. $skipExpiredAlertSql .'
            ', $merge([$userId], $args));

            $db->query('
                DELETE alert, summaryRecord
                FROM xf_user_alert AS alert
                LEFT JOIN xf_sv_user_alert_summary AS summaryRecord ON summaryRecord.alert_id = alert.alert_id
                WHERE summaryRecord.alerted_user_id = ? '. $skipExpiredAlertSql .'
            ', $merge([$userId], $args));
        }, AbstractAdapter::ALLOW_DEADLOCK_RERUN);

        // summarization should not be run inside a transaction
        $this->summarizeAlertsForUser($user,  true, $summaryAlertViewDate);
    }

    protected function getFinderForSummarizeAlerts(int $userId): ExtendedUserAlertFinder
    {
        /** @noinspection PhpIncompatibleReturnTypeInspection */
        return $this->finder('XF:UserAlert')
                    ->where('alerted_user_id', $userId)
                    ->order('event_date', 'desc');
    }

    public function summarizeAlertsForUser(ExtendedUserEntity $user, bool $ignoreReadState, int $summaryAlertViewDate): bool
    {
        $summarizeThreshold = $user->Option->sv_alerts_summarize_threshold ?? 4;
        if ($summarizeThreshold < $this->minimumSummarizeThreshold)
        {
            return false;
        }

        assert(!$this->db()->inTransaction());
        // build the list of handlers at once, and exclude based
        $handlers = $this->getAlertHandlersForConsolidation();
        // nothing to be done
        $userHandler = $handlers['user'] ?? null;
        if (count($handlers) === 0 || ($userHandler !== null && count($handlers) === 1))
        {
            return false;
        }

        $validContentTypes = [];
        $actionsByContent = [];
        foreach ($handlers as $contentType => $handler)
        {
            $actionsByContent[$contentType] = array_fill_keys($handler->getSupportedActionsForSummarization(), true);
            if (count($actionsByContent[$contentType]) !== 0)
            {
                $validContentTypes[] = $contentType;
            }
        }
        if (count($validContentTypes) === 0)
        {
            return false;
        }
        $supportedContentTypesByHandler = [];
        foreach ($handlers as $contentType => $handler)
        {
            $supportedContentTypesByHandler[$contentType] = array_fill_keys($handler->getSupportContentTypesForSummarization(), true);
        }
        if (count($supportedContentTypesByHandler) === 0)
        {
            return false;
        }

        // TODO : finish summarizing alerts
        $xfOptions = \XF::options();
        $userId = (int)$user->user_id;

        $finder = $this->getFinderForSummarizeAlerts($userId)
                       ->forValidContentTypes($validContentTypes);
        if (!$ignoreReadState)
        {
            $finder->showUnreadOnly();
        }
        $finder->where('summerize_id', null);

        if (Globals::isSkippingExpiredAlerts())
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

        $query = $finder->getQuery([
            // avoid fetching joins, and just fetch the limited data required
            'fetchOnly' => [
                'alert_id',
                'view_date',
                'content_type',
                'content_id',
                'alerted_user_id',
                'user_id',
                'action',
                'username',
                'event_date',
                'extra_data'
            ],
        ]);
        $stmt = $this->db()->query($query);

        // collect alerts into groupings by content/id
        $groupedContentAlerts = [];
        $groupedAlerts = false;
        while ($item = $stmt->fetch())
        {
            $alertId = $item['alert_id'];
            if (!$ignoreReadState && $item['view_date'] !== 0)
            {
                continue;
            }
            $contentType = $item['content_type'];
            $handler = $handlers[$contentType] ?? null;
            if ($handler === null)
            {
                continue;
            }
            $action = $item['action'];
            $isSupportedAction = $actionsByContent[$contentType][$action] ?? null;
            if ($isSupportedAction === null)
            {
                continue;
            }

            $data = [
                'alert_id' => $alertId,
                'content_type' => $contentType,
                'extra_data' => $item['extra_data'],
                'user_id' => $item['user_id'],
            ];
            $contentId = $item['content_id'];

            if (!isset($groupedContentAlerts[$contentType][$action][$contentId]))
            {
                $groupedContentAlerts[$contentType][$action][$contentId] = ['c' => 0, 'd' => []];
            }
            $bucket = &$groupedContentAlerts[$contentType][$action][$contentId];
            $bucket['c'] += 1;
            $bucket['i'] = $item;
            if ($contentType === 'user')
            {
                $bucket['d'][$contentType][$contentId][$alertId] = $data;
                unset($bucket);
                continue;
            }
            $bucket['d'][$alertId] = $data;
            unset($bucket);

            if (isset($supportedContentTypesByHandler['user'][$contentType]))
            {
                $contentUserId = $item['user_id'];
                if (!isset($groupedContentAlerts['user'][$action][$contentUserId]))
                {
                    $groupedContentAlerts['user'][$action][$contentUserId] = ['c' => 0, 'd' => []];
                }
                $bucket = &$groupedContentAlerts['user'][$action][$contentUserId];
                $bucket['c'] += 1;
                $bucket['i'] = $item;
                $bucket['d'][$contentType][$contentId][$alertId] = $data;
                unset($bucket);
            }
        }

        $groupedUserAlerts = $groupedContentAlerts['user'] ?? [];
        unset($groupedContentAlerts['user']);
        // determine what can be summarised by content types. These require explicit support (ie a template)
        foreach ($groupedContentAlerts as $contentType => $contentActions)
        {
            foreach ($contentActions as $action => $contentIds)
            {
                foreach ($contentIds as $contentId => $blob)
                {
                    if ($blob['c'] < $summarizeThreshold)
                    {
                        unset($groupedContentAlerts[$contentType][$action][$contentId]);
                        continue;
                    }
                    $summaryData = $this->getSummaryAlertData($action, $blob['d']);
                    if ($summaryData === null)
                    {
                        unset($groupedContentAlerts[$contentType][$action][$contentId]);
                        continue;
                    }

                    if ($this->insertSummaryAlert($contentType, $contentId, $blob['i'], $blob['d'],  0, $summaryAlertViewDate, $summaryData))
                    {
                        $groupedAlerts = true;
                    }
                    else
                    {
                        unset($groupedContentAlerts[$contentType][$action][$contentId]);
                    }
                }
            }
        }

        foreach ($groupedUserAlerts as $action => &$senderIds)
        {
            foreach ($senderIds as $senderUserId => $blob)
            {
                if ($blob['c'] < $summarizeThreshold)
                {
                    continue;
                }

                $userAlertGrouping = [];
                foreach ($blob['d'] as $contentType => $contentIds)
                {
                    foreach ($contentIds as $contentId => $alertIds)
                    {
                        foreach ($alertIds as $alertId => $alert)
                        {
                            if (!isset($groupedContentAlerts[$contentType][$action][$contentId]['d'][$alertId]))
                            {
                                $userAlertGrouping[$alertId] = $alert;
                            }
                        }
                    }
                }
                if (count($userAlertGrouping) === 0)
                {
                    continue;
                }

                $summaryData = $this->getSummaryAlertData($action, $userAlertGrouping);
                if ($summaryData === null)
                {
                    continue;
                }
                $asSystemAlert = $summaryData['asSystemAlert'] ?? false;
                unset($summaryData['asSystemAlert']);
                if ($asSystemAlert)
                {
                    $senderUserId = 0;
                }

                if ($this->insertSummaryAlert('user', $userId, $blob['i'], $userAlertGrouping, $senderUserId, $summaryAlertViewDate, $summaryData))
                {
                    $groupedAlerts = true;
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

        return true;
    }

    /**
     * @param string       $contentType
     * @param int          $contentId
     * @param array        $lastAlert
     * @param array<array> $alertGrouping
     * @param int          $senderUserId
     * @param int          $summaryAlertViewDate
     * @param array        $summaryData
     * @return bool
     * @throws DeadlockException
     * @throws Exception
     */
    protected function insertSummaryAlert(string $contentType, int $contentId, array $lastAlert, array $alertGrouping, int $senderUserId, int $summaryAlertViewDate, array $summaryData): bool
    {
        $userId = $lastAlert['alerted_user_id'];

        // inject a grouped alert with the same content type/id, but with a different action
        $summaryAlert = [
            'depends_on_addon_id' => 'SV/AlertImprovements',
            'alerted_user_id'     => $userId,
            'user_id'             => $senderUserId,
            'username'            => $senderUserId ? $lastAlert['username'] : '',
            'content_type'        => $contentType,
            'content_id'          => $contentId,
            'action'              => $lastAlert['action'] . '_summary',
            'event_date'          => $lastAlert['event_date'],
            'view_date'           => $summaryAlertViewDate,
            'read_date'           => $summaryAlertViewDate,
            'extra_data'          => $summaryData,
        ];

        // limit the size of the IN clause
        //$batchIds = array_keys($alertGrouping);
        //$chunks = $this->updateAlertBatchSize < 1 ? [$batchIds] : array_chunk($batchIds, $this->updateAlertBatchSize);

        $visitor = \XF::visitor();
        if ($visitor->user_id !== $userId)
        {
            $visitor = null;
        }
        /** @var ExtendedUserAlertEntity $alert */
        $alert = $this->em->create('XF:UserAlert');
        $alert->setupSummaryAlert($summaryAlert);

        $db = $this->db();
        $db->query('drop temporary table if exists xf_sv_user_alert_to_summarize');
        $db->query('create temporary table xf_sv_user_alert_to_summarize (
            `alert_id` bigint primary key
        )');
        $ids = array_map('\intval', array_keys($alertGrouping));
        $sql = 'insert into xf_sv_user_alert_to_summarize (alert_id) values ('.implode('),(', $ids). ')';
        $db->query($sql);
        unset($sql);
        // the bulk sql inserted may be very long. rewrite it so _debug=1 doesn't cause pain
        if (\XF::$debugMode || $db->areQueriesLogged())
        {
            $queryLog = $db->getQueryLog();
            // todo replace with array_key_last when php 7.3 is a minimum version
            $key = key(array_slice($queryLog, -1, 1, true));
            $loggedQuery = $queryLog[$key]['query'] ?? null;
            if ($loggedQuery !== null && strpos($loggedQuery, 'xf_sv_user_alert_to_summarize') !== 0)
            {
                $queryLog[$key]['query'] = 'insert into xf_sv_user_alert_to_summarize (alert_id) values (?)';
                $setQueryLog = (new BypassAccessStatus)->setPrivate($db, 'queryLog');
                $setQueryLog($queryLog);
            }
        }

        $db->executeTransaction(function (AbstractAdapter $db) use ($alert, $userId, $visitor) {
            $alert->save(true, false);
            $summaryId = $alert->alert_id;

            // hide the non-summary alerts
            $db->query('
                UPDATE xf_user_alert as alert
                join xf_sv_user_alert_to_summarize as toSummarize on toSummarize.alert_id =  alert.alert_id
                SET alert.summerize_id = ?, 
                    alert.view_date = if(view_date = 0, ?, view_date), 
                    alert.read_date = if(read_date = 0, ?, read_date)
            ', [$summaryId, \XF::$time, \XF::$time]);

            /*
            foreach ($chunks as $chunk)
            {
                // hide the non-summary alerts
                $db->query('
                    UPDATE xf_user_alert
                    SET summerize_id = ?, view_date = if(view_date = 0, ?, view_date), read_date = if(read_date = 0, ?, read_date)
                    WHERE alert_id IN (' . $db->quote($chunk) . ')
                ', [$summaryId, \XF::$time, \XF::$time]);
            }
            */
        }, AbstractAdapter::ALLOW_DEADLOCK_RERUN);

        return true;
    }

    protected function getSummaryAlertData(string $action, array $alertGrouping): ?array
    {
        switch ($action)
        {
            case 'reaction':
                return $this->getSummaryAlertDataForReaction($alertGrouping);
            case 'following':
                return $this->getSummaryAlertDataForUserFollow($alertGrouping);
            case 'quote':
                return $this->getSummaryAlertDataForQuote($alertGrouping);
            default:
                return null;
        }
    }

    protected function countAlertDataForSummary(string $key, bool $fromExtra, array $alertGrouping): array
    {
        $contentTypes = [];
        $countedData = [];
        foreach ($alertGrouping as $alert)
        {
            $extraData = $fromExtra
                ? @json_decode($alert['extra_data'], true)
                : $alert;
            if (!is_array($extraData))
            {
                continue;
            }
            $id = $extraData[$key] ?? null;
            if ($id === null)
            {
                continue;
            }

            $contentType = $alert['content_type'];
            if (!array_key_exists($contentType, $contentTypes))
            {
                $contentTypes[$contentType] = 0;
            }
            $contentTypes[$contentType] += 1;

            $id = (int)$id;
            if (!array_key_exists($id, $countedData))
            {
                $countedData[$id] = 0;
            }
            $countedData[$id] += 1;
        }

        return [$countedData, $contentTypes];
    }

    protected function getSummaryAlertDataForReaction(array $alertGrouping): ?array
    {
        $summaryData = [];
        [$reactionData, $contentTypes] = $this->countAlertDataForSummary('reaction_id', true, $alertGrouping);

        if (count($reactionData) !== 0)
        {
            // ensure reactions are sorted
            $reactionCounts = new ArrayCollection($reactionData);

            $addOns = \XF::app()->container('addon.cache');
            if (isset($addOns['SV/ContentRatings']))
            {
                /** @var Reaction $reactionRepo */
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
            $reactionData = $reactionCounts->toArray();

            $summaryData['reaction_id'] = $reactionData;
        }

        if (count($contentTypes) !== 0)
        {
            $summaryData['ct'] = $contentTypes;
        }

        return count($summaryData) === 0 ? null : $summaryData;
    }

    protected function getSummaryAlertDataForCountable(array $alertGrouping, bool $asSystemAlert): ?array
    {
        [$userIds, $contentTypes] = $this->countAlertDataForSummary('user_id', false, $alertGrouping);
        unset($userIds[0]);

        if (count($userIds) === 0)
        {
            return null;
        }

        return [
            'asSystemAlert' => $asSystemAlert,
            'sum' => array_sum($userIds),
            'total' => count($userIds),
            'u' => $userIds,
            'ct' => $contentTypes,
        ];
    }

    protected function getSummaryAlertDataForUserFollow(array $alertGrouping): ?array
    {
        return $this->getSummaryAlertDataForCountable($alertGrouping, true);
    }

    protected function getSummaryAlertDataForQuote(array $alertGrouping): ?array
    {
        return $this->getSummaryAlertDataForCountable($alertGrouping, false);
    }

    public function insertUnsummarizedAlerts(ExtendedUserAlertEntity $summaryAlert)
    {
        /** @var ExtendedUserEntity $user */
        $user = $summaryAlert->Receiver;
        if ($user === null || !$summaryAlert->is_summary)
        {
            return;
        }

        $this->db()->executeTransaction(function (AbstractAdapter $db) use ($user, $summaryAlert) {
            $summaryId = $summaryAlert->alert_id;
            $userId = $user->user_id;
            $db->fetchOne('SELECT user_id FROM xf_user WHERE user_id = ? FOR UPDATE', $userId);
            $summaryAlert->delete(true, false);

            $row = $db->query('
                SELECT COUNT(read_date <> 0), COUNT(view_date <> 0)
                FROM xf_user_alert USE INDEX (alerted_user_id_summerize_id)
                WHERE alerted_user_id = ? AND summerize_id = ?
                LIMIT 1
            ', [$userId, $summaryId])->fetchRowValues();
            $unreadIncrement = $row[0] ?? 0;
            $unviewedIncrement = $row[1] ?? 0;

            // make alerts visible
            $db->query('
                UPDATE IGNORE xf_user_alert USE INDEX (alerted_user_id_summerize_id)
                SET summerize_id = NULL, read_date = 0, view_date = 0
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
     * @return AbstractHandler[]|ISummarizeAlert[]
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