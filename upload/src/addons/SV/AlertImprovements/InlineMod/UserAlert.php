<?php

namespace SV\AlertImprovements\InlineMod;

use XF\InlineMod\AbstractHandler;

class UserAlert extends AbstractHandler
{
    /**
     * @inheritDoc
     */
    public function getPossibleActions() : array
    {
        $actions = [];

        $actions['mark_as_read'] = $this->getActionHandler('SV\AlertImprovements:UserAlert\MarkAsRead');
        $actions['mark_as_unread'] = $this->getActionHandler('SV\AlertImprovements:UserAlert\MarkAsUnread');

        return $actions;
    }
}