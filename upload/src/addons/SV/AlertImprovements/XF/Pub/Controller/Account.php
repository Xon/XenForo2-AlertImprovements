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
        $explicitSkipMarkAsRead = $this->request->exists('skip_mark_read') ? $this->filter('skip_mark_read', 'bool') : null;
        $explicitSkipSummarize = $this->request->exists('skip_summarize') ? $this->filter('skip_summarize', 'bool') : null;

        if (!empty($visitor->Option->sv_alerts_page_skips_mark_read) && $explicitSkipMarkAsRead === null)
        {
            $this->request->set('skip_mark_read', 1);
        }

        $page = $this->filterPage();
        if ($page > 1 || !empty($visitor->Option->sv_alerts_page_skips_summarize) || $explicitSkipSummarize)
        {
            Globals::$skipSummarize = true;
        }

        $response = parent::actionAlerts();
        if ($response instanceof View)
        {
            $response->setParam('markedAlertsRead', Globals::$markedAlertsRead);
        }

        if ($explicitSkipMarkAsRead === false)
        {
            return $this->redirect($this->buildLink('account/alerts'));
        }

        return $response;
    }

    public function actionUnreadAlert()
    {
        $visitor = \XF::visitor();
        $alertId = $this->filter('alert_id', 'int');

        /** @var UserAlert $alertRepo */
        $alertRepo = $this->repository('XF:UserAlert');
        $alertRepo->changeAlertStatus($visitor, $alertId, false);

        $params = [
            'skip_mark_read' => true,
        ];

        return $this->redirect(
            $this->buildLink(
                'account/alerts', [], $params
            )
        );
    }

    public function actionUnsummarizeAlert()
    {
        $visitor = \XF::visitor();
        $alertId = $this->filter('alert_id', 'int');

        /** @var UserAlert $alertRepo */
        $alertRepo = $this->repository('XF:UserAlert');
        $alertRepo->insertUnsummarizedAlerts($visitor, $alertId);

        $params = [
            'skip_mark_read' => true,
            'skip_summarize' => true
        ];

        return $this->redirect(
            $this->buildLink(
                'account/alerts', [], $params
            )
        );
    }
}
