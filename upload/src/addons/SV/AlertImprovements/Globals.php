<?php


namespace SV\AlertImprovements;

// This class is used to encapsulate global state between layers without using $GLOBAL[] or
// relying on the consumer being loaded correctly by the dynamic class autoloader
class Globals
{
    public static $markedAlertsRead = false;
    public static $skipSummarize = false;
    public static $skipSummarizeFilter = false;

    private function __construct() {}
}
