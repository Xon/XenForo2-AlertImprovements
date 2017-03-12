<?php

namespace SV\AlertImprovements\XF\Pub;

use XF\Mvc\ParameterBag;
use XF\Mvc\FormAction;
use XF\Mvc\Reply\View;

class Account extends XFCP_Account
{
	protected function preferencesSaveProcess(\XF\Entity\User $visitor)
	{
		$form = parent::preferencesSaveProcess($visitor);

		$input = $this->filter([
			'option' => [
				'sv_alerts_page_skips_mark_read' => 'uint',
			],
		]);

		$userOptions = $visitor->getRelationOrDefault('Option');
		$form->setupEntityInput($userOptions, $input['option']);

		return $form;
	}

	public function actionAlerts()
	{
		$reply = parent::actionAlerts();

		return $reply;
	}

	public function actionUnreadAlert(ParameterBag $params)
	{
		$visitor = \XF::visitor();

		/** @var \XF\Repository\UserAlert $alertRepo */
		$alertRepo = $this->repository('XF:UserAlert');
		$alertRepo->markAlertUnread($visitor, $params->alert_id);

		$reply = $this->redirect($this->buildLink(
			'account/alerts', [], ['skip_mark_read' => true]
		));

		return $reply;
	}
}