<?php

namespace SV\AlertImprovements\XF\Alert;

use SV\AlertImprovements\ISummarizeAlert;

/**
 * @extends \XF\Alert\ProfilePost
 */
class ProfilePost extends XFCP_ProfilePost implements ISummarizeAlert
{
    use SummarizeAlertTrait;
}