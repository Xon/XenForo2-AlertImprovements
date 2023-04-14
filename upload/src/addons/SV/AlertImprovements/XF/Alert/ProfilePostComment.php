<?php

namespace SV\AlertImprovements\XF\Alert;

use SV\AlertImprovements\ISummarizeAlert;

/**
 * Class ProfilePostComment
 *
 * @package SV\AlertImprovements\XF\Alert
 */
class ProfilePostComment extends XFCP_ProfilePostComment implements ISummarizeAlert
{
    use SummarizeAlertTrait;
}
