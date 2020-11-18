<?php

namespace SV\AlertImprovements\XF\Alert;

use SV\AlertImprovements\ISummarizeAlert;
use SV\AlertImprovements\XF\Entity\UserAlert as Alerts;

/**
 * Class User
 *
 * @package SV\AlertImprovements\XF\Alert
 */
class User extends XFCP_User implements ISummarizeAlert
{
    use SummarizeAlertTrait;

    /**
     * @param array $optOuts
     * @return bool
     */
    public function canSummarizeForUser(array $optOuts)
    {
        return true;
    }

    /**
     * @param array $alert
     * @return bool
     */
    public function canSummarizeItem(array $alert)
    {
        switch ($alert['content_type'])
        {
            case 'profile_post':
            case 'profile_post_comment':
            case 'report_comment':
            case 'conversation_message':
            case 'post':
                $validActions = ['reaction'];

                return in_array($alert['action'], $validActions, true);
            default:
                return false;
        }
    }

    public function consolidateAlert(string &$contentType, int &$contentId, array $item): bool
    {
        return false;
    }

    /**
     * @param array    $summaryAlert
     * @param Alerts[] $alerts
     * @param string   $groupingStyle
     * @return array
     */
    public function summarizeAlerts(array $summaryAlert, array $alerts, string $groupingStyle)
    {
        $summaryAlert['action'] = $this->getSummaryAction($summaryAlert);

        return $summaryAlert;
    }
}
