<?php
/**
 * @noinspection PhpMissingReturnTypeInspection
 * @noinspection PhpMissingParentCallCommonInspection
 */

namespace SV\AlertImprovements\XF\Entity;

use XF\Mvc\Entity\Entity;
use XF\Mvc\Entity\Structure;
use function in_array;

/**
 * Extends \XF\Entity\UserOption
 */
class UserOptionPatch1 extends XFCP_UserOptionPatch1
{
    public function doesReceiveAlert($contentType, $action)
    {
        /** @var UserOption $this */
        $alertPreferences = $this->sv_alert_pref;
        if ($alertPreferences['none'] ?? false)
        {
            return false;
        }

        return $alertPreferences['alert'][$contentType][$action]
               ?? $this->getSvAlertPreferencesRepo()->getAlertPreferenceDefault('alert', $contentType, $action);
    }

    public function doesReceivePush($contentType, $action)
    {
        /** @var UserOption $this */
        $alertPreferences = $this->sv_alert_pref;
        if ($alertPreferences['none'] ?? false)
        {
            return false;
        }

        return ($this->app()->options()->enablePush ?? false)
               && $this->doesReceiveAlert($contentType, $action)
               && ($alertPreferences['push'][$contentType][$action]
                   ?? $this->getSvAlertPreferencesRepo()->getAlertPreferenceDefault('push', $contentType, $action)
               );
    }
}
