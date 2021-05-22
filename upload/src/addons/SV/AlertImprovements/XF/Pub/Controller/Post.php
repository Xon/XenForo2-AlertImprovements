<?php

namespace SV\AlertImprovements\XF\Pub\Controller;



use SV\AlertImprovements\XF\Entity\User as ExtendedUserEntity;
use SV\AlertImprovements\XF\Repository\UserAlert;
use XF\Mvc\ParameterBag;
use XF\Mvc\Reply\View;

/**
 * Extends \XF\Pub\Controller\Post
 */
class Post extends XFCP_Post
{
    public function actionEdit(ParameterBag $params)
    {
        $reply = parent::actionEdit($params);

        if ($this->isPost() &&
            $reply instanceof View &&
            ($post = $reply->getParam('post')))
        {
            /** @var \XF\Entity\Post @post */
            /** @var ExtendedUserEntity $visitor */
            $visitor = \XF::visitor();

            if ($visitor->user_id && $visitor->alerts_unread)
            {
                /** @var UserAlert $alertRepo */
                $alertRepo = $this->repository('XF:UserAlert');
                $alertRepo->markAlertsReadForContentIds('post', [$post->post_id]);
            }
        }

        return $reply;
    }
}