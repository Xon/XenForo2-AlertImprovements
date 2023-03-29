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

    public function canSummarizeForUser(array $optOuts): bool
    {
        return empty($optOuts['profile_post_react']);
    }
}