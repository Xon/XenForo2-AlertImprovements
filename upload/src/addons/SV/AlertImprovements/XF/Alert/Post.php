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
        return empty($optOuts['report_comment_react']);
    }

    /**
     * @noinspection PhpParameterByRefIsNotUsedAsReferenceInspection
     */
    public function consolidateAlert(string &$contentType, int &$contentId, array $item): bool
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