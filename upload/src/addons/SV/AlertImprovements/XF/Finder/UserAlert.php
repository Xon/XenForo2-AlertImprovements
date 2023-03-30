<?php

namespace SV\AlertImprovements\XF\Finder;

use XF\Mvc\Entity\AbstractCollection;
use XF\Mvc\Entity\Entity;
use function array_keys;
use function array_search;
use function array_unshift;
use function count;
use function implode;
use function str_replace;

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

    public function forValidContentTypes(array $validContentTypes): self
    {
        if (count($validContentTypes) === 0)
        {
            return $this->whereImpossible();
        }

        $this->where('content_type', $validContentTypes);

        $found = false;
        foreach ($this->joins as $join)
        {
            if ($join['entity'] === 'XF:AddOn')
            {
                $found = true;
                break;
            }
        }

        if ($found)
        {
            $conditions = [];
            foreach ([
                         'AddOn.active'        => 1,
                         'depends_on_addon_id' => '',
                     ] as $key => $value)
            {
                $conditions[] = $this->columnSqlName($key, false) . ' = ' . $this->quote($value);
            }
            $sql = '(' . implode(') OR (', $conditions) . ')';

            $i = array_search($sql, $this->conditions, true);
            if ($i !== false)
            {
                unset($this->conditions[$i]);
            }
        }

        return $this;
    }
}

