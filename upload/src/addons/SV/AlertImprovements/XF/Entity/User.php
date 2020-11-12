<?php

namespace SV\AlertImprovements\XF\Entity;

use XF\Mvc\Entity\Structure;

/**
 * Extends \XF\Entity\User
 *
 * @property int alerts_unviewed;
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

        if (\XF::$versionId < 2020000)
        {
            $structure->columns['alerts_unviewed'] = ['type' => self::UINT, 'forced' => true, 'max' => 65535, 'default' => 0, 'changeLog' => false];
        }
    
        return $structure;
    }
}