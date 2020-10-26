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
    /**
     * @param array $optOuts
     * @return bool
     */
    public function canSummarizeForUser(array $optOuts);

    /**
     * @param array $alert
     * @return mixed
     */
    public function canSummarizeItem(array $alert);

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
     * @return array
     */
    public function summarizeAlerts(array $summaryAlert, array $alerts, string $groupingStyle);
}
