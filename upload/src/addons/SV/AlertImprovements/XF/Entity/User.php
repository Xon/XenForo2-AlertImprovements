<?php

namespace SV\AlertImprovements\XF\Entity;

use XF\Mvc\Entity\Structure;

/**
 * Extends \XF\Entity\User
 *
 * @property int alerts_unviewed;
 * @property UserOption Option
 */
class User extends XFCP_User
{
    /**
     * @param Structure $structure
     * @return Structure
     */
    public static function getStructure(Structure $structure)
    {
        $structure = parent::getStructure($structure);

        try
        {
            /** @var \SV\AlertImprovements\XF\Repository\UserAlert $alertRepo */
            $alertRepo = \XF::app()->repository('XF:UserAlert');
        }
        catch (\Exception $e)
        {
            // error because we are still deploying files/updates.
            $alertRepo = null;
        }
        $userMaxAlertCount = $alertRepo && is_callable([$alertRepo, 'getSvUserMaxAlertCount']) ? $alertRepo->getSvUserMaxAlertCount() : 65535;

        if (\XF::$versionId < 2020000)
        {
            $structure->columns['alerts_unviewed'] = ['type' => self::UINT, 'forced' => true, 'max' => $userMaxAlertCount, 'default' => 0, 'changeLog' => false];
        }
        else
        {
            $structure->columns['alerts_unviewed']['max'] = $userMaxAlertCount;
        }
        $structure->columns['alerts_unread']['max'] = $userMaxAlertCount;

        return $structure;
    }
}