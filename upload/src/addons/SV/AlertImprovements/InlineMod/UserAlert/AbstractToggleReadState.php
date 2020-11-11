<?php

namespace SV\AlertImprovements\InlineMod\UserAlert;

use SV\AlertImprovements\InlineMod\UserAlert\Exception\DoesNotSupportApplyingPerAlertException;
use XF\InlineMod\AbstractAction;
use XF\Mvc\Entity\AbstractCollection;
use XF\Mvc\Entity\Entity;
use XF\Entity\UserAlert as UserAlertEntity;
use XF\Mvc\Entity\Repository;
use XF\Phrase;
use XF\Repository\UserAlert as UserAlertRepo;
use SV\AlertImprovements\XF\Repository\UserAlert as ExtendedUserAlertRepo;

abstract class AbstractToggleReadState extends AbstractAction
{
    abstract protected function isForMarkingAsRead() : bool;

    public function getTitle() : Phrase
    {
        if ($this->isForMarkingAsRead())
        {
            return \XF::phrase('svAlertImprov_mark_alerts_read');
        }

        return \XF::phrase('svAlertImprov_mark_alerts_unread');
    }

    /**
     * @param Entity|UserAlertEntity $entity
     * @param array $options
     * @param Phrase|null $error
     *
     * @return bool
     */
    protected function canApplyToEntity(Entity $entity, array $options, &$error = null) : bool
    {
        return $entity->canView($error);
    }

    /**
     * @param Entity|UserAlertEntity $entity
     * @param array $options
     */
    protected function applyToEntity(Entity $entity, array $options)
    {
        throw new DoesNotSupportApplyingPerAlertException();
    }

    protected function applyInternal(AbstractCollection $entities, array $options)
    {
        $user = \XF::visitor();
        $alertIds = $entities->keys();

        if ($this->isForMarkingAsRead())
        {
            $this->getUserAlertRepo()->markAlertIdsAsReadAndViewed($user, $alertIds, \XF::$time);
        }
        else
        {
            $this->getUserAlertRepo()->markAlertIdsAsUnreadAndUnviewed($user, $alertIds);
        }
    }

    /**
     * @return Repository|UserAlertRepo|ExtendedUserAlertRepo
     */
    protected function getUserAlertRepo() : UserAlertRepo
    {
        return $this->repository('XF:UserAlert');
    }

    protected function repository(string $identifier) : Repository
    {
        return $this->app()->repository($identifier);
    }
}