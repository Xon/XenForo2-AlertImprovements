<?php

namespace SV\AlertImprovements\XF\Entity;

/**
 * Extends \XF\Entity\UserOption
 */
class UserOptionPatch2 extends XFCP_UserOptionPatch2
{
    public function doesReceiveDiscordMessage($contentType, $action): bool
    {
        if ($this->sv_alert_pref['none'] ?? false)
        {
            return false;
        }

        /** @var UserOption $this */
        return $this->sv_alert_pref['discord'][$contentType][$action]
               ?? $this->getSvAlertPreferencesRepo()->getAlertPreferenceDefault('discord', $contentType, $action);
    }
}
