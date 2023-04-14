<?php

namespace SV\AlertImprovements\XFMG\Alert;

use SV\AlertImprovements\ISummarizeAlert;
use SV\AlertImprovements\XF\Alert\SummarizeAlertTrait;

/**
 * Extends \XFMG\Alert\Album
 */
class Album extends XFCP_Album implements ISummarizeAlert
{
    use SummarizeAlertTrait;
}