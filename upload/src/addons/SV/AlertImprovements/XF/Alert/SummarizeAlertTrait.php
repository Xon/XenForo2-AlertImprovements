<?php

namespace SV\AlertImprovements\XF\Alert;

use SV\AlertImprovements\XF\Entity\UserAlert as Alerts;

use function in_array;

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

        return in_array($alert['action'], $validActions, true);
    }

    protected function getSummaryAction(array $summaryAlert): string
    {
        return $summaryAlert['action'];
    }

    /**
     * @noinspection PhpParameterByRefIsNotUsedAsReferenceInspection
     */
    public function consolidateAlert(string &$contentType, int &$contentId, array $item): bool
    {
        return $this->contentType === $contentType;
    }

    /**
     * @param array    $summaryAlert
     * @param Alerts[] $alerts
     * @param string   $groupingStyle
     * @return array|null
     */
    public function summarizeAlerts(array $summaryAlert, array $alerts, string $groupingStyle): ?array
    {
        if ($groupingStyle !== 'content')
        {
            return null;
        }

        $summaryAlert['action'] = $this->getSummaryAction($summaryAlert);

        return $summaryAlert;
    }
}