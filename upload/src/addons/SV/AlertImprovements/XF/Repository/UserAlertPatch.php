<?php
/**
 * @noinspection PhpMissingParentCallCommonInspection
 */

namespace SV\AlertImprovements\XF\Repository;

use SV\AlertImprovements\Job\UnviewedAlertCleanup;
use SV\AlertImprovements\Job\ViewedAlertCleanup;
use SV\AlertImprovements\XF\Entity\UserOption;
use SV\StandardLib\Helper;
use XF\Db\AbstractAdapter;
use XF\Db\AbstractStatement;
use XF\Db\DeadlockException;
use XF\Entity\User as UserEntity;
use XF\Entity\UserOption as UserOptionEntity;
use XF\Finder\User as UserFinder;
use XF\Mvc\Entity\AbstractCollection;
use XF\Entity\UserAlert as UserAlertEntity;
use function array_keys;
use function max;

/**
 * @extends \XF\Repository\UserAlert
 */
class UserAlertPatch extends XFCP_UserAlertPatch
{
    /**
     * @param UserEntity $user
     * @param null|int   $viewDate
     */
    public function markUserAlertsViewed(UserEntity $user, $viewDate = null)
    {
        $this->markUserAlertsRead($user, $viewDate);
    }

    public function markUserAlertViewed(UserAlertEntity $alert, $viewDate = null)
    {
        $this->markUserAlertRead($alert, $viewDate);
    }

    public function pruneViewedAlertsBatch(int $cutOff, float $startTime, float $maxRunTime, int &$batchSize): bool
    {
        if (!$cutOff)
        {
            return false;
        }

        $db = \XF::db();
        try
        {
            do
            {

                /** @var AbstractStatement $statement */
                $statement = $db->executeTransaction(function (AbstractAdapter $db) use ($cutOff, $batchSize) {
                    return $db->query("DELETE FROM xf_user_alert WHERE view_date > 0 AND view_date < ? LIMIT {$batchSize}", $cutOff);
                }, AbstractAdapter::ALLOW_DEADLOCK_RERUN);


                if (\microtime(true) - $startTime >= $maxRunTime)
                {
                    return true;
                }
            }
            while ($statement && $statement->rowsAffected() >= $batchSize);
        }
        catch (DeadlockException $e)
        {
            $db->rollback();
            // reduce batch size, and signal to try again
            $batchSize = max((int)($batchSize / 2), 100);
            return true;
        }

        return false;
    }

    public function pruneUnviewedAlertsBatch(int $cutOff, float $startTime, float $maxRunTime, int &$batchSize): bool
    {
        if (!$cutOff)
        {
            return false;
        }

        $db = \XF::db();
        try
        {
            do
            {
                /** @var AbstractStatement $statement */
                $statement = $db->executeTransaction(function (AbstractAdapter $db) use ($cutOff, $batchSize) {
                    return $db->query("DELETE FROM xf_user_alert WHERE view_date = 0 AND event_date < ? LIMIT {$batchSize}", $cutOff);
                }, AbstractAdapter::ALLOW_DEADLOCK_RERUN);

                if (\microtime(true) - $startTime >= $maxRunTime)
                {
                    return true;
                }
            }
            while ($statement && $statement->rowsAffected() >= $batchSize);
        }
        catch (DeadlockException $e)
        {
            $db->rollback();
            // reduce batch size, and signal to try again
            $batchSize = max((int)($batchSize / 2), 100);
            return true;
        }

        return false;
    }

    public function pruneViewedAlerts($cutOff = null)
    {
        if ($cutOff === null)
        {
            $viewedAlertExpiryDays = \XF::options()->alertExpiryDays ?? 0;
            if ($viewedAlertExpiryDays <= 0)
            {
                return;
            }
            $cutOff = \XF::$time - $viewedAlertExpiryDays * 86400;
        }
        \XF::app()->jobManager()->enqueueLater('sViewedAlertCleanup', \XF::$time + 1, ViewedAlertCleanup::class, [
            'cutOff' => $cutOff,
        ], false);
    }

    public function pruneUnviewedAlerts($cutOff = null)
    {
        if ($cutOff === null)
        {
            $unviewedAlertExpiryDays = \XF::options()->svUnviewedAlertExpiryDays ?? 0;
            if ($unviewedAlertExpiryDays <= 0)
            {
                return;
            }
            $cutOff = \XF::$time - $unviewedAlertExpiryDays * 86400;
        }
        \XF::app()->jobManager()->enqueueLater('svUnviewedAlertCleanup', \XF::$time + 2*60, UnviewedAlertCleanup::class, [
            'cutOff' => $cutOff,
        ], false);
    }

    /**
     * @param AbstractCollection|array $alerts
     * @return void
     * @noinspection PhpMissingParentCallCommonInspection
     */
    public function addContentToAlerts($alerts)
    {
        $app = \XF::app();

        /** @var array<int, UserAlertEntity> $alerts */
        /** @var array<int, array<int,int[]>> $contentMap */
        $contentMap = [];
        $userIds  = [];
        foreach ($alerts AS $alertId => $alert)
        {
            $userId = $alert->user_id;
            if ($userId !== 0 && !Helper::findCached(UserEntity::class, $userId))
            {
                $userIds[$userId] = $userId;
            }

            $contentMap[$alert->content_type][$alert->content_id][] = $alertId;
        }

        // special case user alerts
        $contentIds = $contentMap['user'] ?? null;
        if ($contentIds !== null)
        {
            foreach ($contentIds as $contentId => $alertIds)
            {
                $entity = Helper::findCached(UserEntity::class, $contentId);
                if (!$entity)
                {
                    $userIds[$contentId] = $contentId;
                }
            }
        }

        if (count($userIds) !== 0)
        {
            Helper::finder(UserFinder::class)
               ->whereIds($userIds)
               ->fetch();
        }

        foreach ($contentMap AS $contentType => $contentIds)
        {
            $handler = $this->getAlertHandler($contentType);
            if ($handler === null)
            {
                continue;
            }
            $entityName = $app->getContentTypeEntity($contentType, false);
            if ($entityName === null)
            {
                continue;
            }

            foreach ($contentIds as $contentId => $alertIds)
            {
                $entity = Helper::findCached($entityName, $contentId);
                if (!$entity)
                {
                    continue;
                }
                unset($contentMap[$contentType][$contentId]);
                if (count($contentMap[$contentType]) === 0)
                {
                    /** @noinspection PhpConditionAlreadyCheckedInspection */
                    unset($contentMap[$contentType]);
                }
                foreach ($alertIds AS $alertId)
                {
                    $alerts[$alertId]->setContent($entity);
                }
            }
        }

        foreach ($contentMap AS $contentType => $contentIds)
        {
            $handler = $this->getAlertHandler($contentType);
            if ($handler === null)
            {
                continue;
            }
            $data = $handler->getContent(array_keys($contentIds));
            foreach ($contentIds as $contentId => $alertIds)
            {
                $content = $data[$contentId] ?? null;
                foreach ($alertIds AS $alertId)
                {
                    $alerts[$alertId]->setContent($content);
                }
            }
        }
    }

    /**
     * Respect user preferences for auto-read
     *
     * @noinspection PhpUnusedParameterInspection
     */
    public function patchAutoReadForInsertAlert(int $receiverId, string $contentType, string $action, array &$extra, array &$options = null)
    {
        /** @var UserOption|null $userOption */
        $userOption = Helper::find(UserOptionEntity::class, $receiverId);
        if ($userOption !== null)
        {
            $options['autoRead'] = $userOption->doesAutoReadAlert($contentType, $action);
        }
    }
}