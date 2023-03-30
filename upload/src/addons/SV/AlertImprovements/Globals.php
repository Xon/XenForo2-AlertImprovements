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
    public static $forSummarizedAlertView = false;
    /** @var bool */
    public static $skipMarkAlertsRead = false;
    /** @var bool */
    public static $doAlertPopupRewrite = false;

    /**
     * @var bool
     */
    public static $showUnreadOnly = false;

    public static function isSkippingExpiredAlerts(): bool
    {
        return \XF::app()->config('svSkipExpiredAlerts') ?? true;
    }


    public static function isPrefetchRequest(): bool
    {
        $request = \XF::app()->request();
        if (\XF::$versionId >= 2020370)
        {
            return $request->isPrefetch();
        }

        return (
            $request->getServer('HTTP_X_MOZ') === 'prefetch'
            || $request->getServer('HTTP_X_PURPOSE') === 'prefetch'
            || $request->getServer('HTTP_PURPOSE') === 'prefetch'
        );
    }

    private function __construct() { }
}
