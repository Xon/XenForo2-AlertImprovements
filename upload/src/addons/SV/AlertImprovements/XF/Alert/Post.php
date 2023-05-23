<?php

namespace SV\AlertImprovements\XF\Alert;

use SV\AlertImprovements\ISummarizeAlert;

/**
 * Class Post
 *
 * @package SV\AlertImprovements\XF\Alert
 */
class Post extends XFCP_Post implements ISummarizeAlert
{
    use SummarizeAlertTrait;

    public function getSupportedActionsForSummarization(): array
    {
        return ['reaction', 'quote'];
    }
}