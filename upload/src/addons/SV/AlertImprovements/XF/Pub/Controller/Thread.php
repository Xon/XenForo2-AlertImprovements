<?php

namespace SV\AlertImprovements\XF\Pub\Controller;

use SV\AlertImprovements\XF\Repository\UserAlert;
use XF\Entity\Thread as ThreadEntity;
use XF\Mvc\Entity\AbstractCollection;
use XF\Mvc\ParameterBag;
use XF\Mvc\Reply\View;

/**
 * Class Thread
 *
 * @package SV\AlertImprovements\XF\Pub\Controller
 */
class Thread extends XFCP_Thread
{
    /**
     * @param ParameterBag $params
     * @return \XF\Mvc\Reply\Error|\XF\Mvc\Reply\Redirect|View
     */
    public function actionIndex(ParameterBag $params)
    {
        $reply = parent::actionIndex($params);

        if (\XF::$versionId < 2010000 && $reply instanceof View && ($posts = $reply->getParam('posts')))
        {
            $visitor = \XF::visitor();

            if ($visitor->user_id && $visitor->alerts_unread)
            {
                /** @var UserAlert $alertRepo */
                $alertRepo = $this->repository('XF:UserAlert');
                $alertRepo->markAlertsReadForContentIds('post', $posts->keys(), null, 2010000);
            }
        }

        return $reply;
    }

    /**
     * @param ThreadEntity $thread
     * @param int          $lastDate
     * @return View
     */
    protected function getNewPostsReply(ThreadEntity $thread, $lastDate)
    {
        $reply = parent::getNewPostsReply($thread, $lastDate);

        if ($reply instanceof View && ($posts = $reply->getParam('posts')))
        {
            $visitor = \XF::visitor();

            if ($visitor->user_id && $visitor->alerts_unread)
            {
                /** @var UserAlert $alertRepo */
                $alertRepo = $this->repository('XF:UserAlert');
                $alertRepo->markAlertsReadForContentIds('post', $posts->keys());
            }
        }

        return $reply;
    }

    /**
     * @param ThreadEntity $thread
     * @param int          $lastDate
     * @param int          $limit
     * @return array
     */
    protected function _getNextLivePosts(ThreadEntity $thread, $lastDate, $limit = 3)
    {
        /** @noinspection PhpUndefinedMethodInspection */
        /**
         * @var AbstractCollection $contents
         * @var int                $lastDate
         */
        list ($contents, $lastDate) = parent::_getNextLivePosts($thread, $lastDate, $limit);

        /** @var UserAlert $alertRepo */
        $alertRepo = $this->repository('XF:UserAlert');
        $alertRepo->markAlertsReadForContentIds('post', $contents->keys());

        return [$contents, $lastDate];
    }
}
