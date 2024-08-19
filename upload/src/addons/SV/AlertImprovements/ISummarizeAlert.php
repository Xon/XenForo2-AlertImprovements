<?php

namespace SV\AlertImprovements;

interface ISummarizeAlert
{
    public function canSummarizeForUser(array $optOuts): bool;

    public function getSupportedActionsForSummarization(): array;
    public function getSupportContentTypesForSummarization(): array;
}
