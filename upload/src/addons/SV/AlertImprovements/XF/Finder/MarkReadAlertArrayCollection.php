<?php

namespace SV\AlertImprovements\XF\Finder;

use SV\AlertImprovements\XF\Entity\UserAlert as ExtendedUserAlertEntity;
use SV\AlertImprovements\XF\Repository\UserAlert as ExtendedUserAlertRepo;
use SV\StandardLib\Helper;
use XF\Mvc\Entity\ArrayCollection;
use XF\Repository\UserAlert as UserAlertRepo;

class MarkReadAlertArrayCollection extends ArrayCollection
{
    /** @noinspection PhpMissingParentCallCommonInspection */
    public function filterViewable(): ArrayCollection
    {
        $unviewableAlerts = [];
        try
        {
            return $this->filter(function (ExtendedUserAlertEntity $entity) use (&$unviewableAlerts) {
                if ($entity->canView())
                {
                    return true;
                }
                $unviewableAlerts[] = $entity->alert_id;

                return false;
            });
        }
        finally
        {
            if ($unviewableAlerts)
            {
                /** @var ExtendedUserAlertRepo $alertRepo */
                $alertRepo = Helper::repository(UserAlertRepo::class);
                $alertRepo->markAlertIdsAsReadAndViewed(\XF::visitor(), $unviewableAlerts, \XF::$time, false);
            }
        }
    }
}