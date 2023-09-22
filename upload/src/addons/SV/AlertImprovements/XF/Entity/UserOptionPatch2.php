<?php

namespace SV\AlertImprovements\XF\Entity;

use SV\AlertImprovements\Repository\AlertPreferences as AlertPreferencesRepo;

/**
 * Extends \XF\Entity\UserOption
 */
class UserOptionPatch2 extends XFCP_UserOptionPatch2
{
    public function doesReceiveDiscordMessage($contentType, $action): bool
    {
        /** @var UserOption $this */
        $alertPreferences = $this->sv_alert_pref;
        if ($alertPreferences['none'] ?? false)
        {
            return false;
        }

        return $alertPreferences['discord'][$contentType][$action]
               ?? AlertPreferencesRepo::get()->getAlertPreferenceDefault('discord', $contentType, $action);
    }
}
