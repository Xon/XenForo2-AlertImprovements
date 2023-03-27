<?php

namespace SV\AlertImprovements\XF\Finder;

use XF\Mvc\Entity\AbstractCollection;
use XF\Mvc\Entity\Entity;
use function array_keys;
use function array_unshift;
use function count;

/**
 * Class UserAlert
 *
 * @package SV\AlertImprovements\XF\Finder
 */
class UserAlert extends XFCP_UserAlert
{
    /**
     * @var \Closure|null
     */
    protected $shimSource;
    /**
     * @var bool
     */
    protected $shimCollectionViewable = false;

    public function shimSource(\Closure $shimSource = null): self
    {
        $this->shimSource = $shimSource;

        return $this;
    }

    /**
     * @param bool $shimCollectionViewable
     */
    public function markUnviewableAsUnread(bool $shimCollectionViewable = true): void
    {
        $this->shimCollectionViewable = $shimCollectionViewable;
    }

    public function getShimmedCollection(array $entities): AbstractCollection
    {
        return new MarkReadAlertArrayCollection($entities);
    }

    public function forceUnreadFirst(): self
    {
        if ($this->order && $this->order[0] === '`xf_user_alert`.`event_date` DESC')
        {
            //$this->indexHint('FORCE', 'event_date');
            $this->indexHint('USE', 'alertedUserId_eventDate');
        }
        $viewColumn = $this->columnSqlName('view_date');
        array_unshift($this->order, "if ({$viewColumn} = 0, 0, 1)");

        return $this;
    }

    public function showUnreadOnly():self
    {
        $this->whereOr([
            // The addon essentially ignores read_date, so don't bother selecting on it.
            // This also improves index selectivity
            //['read_date', '=', 0],
            ['view_date', '=', 0]
        ]);

        return $this;
    }

    /**
     * @param int|null $limit
     * @param int|null $offset
     * @return AbstractCollection
     */
    public function fetch($limit = null, $offset = null)
    {
        $shimSource = $this->shimSource;
        // allow shimSource to call fetch() without re-entry issues
        $this->shimSource = null;

        if ($shimSource)
        {
            if ($limit === null)
            {
                $limit = $this->limit;
            }
            if ($offset === null)
            {
                $offset = $this->offset;
            }

            /** @var AbstractCollection|Entity[]|null $output */
            $output = $shimSource($limit, $offset);
            if ($output !== null)
            {
                if ($this->shimCollectionViewable)
                {
                    if ($output instanceof AbstractCollection)
                    {
                        $output = $output->toArray();
                    }

                    return $this->getShimmedCollection($output);
                }

                if ($output instanceof AbstractCollection)
                {
                    return $output;
                }

                return $this->em->getBasicCollection($output);
            }
        }

        $collection = parent::fetch($limit, $offset);

        if ($this->shimCollectionViewable && $collection instanceof AbstractCollection)
        {
            $collection = $this->getShimmedCollection($collection->toArray());
        }

        return $collection;
    }

    /**
     * @param array $rawEntities
     * @returns \SV\AlertImprovements\XF\Entity\UserAlert[]
     * @return array
     */
    public function materializeAlerts(array $rawEntities): array
    {
        $output = [];
        $em = $this->em;

        // bulk load users, really should track all joins/Withs.
        $userIds = [];
        foreach ($rawEntities as $rawEntity)
        {
            $userId = (int)($rawEntity['user_id'] ?? 0);
            if ($userId !== 0 && !$em->findCached('XF:User', $userId))
            {
                $userIds[$userId] = true;
            }
            $alertedUserId = (int)($rawEntity['user_id'] ?? 0);
            if ($alertedUserId !== 0 && !$em->findCached('XF:User', $alertedUserId))
            {
                $userIds[$alertedUserId] = true;
            }
        }

        if (count($userIds) !== 0)
        {
            $userIds = array_keys($userIds);
            $em->getFinder('XF:User')->whereIds($userIds)->fetch();
        }

        // materialize raw entities into Entities
        $id = $this->structure->primaryKey;
        $shortname = $this->structure->shortName;
        foreach ($rawEntities as $rawEntity)
        {
            $relations = [
                'User'     => $em->findCached('XF:User', $rawEntity['user_id']) ?: null,
                'Receiver' => $em->findCached('XF:User', $rawEntity['alerted_user_id']) ?: null,
            ];
            $output[$rawEntity[$id]] = $em->instantiateEntity($shortname, $rawEntity, $relations);
        }

        return $output;
    }

    public function undoUserJoin(): self
    {
        foreach ($this->joins as &$join)
        {
            if ($join['entity'] === 'XF:User' && !$join['fundamental'] && !$join['exists'])
            {
                $join['fetch'] = false;
            }
        }

        return $this;
    }
}

