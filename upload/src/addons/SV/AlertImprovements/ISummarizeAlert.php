<?php

namespace SV\AlertImprovements;

use SV\AlertImprovements\XF\Entity\UserAlert as Alerts;

/**
 * Interface ISummarizeAlert
 *
 * @package SV\AlertImprovements
 */
interface ISummarizeAlert
{
    public function canSummarizeForUser(array $optOuts): bool;

    public function canSummarizeItem(array $alert): bool;

    /**
     * @param string $contentType
     * @param int    $contentId
     * @param array  $item
     * @return bool
     */
    public function consolidateAlert(string &$contentType, int &$contentId, array $item): bool;


    /**
     * @param array    $summaryAlert
     * @param Alerts[] $alerts
     * @param string   $groupingStyle
     * @return array|null
     */
    public function summarizeAlerts(array $summaryAlert, array $alerts, string $groupingStyle): ?array;
}
