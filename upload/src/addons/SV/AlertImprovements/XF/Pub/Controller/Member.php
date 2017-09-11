<?php

namespace SV\AlertImprovements\XF\Pub\Controller;

use SV\AlertImprovements\XF\Repository\UserAlert;
use XF\Mvc\ParameterBag;

class Member extends XFCP_Member
{
    public function actionView(ParameterBag $params)
    {
        $reply = parent::actionView($params);

        if ($reply instanceof \XF\Mvc\Reply\View && !empty($profilePosts = $reply->getParam('profilePosts')))
        {
            $visitor = \XF::visitor();

            if ($visitor->user_id && $visitor->alerts_unread)
            {
                $profilePostIds = $profilePosts->keys();
                $contentType = 'profile_post';

                /** @var UserAlert $alertRepo */
                $alertRepo = $this->repository('XF:UserAlert');
                $alertRepo->markAlertsReadForContentIds($contentType, $profilePostIds);

                $contentType = 'profile_post_comment';
                $contentIds = [];

                foreach ($profilePosts AS $profilePost)
                {
                    if ($commentIds = $profilePost->latest_comment_ids)
                    {
                        foreach ($commentIds AS $commentId => $state)
                        {
                            $commentId = intval($commentId);
                            $contentIds[] = $commentId;
                        }
                    }
                }

                $alertRepo->markAlertsReadForContentIds($contentType, $contentIds);
            }
        }

        return $reply;
    }
}
