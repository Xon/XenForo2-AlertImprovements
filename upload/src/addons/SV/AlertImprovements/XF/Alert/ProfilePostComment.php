<?php

namespace SV\AlertImprovements\XF\Alert;

use SV\AlertImprovements\ISummarizeAlert;

/**
 * @extends \XF\Alert\ProfilePostComment
 */
class ProfilePostComment extends XFCP_ProfilePostComment implements ISummarizeAlert
{
    use SummarizeAlertTrait;
}
