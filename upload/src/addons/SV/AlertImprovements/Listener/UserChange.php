<?php
/**
 * @noinspection PhpUnusedParameterInspection
 */

namespace SV\AlertImprovements\Listener;

use XF\Service\User\ContentChange as ContentChangeService;
use XF\Service\User\DeleteCleanUp as DeleteCleanUpService;

abstract class UserChange
{
    public static function userContentChangeInit(ContentChangeService $changeService, array &$updates): void
    {
        $updates['xf_sv_user_alert_rebuild'] = [
            ['user_id', 'emptytable' => false],
        ];

        $updates['xf_sv_user_alert_summary'] = [
            ['alerted_user_id', 'emptytable' => false],
        ];
    }

    public static function userDeleteCleanInit(DeleteCleanUpService $deleteService, array &$deletes): void
    {
        $deletes['xf_sv_user_alert_rebuild'] = 'user_id = ?';
        $deletes['xf_sv_user_alert_summary'] = 'alerted_user_id = ?';
    }

    private function __construct() { }
}