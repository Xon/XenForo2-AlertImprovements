<?php
/**
 * @noinspection PhpMissingReturnTypeInspection
 */

namespace SV\AlertImprovements\XF\Entity;

use SV\StandardLib\Helper;
use XF\Mvc\Entity\Structure;
use XF\Repository\UserAlert as UserAlertRepo;
use function is_callable;

/**
 * Extends \XF\Entity\User
 *
 * @property-read UserOption $Option
 */
class User extends XFCP_User
{
    public function canCustomizeAdvAlertPreferences(): bool
    {
        return $this->hasPermission('general', 'svCustomizeAdvAlertPrefs');
    }

    /**
     * @param Structure $structure
     * @return Structure
     */
    public static function getStructure(Structure $structure)
    {
        $structure = parent::getStructure($structure);

        try
        {
            $alertRepo = Helper::repository(UserAlertRepo::class);
        }
        catch (\Exception $e)
        {
            // error because we are still deploying files/updates.
            $alertRepo = null;
        }
        $userMaxAlertCount = $alertRepo !== null && is_callable([$alertRepo, 'getSvUserMaxAlertCount']) ? $alertRepo->getSvUserMaxAlertCount() : 65535;

        $structure->columns['alerts_unviewed']['max'] = $userMaxAlertCount;
        $structure->columns['alerts_unread']['max'] = $userMaxAlertCount;

        return $structure;
    }
}