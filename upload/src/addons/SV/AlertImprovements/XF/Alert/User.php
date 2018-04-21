<?php

namespace SV\AlertImprovements\XF\Alert;

use SV\AlertImprovements\ISummarizeAlert;

class User extends XFCP_User implements ISummarizeAlert
{
    use SummarizeAlertTrait;

    public function canSummarizeForUser(array $optOuts)
    {
        return true;
    }

    public function canSummarizeItem(array $alert)
    {
        switch($alert['content_type'])
        {
            case 'profile_post':
            case 'profile_post_comment':
            case 'report_comment':
            case 'conversation_message':
            case 'post':
                return $alert['action'] === 'like' || $alert['action'] === 'rating';
            default:
                return false;
        }
    }

    public function consolidateAlert(&$contentType, &$contentId, array $item)
    {
        return false;
    }

    function summarizeAlerts(array $summaryAlert, array $alerts, $groupingStyle)
    {
        $summaryAlert['action'] = $this->getSummaryAction($summaryAlert);

        return $summaryAlert;
    }
}
