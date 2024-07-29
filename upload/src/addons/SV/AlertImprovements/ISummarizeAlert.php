<?php

namespace SV\AlertImprovements;

/**
 * Interface ISummarizeAlert
 *
 * @package SV\AlertImprovements
 */
interface ISummarizeAlert
{
    public function canSummarizeForUser(array $optOuts): bool;

    public function getSupportedActionsForSummarization(): array;
    public function getSupportContentTypesForSummarization(): array;
}
