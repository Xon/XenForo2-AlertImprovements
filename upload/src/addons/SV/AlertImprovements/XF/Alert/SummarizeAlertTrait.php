<?php

namespace SV\AlertImprovements\XF\Alert;

trait SummarizeAlertTrait
{
    public function canSummarizeItem(array $alert)
    {
        return $alert['action'] === 'like' || $alert['action'] === 'rating';
    }

    protected function getSummaryAction(array $summaryAlert)
    {
        return $summaryAlert['action'];
    }

    function summarizeAlerts(array $summaryAlert, array $alerts, $groupingStyle)
    {
        if ($groupingStyle !== 'content')
        {
            return null;
        }

        $summaryAlert['action'] = $this->getSummaryAction($summaryAlert);

        return $summaryAlert;
    }
}