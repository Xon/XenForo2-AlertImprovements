<?php

namespace SV\AlertImprovements\XF\Pub\Controller;

use SV\AlertImprovements\Globals;
use SV\AlertImprovements\XF\Repository\UserAlert;
use XF\Db\Exception;
use XF\Mvc\ParameterBag;
use XF\Mvc\Reply\View;

class Account extends XFCP_Account
{
    protected function preferencesSaveProcess(\XF\Entity\User $visitor)
    {
        $form = parent::preferencesSaveProcess($visitor);

        $input = $this->filter(
            [
                'option' => [
                    'sv_alerts_page_skips_mark_read' => 'bool',
                    'sv_alerts_page_skips_summarize' => 'bool',
                    'sv_alerts_summarize_threshold' => 'uint',
                ],
            ]
        );

        $userOptions = $visitor->getRelationOrDefault('Option');
        $form->setupEntityInput($userOptions, $input['option']);

        return $form;
    }

    public function actionAlerts()
    {
        $visitor = \XF::visitor();
        $explicitMarkAsRead = $this->request->exists('skip_mark_read') ? $this->filter('skip_mark_read', 'bool') : null;
        $explicitSkipSummarize = $this->request->exists('skip_summarize') ? $this->filter('skip_summarize', 'bool') : null;

        if (!empty($visitor->Option->sv_alerts_page_skips_mark_read) && $explicitMarkAsRead === null)
        {
            $this->request->set('skip_mark_read', 1);
        }

        if (!empty($visitor->Option->sv_alerts_page_skips_summarize) || $explicitSkipSummarize)
        {
            Globals::$skipSummarize = true;
        }

        $page = $this->filterPage();
        Globals::$skipSummarize = $page > 1;

        $response = parent::actionAlerts();
        if ($response instanceof View)
        {
            $response->setParam('markedAlertsRead', Globals::$markedAlertsRead);
        }

        if ($explicitMarkAsRead)
        {
            return $this->redirect($this->buildLink('account/alerts'));
        }

        return $response;
    }

    public function actionUnreadAlert(ParameterBag $params)
    {
        $visitor = \XF::visitor();
        $alertId = $this->filter('alert_id', 'int');

        /** @var UserAlert $alertRepo */
        $alertRepo = $this->repository('XF:UserAlert');
        $alertRepo->changeAlertStatus($visitor, $alertId, false);

        $reply = $this->redirect(
            $this->buildLink(
                'account/alerts', [], ['skip_mark_read' => true]
            )
        );

        return $reply;
    }
}
