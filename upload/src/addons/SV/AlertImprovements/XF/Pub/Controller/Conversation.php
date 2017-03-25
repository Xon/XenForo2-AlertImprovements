<?php

namespace SV\AlertImprovements\XF\Pub\Controller;

use XF\Mvc\ParameterBag;
use XF\Mvc\FormAction;
use XF\Mvc\Reply\View;

class Conversation extends XFCP_Conversation
{
	public function actionView(ParameterBag $params)
	{
		$reply = parent::actionView($params);

		if ($reply instanceof \XF\Mvc\Reply\View && !empty($messages = $reply->getParam('messages')))
		{
			$visitor = \XF::visitor();

			if ($visitor->user_id && $visitor->alerts_unread)
			{
				$contentIds = $messages->keys();
				$contentType = 'conversation_message';

				/** @var \XF\Repository\UserAlert $alertRepo */
				$alertRepo = $this->repository('XF:UserAlert');
				$alertRepo->markAlertsReadForContentIds($contentType, $contentIds);
			}
		}

		return $reply;
	}
}