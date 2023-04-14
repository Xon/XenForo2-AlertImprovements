<?php

namespace SV\AlertImprovements\XFMG\Alert;

use SV\AlertImprovements\ISummarizeAlert;
use SV\AlertImprovements\XF\Alert\SummarizeAlertTrait;

/**
 * Extends \XFMG\Alert\Comment
 */
class Comment extends XFCP_Comment implements ISummarizeAlert
{
    use SummarizeAlertTrait;
}