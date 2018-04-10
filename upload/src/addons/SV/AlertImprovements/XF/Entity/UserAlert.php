<?php


namespace SV\AlertImprovements\XF\Entity;


use XF\Mvc\Entity\Structure;

/**
 * Class UserAlert
 *
 * @property bool IsSummary
 * @property int summerize_id
 * @property UserAlert SummaryAlert
 */
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

    public function getSvRatingTypes()
    {
        if (is_array($this->extra_data['rating_type_id']))
        {
            $ratings = array_keys($this->extra_data['rating_type_id']);
            return $this->finder('SV\ContentRatings:RatingType')
                ->where('rating_type_id', '=', $ratings)
                ->fetch();
        }
        return null;
    }

    public static function getStructure(Structure $structure)
    {
        $structure = parent::getStructure($structure);

        $structure->columns['summerize_id'] = ['type' => self::UINT, 'nullable' => true, 'default' => null];

        $structure->getters['isSummary'] = [
            'getter' => true,
            'cache'  => true
        ];
        $structure->getters['sv_rating_types'] = true;

        $structure->relations['SummaryAlert'] = [
            'entity'     => 'XF:UserAlert',
            'type'       => self::TO_ONE,
            'conditions' => [['alert_id', '=', '$summerize_id']],
            'primary'    => true
        ];

        return $structure;
    }
}
