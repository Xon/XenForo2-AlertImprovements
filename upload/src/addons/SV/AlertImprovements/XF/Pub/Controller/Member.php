<?php

namespace SV\AlertImprovements\XF\Pub\Controller;

use SV\AlertImprovements\XF\Repository\UserAlert;
use XF\Mvc\ParameterBag;
use XF\Mvc\Reply\View;

/**
 * Class Member
 *
 * @package SV\AlertImprovements\XF\Pub\Controller
 */
class Member extends XFCP_Member
{
    /**
     * @param ParameterBag $params
     *
     * @return \XF\Mvc\Reply\Reroute|View
     */
    public function actionView(ParameterBag $params)
    {
        $reply = parent::actionView($params);

        if ($reply instanceof View && ($profilePosts = $reply->getParam('profilePosts')))
        {
            $visitor = \XF::visitor();

            if ($visitor->user_id && $visitor->alerts_unread)
            {
                $profilePostIds = $profilePosts->keys();
                $contentType = 'profile_post';

                /** @var UserAlert $alertRepo */
                $alertRepo = $this->repository('XF:UserAlert');
                $alertRepo->markAlertsReadForContentIds($contentType, $profilePostIds, null, 2010000);

                $contentType = 'profile_post_comment';
                $contentIds = [];

                foreach ($profilePosts AS $profilePost)
                {
                    if ($commentIds = $profilePost->latest_comment_ids)
                    {
                        foreach ($commentIds AS $commentId => $state)
                        {
                            $commentId = (int)$commentId;
                            $contentIds[] = $commentId;
                        }
                    }
                }

                $alertRepo->markAlertsReadForContentIds($contentType, $contentIds, null, 2010000);
            }
        }

        return $reply;
    }
}