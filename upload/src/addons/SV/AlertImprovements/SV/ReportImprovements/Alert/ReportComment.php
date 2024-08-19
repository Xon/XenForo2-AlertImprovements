<?php

namespace SV\AlertImprovements\SV\ReportImprovements\Alert;

use SV\AlertImprovements\ISummarizeAlert;
use SV\AlertImprovements\XF\Alert\SummarizeAlertTrait;

/**
 * @extends \SV\ReportImprovements\Alert\ReportComment
 */
class ReportComment extends XFCP_ReportComment implements ISummarizeAlert
{
    use SummarizeAlertTrait;
}