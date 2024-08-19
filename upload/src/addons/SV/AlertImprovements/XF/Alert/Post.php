<?php

namespace SV\AlertImprovements\XF\Alert;

use SV\AlertImprovements\ISummarizeAlert;

/**
 * @extends \XF\Alert\Post
 */
class Post extends XFCP_Post implements ISummarizeAlert
{
    use SummarizeAlertTrait;

    public function getSupportedActionsForSummarization(): array
    {
        return ['reaction', 'quote'];
    }
}