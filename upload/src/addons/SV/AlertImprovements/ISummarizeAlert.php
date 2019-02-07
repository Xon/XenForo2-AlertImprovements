<?php


namespace SV\AlertImprovements;

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
     * @param int $contentId
     * @param array $item
     * @return bool
     */
    public function consolidateAlert(&$contentType, &$contentId, array $item);


    /**
     * @param array $summaryAlert
     * @param array[] $alerts
     * @param string $groupingStyle
     * @return array
     */
    public function summarizeAlerts(array $summaryAlert, array $alerts, $groupingStyle);
}
