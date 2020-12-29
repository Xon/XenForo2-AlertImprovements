<?php

namespace SV\AlertImprovements\XF\Pub\Controller;

use SV\AlertImprovements\XF\Repository\UserAlert;
use XF\Entity\Thread as ThreadEntity;
use XF\Mvc\Entity\AbstractCollection;

/**
 * Class Thread
 *
 * @package SV\AlertImprovements\XF\Pub\Controller
 */
class Thread extends XFCP_Thread
{
    /**
     * @param ThreadEntity $thread
     * @param int          $lastDate
     * @param int          $limit
     * @return array
     * @noinspection PhpMissingParamTypeInspection
     */
    protected function _getNextLivePosts(ThreadEntity $thread, $lastDate, $limit = 3)
    {
        /**
         * @var AbstractCollection $contents
         * @var int                $lastDate
         */
        /** @noinspection PhpUndefinedMethodInspection */
        list ($contents, $lastDate) = parent::_getNextLivePosts($thread, $lastDate, $limit);

        /** @var UserAlert $alertRepo */
        $alertRepo = $this->repository('XF:UserAlert');
        $alertRepo->markAlertsReadForContentIds('post', $alertRepo->getContentIdKeys($contents));

        return [$contents, $lastDate];
    }
}
