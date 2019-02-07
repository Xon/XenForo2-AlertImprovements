<?php

namespace SV\AlertImprovements\XF\Template;

class Templater extends XFCP_Templater
{
    public function addDefaultHandlers()
    {
        parent::addDefaultHandlers();

        $this->addFunction('alert_summary_reaction', 'fnAlertSummaryReaction');
    }

    public function fnAlertSummaryReaction($templater, &$escape, $reactionId)
    {
        return $this->fn('reaction', [[
            'id' => $reactionId,
            'showtitle' => false,
            'hasreaction' => true,
            'tooltip' => true,
            'small' => true
        ]], $escape);
    }
}