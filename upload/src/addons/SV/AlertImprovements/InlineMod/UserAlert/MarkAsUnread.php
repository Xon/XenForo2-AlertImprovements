<?php

namespace SV\AlertImprovements\InlineMod\UserAlert;

class MarkAsUnread extends AbstractToggleReadState
{
    protected function isForMarkingAsRead(): bool
    {
        return false;
    }
}