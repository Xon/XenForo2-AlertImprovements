<?php

namespace SV\AlertImprovements\XF\Pub\Controller;

use SV\AlertImprovements\XF\Repository\UserAlert;
use XF\Entity\Thread as ThreadEntity;
use XF\Mvc\Entity\AbstractCollection;
use XF\Mvc\ParameterBag;
use XF\Mvc\Reply\AbstractReply;
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
     * @return AbstractReply
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
     * XF2.0-XF2.1, new posts are marked as read when viewed in XF2.2+
     *
     * @param ThreadEntity $thread
     * @param int          $lastDate
     * @return AbstractReply
     * @noinspection PhpMissingParamTypeInspection
     */
    protected function getNewPostsReply(ThreadEntity $thread, $lastDate)
    {
        /** @noinspection PhpUndefinedMethodInspection */
        $reply = parent::getNewPostsReply($thread, $lastDate);

        if ($reply instanceof View && ($posts = $reply->getParam('posts')))
        {
            $visitor = \XF::visitor();

            if ($visitor->user_id && $visitor->alerts_unread)
            {
                /** @var UserAlert $alertRepo */
                $alertRepo = $this->repository('XF:UserAlert');
                $alertRepo->markAlertsReadForContentIds('post', is_array($posts) ? \array_keys($posts) : $posts->keys(), null,200100);
            }
        }

        return $reply;
    }

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
        $alertRepo->markAlertsReadForContentIds('post', $contents->keys());

        return [$contents, $lastDate];
    }
}
