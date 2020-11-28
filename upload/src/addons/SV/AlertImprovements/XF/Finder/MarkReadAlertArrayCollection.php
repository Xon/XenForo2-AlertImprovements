<?php

namespace SV\AlertImprovements\XF\Finder;

use SV\AlertImprovements\XF\Entity\UserAlert as ExtendedUserAlertEntity;
use SV\AlertImprovements\XF\Repository\UserAlert as ExtendedUserAlertRepo;
use XF\Mvc\Entity\ArrayCollection;

class MarkReadAlertArrayCollection extends ArrayCollection
{
    public function filterViewable()
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
                $alertRepo = \XF::repository('XF:UserAlert');
                $alertRepo->markAlertIdsAsReadAndViewed(\XF::visitor(), $unviewableAlerts, \XF::$time, false);
            }
        }
    }
}