<?php

namespace SV\AlertImprovements\XF\Alert;

use SV\AlertImprovements\ISummarizeAlert;

/**
 * Class ProfilePost
 *
 * @package SV\AlertImprovements\XF\Alert
 */
class ProfilePost extends XFCP_ProfilePost implements ISummarizeAlert
{
    use SummarizeAlertTrait;

    /**
     * @param array $optOuts
     * @return bool
     */
    public function canSummarizeForUser(array $optOuts)
    {
        return empty($optOuts['profile_post_like']);
    }

    /**
     * @param string $contentType
     * @param int    $contentId
     * @param array  $item
     * @return bool
     */
    public function consolidateAlert(&$contentType, &$contentId, array $item)
    {
        switch ($contentType)
        {
            case 'profile_post':
                return true;
            default:
                return false;
        }
    }
}