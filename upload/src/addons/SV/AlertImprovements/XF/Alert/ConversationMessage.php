<?php

namespace SV\AlertImprovements\XF\Alert;

use SV\AlertImprovements\ISummarizeAlert;

/**
 * Class ConversationMessage
 *
 * @package SV\AlertImprovements\XF\Alert
 */
class ConversationMessage extends XFCP_ConversationMessage implements ISummarizeAlert
{
    use SummarizeAlertTrait;

    /**
     * @param array $optOuts
     * @return bool
     */
    public function canSummarizeForUser(array $optOuts)
    {
        return empty($optOuts['conversation_message_like']);
    }

    /**
     * @param string $contentType
     * @param int    $contentId
     * @param array  $item
     * @return bool
     */
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
}