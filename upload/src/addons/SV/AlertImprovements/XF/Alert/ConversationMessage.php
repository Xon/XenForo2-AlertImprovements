<?php

namespace SV\AlertImprovements\XF\Alert;

use SV\AlertImprovements\ISummarizeAlert;

/**
 * @extends \XF\Alert\ConversationMessage
 */
class ConversationMessage extends XFCP_ConversationMessage implements ISummarizeAlert
{
    use SummarizeAlertTrait;
}