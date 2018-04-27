<?php

namespace SV\AlertImprovements\XF\Alert;

use SV\AlertImprovements\ISummarizeAlert;

class Post extends XFCP_Post implements ISummarizeAlert
{
    use SummarizeAlertTrait;

    public function canSummarizeForUser(array $optOuts)
    {
        return empty($optOuts['post_like']);
    }

    public function consolidateAlert(&$contentType, &$contentId, array $item)
    {
        switch ($contentType)
        {
            case 'post':
                return true;
            default:
                return false;
        }
    }
}
