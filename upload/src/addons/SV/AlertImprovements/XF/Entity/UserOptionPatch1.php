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
        return $this->sv_alert_pref['alert'][$contentType][$action] ?? true;
    }

    public function doesReceivePush($contentType, $action)
    {
        /** @var UserOption $this */
        return ($this->app()->options()->enablePush ?? false)
               && $this->doesReceiveAlert($contentType, $action)
               && ($this->sv_alert_pref['push'][$contentType][$action] ?? true);
    }
}
