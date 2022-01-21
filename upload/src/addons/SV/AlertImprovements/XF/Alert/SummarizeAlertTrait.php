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
    public function canSummarizeItem(array $alert): bool
    {
        $validActions = ['reaction'];

        return \in_array($alert['action'], $validActions, true);
    }

    protected function getSummaryAction(array $summaryAlert): string
    {
        return $summaryAlert['action'];
    }

    /**
     * @param array    $summaryAlert
     * @param Alerts[] $alerts
     * @param string   $groupingStyle
     * @return array|null
     */
    public function summarizeAlerts(array $summaryAlert, array $alerts, string $groupingStyle)//: ?array
    {
        if ($groupingStyle !== 'content')
        {
            return null;
        }

        $summaryAlert['action'] = $this->getSummaryAction($summaryAlert);

        return $summaryAlert;
    }
}