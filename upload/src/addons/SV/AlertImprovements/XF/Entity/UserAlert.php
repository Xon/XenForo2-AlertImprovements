<?php


namespace SV\AlertImprovements\XF\Entity;


use XF\Mvc\Entity\Structure;

class UserAlert extends XFCP_UserAlert
{
    public function getIsSummary()
    {
        if ($this->summerize_id === null)
        {
            return (bool)preg_match('/^.*_summary$/', $this->action);
        }
        return false;
    }

    public static function getStructure(Structure $structure)
    {
        $structure = parent::getStructure($structure);

        $structure->columns['summerize_id'] = ['type' => self::UINT, 'nullable' => true, 'default' => null];

        $structure->getters['isSummary'] = [
            'getter' => true,
            'cache' => true
        ];

        $structure->relations['SummaryAlert'] = [
            'entity' => 'XF:UserAlert',
            'type' => self::TO_ONE,
            'conditions' => 'alert_id',
            'primary' => true
        ];

        return $structure;
    }
}
