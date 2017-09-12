<?php


namespace SV\AlertImprovements;


use SV\AlertImprovements\XF\Entity\UserAlert;

interface ISummarizeAlert
{
    /**
     * @param array $optOuts
     * @return bool
     */
    function canSummarizeForUser(array $optOuts);

    /**
     * @param array $alert
     * @return mixed
     */
    function canSummarizeItem(array $alert);

    /**
     * @param string $contentType
     * @param int $contentId
     * @param array $item
     * @return bool
     */
    function consolidateAlert(&$contentType, &$contentId, array $item);


    /**
     * @param array $summaryAlert
     * @param array[] $alerts
     * @param string $groupingStyle
     * @return array
     */
    function summarizeAlerts(array $summaryAlert, array $alerts, $groupingStyle);
}
