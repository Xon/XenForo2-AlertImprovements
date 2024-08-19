<?php

namespace SV\AlertImprovements\XF\ChangeLog;

use XF\Phrase;

/**
 * @extends \XF\ChangeLog\User
 */
class User extends XFCP_User
{
    /** @noinspection PhpMissingReturnTypeInspection */
    protected function getFormatterMap()
    {
        $map = parent::getFormatterMap();
        $map['sv_alerts_popup_read_behavior'] = 'svFormatAlertsPopUpReadBehavior';

        return $map;
    }

    public function svFormatAlertsPopUpReadBehavior(string $value): Phrase
    {
        return \XF::phrase('svAlertPopUpReadBehavior.'.$value);
    }
}