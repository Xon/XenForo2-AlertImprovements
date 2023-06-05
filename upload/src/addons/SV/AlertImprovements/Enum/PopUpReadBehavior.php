<?php

namespace SV\AlertImprovements\Enum;

use XF\Phrase;

// todo: be an enum
abstract class PopUpReadBehavior
{
    public const AlwaysMarkRead = 'AlwaysMarkRead';
    public const NeverMarkRead  = 'NeverMarkRead';
    public const PerUser        = 'PerUser';

    public static function get(): array
    {
        return [
            PopUpReadBehavior::AlwaysMarkRead,
            PopUpReadBehavior::NeverMarkRead,
            PopUpReadBehavior::PerUser,
        ];
    }

    /**
     * @return array<string,Phrase>
     */
    public static function getPairs(): array
    {
        $pairs = [];

        foreach (self::get() as $key)
        {
            $pairs[$key] = \XF::phrase('svAlertPopUpReadBehavior.' . $key);
        }

        return $pairs;
    }
}