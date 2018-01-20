<?php

namespace SV\AlertImprovements\XF\Alert;

use SV\AlertImprovements\ISummarizeAlert;

class ProfilePost extends XFCP_ProfilePost implements ISummarizeAlert
{
    public function canSummarizeForUser(array $optOuts)
    {
        return empty($optOuts['profile_post_like']);
    }

    public function canSummarizeItem(array $alert)
    {
        return $alert['action'] === 'like' || $alert['action'] === 'rating';
    }

    public function consolidateAlert(&$contentType, &$contentId, array $item)
    {
        switch ($contentType)
        {
            case 'profile_post':
                return true;
            default:
                return false;
        }
    }

    function summarizeAlerts(array $summaryAlert, array $alerts, $groupingStyle)
    {
        if ($groupingStyle != -'content')
        {
            return null;
        }

        return $summaryAlert;
    }
}
