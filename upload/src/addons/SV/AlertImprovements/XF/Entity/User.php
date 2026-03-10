<?php
/**
 * @noinspection PhpMissingReturnTypeInspection
 */

namespace SV\AlertImprovements\XF\Entity;

use SV\AlertImprovements\XF\Repository\UserAlert as ExtendedUserAlertRepo;
use SV\StandardLib\Helper;
use XF\Mvc\Entity\Structure;
use XF\Repository\UserAlert as UserAlertRepo;
use function in_array;
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

        if ($this->isUpdate() && ($this->isChanged('user_state') || $this->isChanged('is_banned')))
        {
            $oldUserState = (string)($this->getExistingValue('is_banned') ? 'banned' : $this->getExistingValue('user_state'));
            $newUserState = $this->is_banned ? 'banned' : $this->user_state;

            /** @var ExtendedUserAlertRepo $alertRepo */
            $alertRepo = Helper::repository(UserAlertRepo::class);
            $skipUserAlertTotalsRebuildStates = $alertRepo->getSvSkipUserAlertTotalsRebuildStates();

            $wasSkipped = in_array($oldUserState, $skipUserAlertTotalsRebuildStates, true);
            $isSkipped = in_array($newUserState, $skipUserAlertTotalsRebuildStates, true);

            if (!$wasSkipped && $isSkipped)
            {
                \XF::runOnce('svUserAlertTotalRebuild-'. $this->user_id, function () use ($alertRepo): void {
                    $alertRepo->cleanupPendingAlertRebuild($this->user_id);
                });
            }
            else if ($wasSkipped && !$isSkipped)
            {
                \XF::runOnce('svUserAlertTotalRebuild-'. $this->user_id, function () use ($alertRepo): void {
                    $alertRepo->insertPendingAlertRebuild($this->user_id);
                });
            }
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