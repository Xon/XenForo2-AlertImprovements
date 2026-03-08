<?php
/**
 * @noinspection PhpMissingReturnTypeInspection
 */

namespace SV\AlertImprovements\XF\Entity;

use SV\AlertImprovements\XF\Repository\UserAlert as ExtendedUserAlertRepo;
use SV\StandardLib\Helper;
use XF\Mvc\Entity\Structure;
use XF\Repository\UserAlert as UserAlertRepo;
use function is_callable;

/**
 * @extends \XF\Entity\User
 *
 * @property-read UserOption|null $Option
 */
class User extends XFCP_User
{
    public function canCustomizeAdvAlertPreferences(): bool
    {
        return $this->hasPermission('general', 'svCustomizeAdvAlertPrefs');
    }

    protected function _postSave()
    {
        parent::_postSave();
        if ($this->isUpdate() && $this->isChanged('user_state') && in_array($this->user_state, ['disabled', 'rejected']))
        {
            \XF::runLater(function () {
                /** @var ExtendedUserAlertRepo $alertRepo */
                $alertRepo = Helper::repository(UserAlertRepo::class);
                $alertRepo->cleanupPendingAlertRebuild($this->user_id);
            });
        }
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