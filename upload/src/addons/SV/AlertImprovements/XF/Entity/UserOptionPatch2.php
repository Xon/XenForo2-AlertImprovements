<?php

namespace SV\AlertImprovements\XF\Entity;

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
               ?? $this->getSvAlertPreferencesRepo()->getAlertPreferenceDefault('discord', $contentType, $action);
    }
}
