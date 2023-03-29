<?php

namespace SV\AlertImprovements\XF\Alert;

use SV\AlertImprovements\XF\Entity\UserAlert as Alerts;

use function in_array;

/**
 * Trait SummarizeAlertTrait
 *
 * @package SV\AlertImprovements\XF\Alert
 */
trait SummarizeAlertTrait
{
    public function getSupportedActionsForSummarization(): array
    {
        return ['reaction'];
    }

    public function getSupportContentTypesForSummarization(): array
    {
        return [$this->contentType];
    }
}