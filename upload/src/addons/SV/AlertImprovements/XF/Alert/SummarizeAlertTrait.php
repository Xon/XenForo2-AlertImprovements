<?php

namespace SV\AlertImprovements\XF\Alert;

trait SummarizeAlertTrait
{
    protected function getSummaryAction(array $summaryAlert)
    {
        return $summaryAlert['action'];
    }
}