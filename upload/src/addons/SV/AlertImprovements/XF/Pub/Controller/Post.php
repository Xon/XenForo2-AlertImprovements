<?php

namespace SV\AlertImprovements\XF\Pub\Controller;

use SV\AlertImprovements\XF\Repository\UserAlert;
use SV\StandardLib\Helper;
use XF\Mvc\ParameterBag;
use XF\Mvc\Reply\AbstractReply;
use XF\Mvc\Reply\View;
use XF\Repository\UserAlert as UserAlertRepo;

/**
 * Extends \XF\Pub\Controller\Post
 */
class Post extends XFCP_Post
{
    /**
     * @param ParameterBag $params
     * @return AbstractReply
     */
    public function actionEdit(ParameterBag $params)
    {
        $reply = parent::actionEdit($params);

        if ($this->isPost() &&
            $reply instanceof View &&
            ($post = $reply->getParam('post')))
        {
            /** @var \XF\Entity\Post $post */
            $visitor = \XF::visitor();

            if ($visitor->user_id && $visitor->alerts_unread)
            {
                /** @var UserAlert $alertRepo */
                $alertRepo = Helper::repository(UserAlertRepo::class);
                $alertRepo->markAlertsReadForContentIds('post', [$post->post_id]);
            }
        }

        return $reply;
    }
}