<?php

namespace SV\AlertImprovements\XF\Alert;

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

    public function canSummarizeForUser(array $optOuts): bool
    {
        return true;
    }

    public function getSupportContentTypesForSummarization(): array
    {
        return [$this->contentType];
    }
}
