<?php

namespace SV\AlertImprovements\XF\Alert;

use SV\AlertImprovements\XF\Entity\UserAlert as Alerts;

/**
 * Trait SummarizeAlertTrait
 *
 * @package SV\AlertImprovements\XF\Alert
 */
trait SummarizeAlertTrait
{
    /**
     * @param array $alert
     * @return bool
     */
    public function canSummarizeItem(array $alert)
    {
        $validActions = ['like', 'rating', 'reaction'];

        return in_array($alert['action'], $validActions, true);
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
     * @param array    $summaryAlert
     * @param Alerts[] $alerts
     * @param string   $groupingStyle
     * @return array|null
     * @noinspection PhpUnusedParameterInspection
     */
    public function summarizeAlerts(array $summaryAlert, array $alerts, $groupingStyle)
    {
        if ($groupingStyle !== 'content')
        {
            return null;
        }

        $summaryAlert['action'] = $this->getSummaryAction($summaryAlert);

        return $summaryAlert;
    }
}