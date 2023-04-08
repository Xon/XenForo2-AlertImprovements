<?php

namespace SV\AlertImprovements\XFMG\Alert;

use SV\AlertImprovements\ISummarizeAlert;
use SV\AlertImprovements\XF\Alert\SummarizeAlertTrait;

/**
 * Extends \XFMG\Alert\Media
 */
class Media extends XFCP_Media implements ISummarizeAlert
{
    use SummarizeAlertTrait;

    public function canSummarizeForUser(array $optOuts): bool
    {
        return empty($optOuts['xfmg_media_react']);
    }
}