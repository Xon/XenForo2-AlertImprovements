<?php

namespace SV\AlertImprovements\XF\Alert;

use SV\AlertImprovements\ISummarizeAlert;
use SV\AlertImprovements\XF\Entity\UserAlert as Alerts;

use function in_array;

/**
 * Class User
 *
 * @package SV\AlertImprovements\XF\Alert
 */
class User extends XFCP_User implements ISummarizeAlert
{
    public function canSummarizeForUser(array $optOuts): bool
    {
        return true;
    }

    public function getSupportedActionsForSummarization(): array
    {
        return [];
    }

    public function getSupportContentTypesForSummarization(): array
    {
        return [
            'profile_post',
            'profile_post_comment',
            'report_comment',
            'conversation_message',
            'post',
        ];
    }
}
