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
}