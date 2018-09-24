<?php


namespace SV\AlertImprovements;

// This class is used to encapsulate global state between layers without using $GLOBAL[] or
// relying on the consumer being loaded correctly by the dynamic class autoloader
class Globals
{
    /** @var bool  */
    public static $markedAlertsRead    = false;
    /** @var bool  */
    public static $skipSummarize       = false;
    /** @var bool  */
    public static $skipSummarizeFilter = false;

    private function __construct() { }
}
