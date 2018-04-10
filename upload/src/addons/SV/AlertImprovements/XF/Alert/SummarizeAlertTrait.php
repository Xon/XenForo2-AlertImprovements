<?php

namespace SV\AlertImprovements\XF\Alert;

trait SummarizeAlertTrait
{
    protected function getSummaryAction(array $summaryAlert)
    {
        $app = \XF::app();
        $installedAddOns = $app->addOnManager()->getInstalledAddOns();

        if (!isset($installedAddOns['SV/ContentRatings']))
        {
            return $summaryAlert['action'];
        }
        
        $likeRatingId = intval($app->options()->svContentRatingsLikeRatingType);

        if (!$likeRatingId)
        {
            throw new \LogicException("Invalid Like rating type provided.");
        }

        $extraData = (!empty($summaryAlert['extra_data'])) ? @unserialize($summaryAlert['extra_data']) : false;
        if (!$extraData)
        {
            return $summaryAlert['action'];
        }

        if ($summaryAlert['action'] === 'rating')
        {
            if (!empty($extraData['rating_type_id'] === $likeRatingId))
            {
                return 'like_summary';
            }
        }

        return $summaryAlert['action'];
    }
}