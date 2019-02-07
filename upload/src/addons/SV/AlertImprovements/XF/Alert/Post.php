<?php

namespace SV\AlertImprovements\XF\Alert;

use SV\AlertImprovements\ISummarizeAlert;

/**
 * Class Post
 *
 * @package SV\AlertImprovements\XF\Alert
 */
class Post extends XFCP_Post implements ISummarizeAlert
{
    use SummarizeAlertTrait;

    /**
     * @param array $optOuts
     * @return bool
     */
    public function canSummarizeForUser(array $optOuts)
    {
        return empty($optOuts['report_comment_like']);
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
            case 'post':
                return true;
            default:
                return false;
        }
    }
}