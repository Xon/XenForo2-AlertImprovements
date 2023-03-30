<?php

namespace SV\AlertImprovements\Repository;

use SV\AlertImprovements\Globals;
use SV\AlertImprovements\ISummarizeAlert;
use SV\AlertImprovements\XF\Entity\User as ExtendedUserEntity;
use SV\AlertImprovements\XF\Entity\UserAlert as ExtendedUserAlertEntity;
use SV\AlertImprovements\XF\Finder\UserAlert as ExtendedUserAlertFinder;
use SV\AlertImprovements\XF\Repository\UserAlert;
use XF\Db\AbstractAdapter;
use XF\Db\DeadlockException;
use XF\Db\Exception;
use XF\Mvc\Entity\AbstractCollection;
use XF\Mvc\Entity\ArrayCollection;
use XF\Mvc\Entity\Repository;
use XF\PrintableException;
use function array_chunk;
use function array_column;
use function array_fill_keys;
use function array_key_exists;
use function array_keys;
use function assert;
use function count;
use function is_array;
use function max;
use function str_replace;

class AlertSummarization extends Repository
{
    /** @var int */
    protected $updateAlertBatchSize = 1000;

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

            // mysql really needs help with this one :(
            $db->query('
                UPDATE xf_user_alert AS alert use index ('.($skipExpiredAlertSql === '' ? 'alertedUserId_eventDate' : 'alerted_user_id_summerize_id') .')
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
        $this->summarizeAlertsForUser($user,  true, 0);
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
        $option = $user->Option;
        assert($option !== null);
        $summarizeThreshold = $option->sv_alerts_summarize_threshold;

        $finder = $this->getFinderForSummarizeAlerts($userId)
                       ->forValidContentTypes($validContentTypes);
        if (!$ignoreReadState)
        {
            $finder->showUnreadOnly();
        }
        /** @noinspection PhpRedundantOptionalArgumentInspection */
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
            //$finder->limit($svAlertsSummerizeLimit);
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
        $groupedUserAlerts = [];
        $groupedAlerts = false;
        while ($item = $stmt->fetch())
        {
            $id = $item['alert_id'];
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
            if (!$isSupportedAction)
            {
                continue;
            }

            $contentId = $item['content_id'];
            $contentUserId = $item['user_id'];
            $groupedContentAlerts[$action][$contentType][$contentId][$id] = $item;

            if ($userHandler !== null && isset($supportedContentTypesByHandler[$contentType]))
            {
                if (!isset($groupedUserAlerts[$action][$contentUserId]))
                {
                    $groupedUserAlerts[$action][$contentUserId] = ['c' => 0, 'd' => []];
                }
                $groupedUserAlerts[$action][$contentUserId]['c'] += 1;
                $groupedUserAlerts[$action][$contentUserId]['d'][$contentType][$contentId][$id] = $item;
            }
        }

        // determine what can be summarised by content types. These require explicit support (ie a template)
        foreach ($groupedContentAlerts as $action => &$contentTypes)
        {
            foreach ($contentTypes as $contentType => &$contentIds)
            {
                foreach ($contentIds as $contentId => $alertGrouping)
                {
                    if (count($alertGrouping) < $summarizeThreshold)
                    {
                        continue;
                    }
                    $summaryData = $this->getSummaryAlertData($action, $alertGrouping);
                    if ($summaryData === null)
                    {
                        continue;
                    }

                    if ($this->insertSummaryAlert($contentType, $contentId, $alertGrouping,  0, $summaryAlertViewDate, $summaryData))
                    {
                        unset($contentIds[$contentId]);
                        $groupedAlerts = true;
                    }
                }
            }
        }

        // see if we can group some alert by user (requires deap knowledge of most content types and the template)
        if ($userHandler !== null)
        {
            foreach ($groupedUserAlerts as $action => &$groupedByAction)
            {
                foreach ($groupedByAction as $senderUserId => &$perUserAlerts)
                {
                    if ($perUserAlerts['c'] < $summarizeThreshold)
                    {
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

                    if (count($userAlertGrouping) == 0)
                    {
                        continue;
                    }

                    $summaryData = $this->getSummaryAlertData($action, $userAlertGrouping);
                    if ($summaryData === null)
                    {
                        continue;
                    }

                    if ($this->insertSummaryAlert('user', $userId, $userAlertGrouping, $senderUserId, $summaryAlertViewDate, $summaryData))
                    {
                        foreach ($userAlertGrouping as $id => $alert)
                        {
                            unset($groupedContentAlerts[$alert['content_type_map']][$alert['content_id_map']][$id]);
                        }
                        $groupedAlerts = true;
                    }
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
     * @param array<array> $alertGrouping
     * @param int          $senderUserId
     * @param int          $summaryAlertViewDate
     * @param array        $summaryData
     * @return bool
     * @throws DeadlockException
     */
    protected function insertSummaryAlert(string $contentType, int $contentId, array $alertGrouping, int $senderUserId, int $summaryAlertViewDate, array $summaryData): bool
    {
        $lastAlert = \reset($alertGrouping);
        $userId = $lastAlert['alerted_user_id'];

        // inject a grouped alert with the same content type/id, but with a different action
        $summaryAlert = [
            'depends_on_addon_id' => 'SV/AlertImprovements',
            'alerted_user_id'     => $userId,
            'user_id'             => $senderUserId,
            'username'            => $senderUserId ? $lastAlert['username'] : 'Guest',
            'content_type'        => $contentType,
            'content_id'          => $contentId,
            'action'              => $lastAlert['action'] . '_summary',
            'event_date'          => $lastAlert['event_date'],
            'view_date'           => $summaryAlertViewDate,
            'read_date'           => $summaryAlertViewDate,
            'extra_data'          => $summaryData,
        ];

        // limit the size of the IN clause
        $batchIds = array_keys($alertGrouping);
        $chunks = $this->updateAlertBatchSize < 1 ? [$batchIds] : array_chunk($batchIds, $this->updateAlertBatchSize);

        $visitor = \XF::visitor();
        if ($visitor->user_id !== $userId)
        {
            $visitor = null;
        }
        /** @var ExtendedUserAlertEntity $alert */
        $alert = $this->em->create('XF:UserAlert');
        $alert->setupSummaryAlert($summaryAlert);

        if (\XF::$developmentMode)
        {
            $this->db()->logSimpleOnly(true);
        }

        $this->db()->executeTransaction(function (AbstractAdapter $db) use ($chunks, $alert, $userId, $visitor) {
            $alert->save(true, false);
            $summaryId = $alert->alert_id;

            foreach ($chunks as $chunk)
            {
                // hide the non-summary alerts
                $db->query('
                    UPDATE xf_user_alert
                    SET summerize_id = ?, view_date = if(view_date = 0, ?, view_date), read_date = if(read_date = 0, ?, read_date)
                    WHERE alert_id IN (' . $db->quote($chunk) . ')
                ', [$summaryId, \XF::$time, \XF::$time]);
            }
        }, AbstractAdapter::ALLOW_DEADLOCK_RERUN);

        return true;
    }

    protected function getSummaryAlertData(string $action, array $alertGrouping): ?array
    {
        if ($action !== 'reaction')
        {
            return null;
        }

        $summaryData = [];
        $contentTypes = [];
        $reactionData = [];
        foreach ($alertGrouping as $alert)
        {
            $extraData = @\json_decode($alert['extra_data'], true);
            if (!is_array($extraData))
            {
                continue;
            }
            $reactionId = $extraData['reaction_id'] ?? null;
            if ($reactionId === null)
            {
                continue;
            }

            $contentType = $alert['content_type'];
            if (!array_key_exists($contentType, $contentTypes))
            {
                $contentTypes[$contentType] = 0;
            }
            $contentTypes[$contentType] += 1;

            $reactionId = (int)$reactionId;
            if (!array_key_exists($reactionId, $reactionData))
            {
                $reactionData[$reactionId] = 0;
            }
            $reactionData[$reactionId] += 1;
        }

        if (count($reactionData) !== 0)
        {
            // ensure reactions are sorted
            $reactionCounts = new ArrayCollection($reactionData);

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
            $reactionData = $reactionCounts->toArray();

            $summaryData['reaction_id'] = $reactionData;
        }

        if (count($contentTypes) !== 0)
        {
            $summaryData['ct'] = $contentTypes;
        }

        return $summaryData;
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