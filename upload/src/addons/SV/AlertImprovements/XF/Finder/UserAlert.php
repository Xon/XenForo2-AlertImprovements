<?php

namespace SV\AlertImprovements\XF\Finder;

use SV\AlertImprovements\Globals;
use SV\StandardLib\Helper;
use XF\Mvc\Entity\AbstractCollection;
use XF\Mvc\Entity\Entity;
use XF\Repository\UserAlert as UserAlertRepo;
use function array_keys;
use function array_search;
use function array_unshift;
use function count;
use function implode;

/**
 * @extends \XF\Finder\UserAlert
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

    public function shimSource(?\Closure $shimSource = null): self
    {
        $this->shimSource = $shimSource;

        return $this;
    }

    /**
     * @param bool $shimCollectionViewable
     * @return UserAlert
     */
    public function markUnviewableAsUnread(bool $shimCollectionViewable = true): self
    {
        $this->shimCollectionViewable = $shimCollectionViewable;

        return $this;
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

                return \XF::em()->getBasicCollection($output);
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

    /**
     * When config('svRemoveAddonJoin') is false, $validContentTypes = null will skip this function
     * Otherwise the active add-on check will be removed
     *
     * @param array<string>|null $validContentTypes
     * @return $this
     */
    public function forValidContentTypes(?array $validContentTypes = null): self
    {
        $isRemovingAddOnJoin = Globals::isRemovingAddOnJoin();

        if ($validContentTypes === null)
        {
            if (!$isRemovingAddOnJoin)
            {
                return $this;
            }

            $alertRepo = Helper::repository(UserAlertRepo::class);
            $validContentTypes = array_keys($alertRepo->getAlertHandlers());
        }

        if (count($validContentTypes) === 0)
        {
            return $this->whereImpossible();
        }

        // The list of alert handlers is generally quite small, and so a simple array check is vastly faster than the additional join
        // XF ensures the list of alert handler classes is rebuild on add-on change, so this encodes the same information
        $this->where('content_type', $validContentTypes);

        if (!$isRemovingAddOnJoin)
        {
            return $this;
        }

        $found = false;
        foreach ($this->joins as $join)
        {
            if ($join['entity'] === 'XF:AddOn')
            {
                $found = true;
                break;
            }
        }

        // leave the actual join, as mysql is smart enough to throw away a left-join which has no dependencies
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

