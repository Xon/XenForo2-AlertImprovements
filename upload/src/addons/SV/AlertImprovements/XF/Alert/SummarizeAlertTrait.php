<?php

namespace SV\AlertImprovements\XF\Alert;

trait SummarizeAlertTrait
{
    /**
     * @param array $alert
     * @return bool
     */
    public function canSummarizeItem(array $alert)
    {
        return $alert['action'] === 'like' || $alert['action'] === 'rating';
    }

    /**
     * @param array $summaryAlert
     * @return string
     */
    protected function getSummaryAction(array $summaryAlert)
    {
        return $summaryAlert['action'];
    }

    /**
     * @param array  $summaryAlert
     * @param array  $alerts
     * @param string $groupingStyle
     * @return array|null
     */
    function summarizeAlerts(/** @noinspection PhpUnusedParameterInspection */ array $summaryAlert, array $alerts, $groupingStyle)
    {
        if ($groupingStyle !== 'content')
        {
            return null;
        }

        $summaryAlert['action'] = $this->getSummaryAction($summaryAlert);

        return $summaryAlert;
    }
}