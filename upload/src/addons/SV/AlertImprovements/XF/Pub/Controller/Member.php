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
     * @return \XF\Mvc\Reply\Reroute|View
     */
    public function actionView(ParameterBag $params)
    {
        $reply = parent::actionView($params);

        if (\XF::$versionId < 2010000 &&
            $reply instanceof View && ($profilePosts = $reply->getParam('profilePosts')))
        {
            $visitor = \XF::visitor();

            if ($visitor->user_id && $visitor->alerts_unread)
            {
                /** @var UserAlert $alertRepo */
                $alertRepo = $this->repository('XF:UserAlert');
                $alertRepo->markAlertsReadForContentIds('profile_post', $profilePosts->keys(), null, 2010000);

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

                $alertRepo->markAlertsReadForContentIds('profile_post_comment', $contentIds, null, 2010000);
            }
        }

        return $reply;
    }
}