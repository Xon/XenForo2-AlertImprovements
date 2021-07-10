<?php

namespace SV\AlertImprovements\XF\Alert;

use SV\AlertImprovements\ISummarizeAlert;

/**
 * Class ProfilePostComment
 *
 * @package SV\AlertImprovements\XF\Alert
 */
class ProfilePostComment extends XFCP_ProfilePostComment implements ISummarizeAlert
{
    use SummarizeAlertTrait;

    public function canSummarizeForUser(array $optOuts): bool
    {
        return empty($optOuts['profile_post_react']);
    }

    /**
     * @noinspection PhpParameterByRefIsNotUsedAsReferenceInspection
     */
    public function consolidateAlert(string &$contentType, int &$contentId, array $item): bool
    {
        switch ($contentType)
        {
            case 'profile_post_comment':
                return true;
            default:
                return false;
        }
    }
}
