<?php

namespace SV\AlertImprovements\XF\Entity;


use function array_filter;
use function array_unique;
use function sort;

/**
 * Extends \XF\Entity\ReactionContent
 */
class ReactionContentPatch extends XFCP_ReactionContentPatch
{
    protected function _saveToSource()
    {
        $this->svForceLockingOrder();
        return parent::_saveToSource();
    }

    protected function svForceLockingOrder(): void
    {
        // this is currently duplicated in SV/ContentRatings
        $userIds = [$this->content_user_id, $this->reaction_user_id];
        if ($this->isChanged('reaction_user_id'))
        {
            $userIds[] = (int)$this->getPreviousValue('reaction_user_id');
        }
        if ($this->isChanged('content_user_id'))
        {
            $userIds[] = (int)$this->getPreviousValue('content_user_id');
        }
        $userIds = array_filter($userIds);
        if (count($userIds) === 0)
        {
            return;
        }
        $userIds = array_unique($userIds);
        sort($userIds);
        $db = \XF::db();
        $db->fetchAllColumn('SELECT user_id FROM xf_user WHERE user_id in (' . $db->quote($userIds) . ') ORDER BY user_id FOR UPDATE ');
    }

    protected function svLockOrder()
    {
        // used by SV/ContentRating, stub it out so this add-on has priority
    }
}