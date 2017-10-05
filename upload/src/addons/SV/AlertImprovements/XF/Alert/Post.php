<?php

namespace SV\AlertImprovements\XF\Alert;

use SV\AlertImprovements\ISummarizeAlert;
use SV\AlertImprovements\XF\Entity\UserAlert;

class Post extends XFCP_Post implements ISummarizeAlert
{
    public function canSummarizeForUser(array $optOuts)
    {
        return empty($optOuts['post_like']);
    }

    public function canSummarizeItem(array $alert)
    {
        return $alert['action'] == 'like' || $alert['action'] == 'rating';
    }

    public function consolidateAlert(&$contentType, &$contentId, array $item)
    {
        switch($contentType)
        {
            case 'post':
                return true;
            default:
                return false;
        }
    }

    function summarizeAlerts(array $summaryAlert, array $alerts, $groupingStyle)
    {
        if ($groupingStyle != 'content')
        {
            return null;
        }
        $summaryAlert['action'] = 'like_summary';
        return $summaryAlert;
    }
}
