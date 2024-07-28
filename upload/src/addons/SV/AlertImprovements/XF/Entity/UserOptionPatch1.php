<?php
/**
 * @noinspection PhpMissingReturnTypeInspection
 * @noinspection PhpMissingParentCallCommonInspection
 */

namespace SV\AlertImprovements\XF\Entity;

use SV\AlertImprovements\Repository\AlertPreferences as AlertPreferencesRepo;

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
               ?? AlertPreferencesRepo::get()->getAlertPreferenceDefault('alert', $contentType, $action);
    }

    public function doesReceivePush($contentType, $action)
    {
        /** @var UserOption $this */
        $alertPreferences = $this->sv_alert_pref;
        if ($alertPreferences['none'] ?? false)
        {
            return false;
        }

        return (\XF::app()->options()->enablePush ?? false)
               && $this->doesReceiveAlert($contentType, $action)
               && ($alertPreferences['push'][$contentType][$action]
                   ?? AlertPreferencesRepo::get()->getAlertPreferenceDefault('push', $contentType, $action)
               );
    }
}
