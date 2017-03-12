<?php

namespace SV\AlertImprovements\XF\Pub;

use XF\Mvc\ParameterBag;
use XF\Mvc\FormAction;
use XF\Mvc\Reply\View;

class Conversation extends XFCP_Conversation
{
	public function actionView(ParameterBag $params)
	{
		$reply = parent::actionView($params);

		// todo: conv ess??

		return $reply;
	}
}