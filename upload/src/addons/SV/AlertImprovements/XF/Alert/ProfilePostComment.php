<?php

namespace SV\AlertImprovements\XF\Alert;

use SV\AlertImprovements\ISummarizeAlert;

class ProfilePostComment extends XFCP_ProfilePostComment implements ISummarizeAlert
{
    use SummarizeAlertTrait;

    public function canSummarizeForUser(array $optOuts)
    {
        return empty($optOuts['profile_post_like']);
    }

    public function consolidateAlert(&$contentType, &$contentId, array $item)
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
