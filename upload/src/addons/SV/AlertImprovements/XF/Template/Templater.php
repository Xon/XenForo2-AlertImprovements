<?php

namespace SV\AlertImprovements\XF\Template;

class Templater extends XFCP_Templater
{
    public function addDefaultHandlers()
    {
        parent::addDefaultHandlers();

        $this->addFunction('alert_summary_reaction', 'fnAlertSummaryReaction');
    }

    /** @noinspection PhpUnusedParameterInspection */
    public function fnAlertSummaryReaction($templater, &$escape, $reactionId)
    {
        $func = \XF::$versionId >= 2010370 ? 'func' : 'fn';

        return $this->$func('reaction', [
            [
                'id'          => $reactionId,
                'showtitle'   => false,
                'hasreaction' => true,
                'tooltip'     => true,
                'small'       => true,
            ],
        ], $escape);
    }
}