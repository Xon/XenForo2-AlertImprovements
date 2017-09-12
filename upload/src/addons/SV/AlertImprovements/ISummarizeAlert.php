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
     * @param UserAlert $alert
     * @return mixed
     */
    function canSummarizeItem(UserAlert $alert);

    /**
     * @param string $contentType
     * @param int $contentId
     * @param UserAlert $item
     * @return bool
     */
    function consolidateAlert(&$contentType, &$contentId, UserAlert $item);


    /**
     * @param array $summaryAlert
     * @param UserAlert[] $alerts
     * @param string $groupingStyle
     * @return array
     */
    function summarizeAlerts(array $summaryAlert, array $alerts, $groupingStyle);
}
