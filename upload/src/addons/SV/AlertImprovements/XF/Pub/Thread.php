<?php

namespace SV\AlertImprovements\XF\Pub;

use XF\Mvc\ParameterBag;
use XF\Mvc\FormAction;
use XF\Mvc\Reply\View;

class Thread extends XFCP_Thread
{
	public function actionIndex(ParameterBag $params)
	{
		$reply = parent::actionIndex($params);

		if ($reply instanceof \XF\Mvc\Reply\View && !empty($posts = $reply->getParam('posts')))
		{
			$visitor = \XF::visitor();

			if ($visitor->user_id && $visitor->alerts_unread)
			{
				$contentIds = $posts->keys();
				$contentType = 'post';

				/** @var \XF\Repository\UserAlert $alertRepo */
				$alertRepo = $this->repository('XF:UserAlert');
				$alertRepo->markAlertsReadForContentIds($contentType, $contentIds);
			}
		}

		return $reply;
	}

	public function getNewPostsReply(\XF\Entity\Thread $thread, $lastDate)
	{
		$reply = parent::getNewPostsReply($thread, $lastDate);

		if ($reply instanceof \XF\Mvc\Reply\View && !empty($posts = $reply->getParam('posts')))
		{
			$visitor = \XF::visitor();

			if ($visitor->user_id && $visitor->alerts_unread)
			{
				$contentIds = $posts->keys();
				$contentType = 'post';

				/** @var \XF\Repository\UserAlert $alertRepo */
				$alertRepo = $this->repository('XF:UserAlert');
				$alertRepo->markAlertsReadForContentIds($contentType, $contentIds);
			}
		}

		return $reply;
	}
}