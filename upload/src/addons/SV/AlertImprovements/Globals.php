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
    public static $skipSummarize = true;
    /** @var bool */
    public static $skipSummarizeFilter = false;
    /** @var bool */
    public static $skipMarkAlertsRead = false;
    /** @var bool */
    public static $skipExpiredAlerts = true;
    /** @var bool */
    public static $alertPopupExtraFetch = false;

    /**
     * @var bool
     */
    public static $showUnreadOnly = false;


    public static function isPrefetchRequest()
    {
        if (\XF::app()->request()->getServer('HTTP_X_MOZ') == 'prefetch')
        {
            return true;
        }

        return  false;
    }

    private function __construct() { }
}
