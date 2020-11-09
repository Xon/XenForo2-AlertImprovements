<?php


namespace SV\AlertImprovements;

/**
 * This class is used to encapsulate global state between layers without using $GLOBAL[] or relying on the consumer
 * being loaded correctly by the dynamic class autoloader
 * Class Globals
 *
 * @package SV\AlertImprovements
 */
class Globals
{
    /** @var bool */
    public static $skipSummarize = false;
    /** @var bool */
    public static $skipSummarizeFilter = false;
    /** @var bool */
    public static $skipMarkAlertsRead = false;

    private function __construct() { }
}
