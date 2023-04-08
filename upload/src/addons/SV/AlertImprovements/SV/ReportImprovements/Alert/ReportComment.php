<?php

namespace SV\AlertImprovements\SV\ReportImprovements\Alert;

use SV\AlertImprovements\ISummarizeAlert;
use SV\AlertImprovements\XF\Alert\SummarizeAlertTrait;

/**
 * Extends \SV\ReportImprovements\Alert\ReportComment
 */
class ReportComment extends XFCP_ReportComment implements ISummarizeAlert
{
    use SummarizeAlertTrait;

    public function canSummarizeForUser(array $optOuts): bool
    {
        return empty($optOuts['report_comment_react']);
    }
}