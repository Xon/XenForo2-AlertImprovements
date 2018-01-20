<?php

namespace SV\AlertImprovements\XF\Alert;

use SV\AlertImprovements\ISummarizeAlert;
use SV\AlertImprovements\XF\Entity\UserAlert;
use XF\Mvc\Entity\Entity;

class ConversationMessage extends XFCP_ConversationMessage implements ISummarizeAlert
{
    public function canSummarizeForUser(array $optOuts)
    {
        return empty($optOuts['conversation_message_like']);
    }

    public function canSummarizeItem(array $alert)
    {
        return $alert['action'] === 'like' || $alert['action'] === 'rating';
    }

    public function consolidateAlert(&$contentType, &$contentId, array $item)
    {
        switch ($contentType)
        {
            case 'conversation_message':
                return true;
            default:
                return false;
        }
    }

    function summarizeAlerts(array $summaryAlert, array $alerts, $groupingStyle)
    {
        if ($groupingStyle !== 'content')
        {
            return null;
        }

        return $summaryAlert;
    }
}
