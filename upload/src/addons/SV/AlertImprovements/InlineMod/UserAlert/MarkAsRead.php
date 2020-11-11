<?php

namespace SV\AlertImprovements\InlineMod\UserAlert;

class MarkAsRead extends AbstractToggleReadState
{
    protected function isForMarkingAsRead(): bool
    {
        return true;
    }
}