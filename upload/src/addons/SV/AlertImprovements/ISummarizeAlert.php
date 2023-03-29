<?php

namespace SV\AlertImprovements;

use SV\AlertImprovements\XF\Entity\UserAlert as Alerts;

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
