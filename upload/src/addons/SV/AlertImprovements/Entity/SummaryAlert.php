<?php

namespace SV\AlertImprovements\Entity;

use SV\AlertImprovements\XF\Entity\UserAlert;
use XF\Mvc\Entity\Entity;
use XF\Mvc\Entity\Structure;

/**
 * @property int $alert_id
 * @property int $alerted_user_id
 * @property string $content_type
 * @property int $content_id
 * @property string $action
 *
 * @property-read UserAlert|null $Alert
 */
class SummaryAlert extends Entity
{
    /** @noinspection PhpMissingParentCallCommonInspection */
    public static function getStructure(Structure $structure): Structure
    {
        $structure->table = 'xf_sv_user_alert_summary';
        $structure->shortName = 'SV\AlertImprovements:SummaryAlert';
        $structure->primaryKey = 'alert_id';
        $structure->columns = [
            'alert_id' => ['type' => self::UINT, 'require' => true],
            'alerted_user_id' => ['type' => self::UINT, 'require' => true],
            'content_type' => ['type' => self::STR, 'maxLength' => 25, 'required' => true, 'api' => true],
            'content_id' => ['type' => self::UINT, 'required' => true, 'api' => true],
            'action' => ['type' => self::STR, 'maxLength' => 30, 'required' => true, 'api' => true],
        ];

        $structure->relations = [
            'Alert' => [
                'entity' => 'XF:UserAlert',
                'type' => self::TO_ONE,
                'conditions' => 'alert_id',
                'primary' => true,
            ],
            'User' => [
                'entity' => 'XF:User',
                'type' => self::TO_ONE,
                'conditions' => [['user_id', '=', '$alerted_user_id']],
                'primary' => true,
            ],
        ];

        return $structure;
    }
}