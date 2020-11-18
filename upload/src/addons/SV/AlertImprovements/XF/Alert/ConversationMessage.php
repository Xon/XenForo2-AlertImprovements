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
        return empty($optOuts['conversation_message_react']);
    }

    /**
     * @noinspection PhpParameterByRefIsNotUsedAsReferenceInspection
     */
    public function consolidateAlert(string &$contentType, int &$contentId, array $item): bool
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