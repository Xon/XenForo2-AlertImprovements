<?php

namespace SV\AlertImprovements\XF\Alert;

use SV\AlertImprovements\ISummarizeAlert;
use SV\AlertImprovements\XF\Entity\UserAlert;

class User extends XFCP_User implements ISummarizeAlert
{
    public function canSummarizeForUser(array $optOuts)
    {
        return true;
    }

    public function canSummarizeItem(UserAlert $alert)
    {
        switch($alert['content_type'])
        {
            case 'profile_post':
            case 'profile_post_comment':
            case 'report_comment':
            case 'conversation_message':
            case 'post':
                return $alert->action == 'like';
            case 'postrating':
                return $alert->action == 'rate';
            default:
                return false;
        }
    }

    public function consolidateAlert(&$contentType, &$contentId, UserAlert $item)
    {
        return false;
    }

    function summarizeAlerts(array $summaryAlert, array $alerts, $groupingStyle)
    {
        return $summaryAlert;
    }
}
