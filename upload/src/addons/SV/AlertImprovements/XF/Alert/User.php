<?php

namespace SV\AlertImprovements\XF\Alert;

use SV\AlertImprovements\ISummarizeAlert;

/**
 * @extends \XF\Alert\User
 */
class User extends XFCP_User implements ISummarizeAlert
{
    public function canSummarizeForUser(array $optOuts): bool
    {
        return true;
    }

    public function getSupportedActionsForSummarization(): array
    {
        return [
            'following',
        ];
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
