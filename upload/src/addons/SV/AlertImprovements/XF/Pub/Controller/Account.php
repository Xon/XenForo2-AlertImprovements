<?php

namespace SV\AlertImprovements\XF\Pub\Controller;

use SV\AlertImprovements\Globals;
use SV\AlertImprovements\XF\Entity\UserOption;
use SV\AlertImprovements\XF\Repository\UserAlert;
use XF\Entity\User;
use XF\Mvc\Entity\AbstractCollection;
use XF\Mvc\Reply\View;

class Account extends XFCP_Account
{
    protected function preferencesSaveProcess(User $visitor)
    {
        $form = parent::preferencesSaveProcess($visitor);

        $input = $this->filter(
            [
                'option' => [
                    'sv_alerts_page_skips_mark_read' => 'bool',
                    'sv_alerts_page_skips_summarize' => 'bool',
                    'sv_alerts_summarize_threshold'  => 'uint',
                ],
            ]
        );

        $userOptions = $visitor->getRelationOrDefault('Option');
        $form->setupEntityInput($userOptions, $input['option']);

        return $form;
    }

    public function actionSummarizeAlerts()
    {
        $options = \XF::options();
        if (empty($options->sv_alerts_summerize))
        {
            return $this->notFound();
        }

        $this->assertNotFlooding('alertSummarize', max(1, intval($options->sv_alerts_summerize_flood)));

        /** @var UserAlert $alertRepo */
        $alertRepo = $this->repository('XF:UserAlert');
        $alertRepo->summarizeAlertsForUser(\XF::visitor()->user_id);

        return $this->redirect($this->buildLink('account/alerts'));
    }

    public function actionAlert()
    {
        $visitor = \XF::visitor();
        $alertId = $this->filter('alert_id', 'int');
        $skipMarkAsRead = $this->filter('skip_mark_read', 'bool');
        $page = $this->filterPage();
        $perPage = $this->options()->alertsPerPage;

        /** @var UserAlert $alertRepo */
        $alertRepo = $this->repository('XF:UserAlert');
        if (!$skipMarkAsRead && $page === 1)
        {
            $alert = $alertRepo->changeAlertStatus($visitor, $alertId, true);
        }
        else
        {
            $alert = $alertRepo->findAlertForUser($visitor, $alertId)->fetchOne();
        }
        /** @var \SV\AlertImprovements\XF\Entity\UserAlert $alert */
        if (!$alert)
        {
            return $this->notFound();
        }

        /** @var \XF\Repository\UserAlert $alertRepo */
        $alertRepo = $this->repository('XF:UserAlert');

        Globals::$skipSummarize = true;
        Globals::$skipSummarizeFilter = true;
        try
        {
            $alertsFinder = $alertRepo->findAlertsForUser($visitor->user_id);
        }
        finally
        {
            Globals::$skipSummarize = false;
            Globals::$skipSummarizeFilter = false;
        }
        $alertsFinder->where('summerize_id', '=', $alertId);
        /** @var \XF\Entity\UserAlert[]|AbstractCollection $alerts */
        $alerts = $alertsFinder->limitByPage($page, $perPage)->fetch();

        $alertRepo->addContentToAlerts($alerts);
        $alerts = $alerts->filterViewable();

        if ($this->app->options()->sv_alerts_groupByDate)
        {
            $alerts = $this->groupAlertsByDay($alerts);
        }

        $viewParams = [
            'navParams' => ['alert_id' => $alert->alert_id],
            'alert'  => $alert,
            'alerts' => $alerts,

            'page'        => $page,
            'perPage'     => $perPage,
            'totalAlerts' => $alertsFinder->total()
        ];
        $view = $this->view('XF:Account\Alerts', 'account_alerts_summary', $viewParams);

        return $this->addAccountWrapperParams($view, 'alerts');
    }

    public function actionAlerts()
    {
        $visitor = \XF::visitor();
        /** @var UserOption $option */
        $option = $visitor->Option;
        $explicitSkipMarkAsRead = $this->request->exists('skip_mark_read') ? $this->filter('skip_mark_read', 'bool') : null;
        $explicitSkipSummarize = $this->request->exists('skip_summarize') ? $this->filter('skip_summarize', 'bool') : null;

        if (!empty($option->sv_alerts_page_skips_mark_read) && $explicitSkipMarkAsRead === null)
        {
            $this->request->set('skip_mark_read', 1);
        }

        $page = $this->filterPage();
        if ($page > 1 || !empty($option->sv_alerts_page_skips_summarize) || $explicitSkipSummarize)
        {
            Globals::$skipSummarize = true;
        }
        try
        {
            $response = parent::actionAlerts();
        }
        finally
        {
            Globals::$skipSummarize = false;
        }
        if ($response instanceof View)
        {
            $response->setParam('markedAlertsRead', Globals::$markedAlertsRead);

            if ($this->app->options()->sv_alerts_groupByDate)
            {
                /** @var \XF\Mvc\Entity\AbstractCollection $alerts */
                $alerts = $response->getParam('alerts');
                $newAlerts = $this->groupAlertsByDay($alerts);
                $response->setParam('alerts', $newAlerts);
            }
        }

        if ($explicitSkipMarkAsRead === false)
        {
            return $this->redirect($this->buildLink('account/alerts'));
        }

        return $response;
    }

    /**
     * @param \XF\Mvc\Entity\AbstractCollection $alerts
     * @return array
     */
    protected function groupAlertsByDay($alerts)
    {
        $newAlerts = [];
        $language = $this->app()->language(\XF::visitor()->language_id);
        $timeRef = $language->getDayStartTimestamps();

        /** @var \XF\Entity\UserAlert $alert */
        foreach ($alerts AS $alert)
        {
            $interval = $timeRef['now'] = $alert->event_date;
            list($date, $time) = $language->getDateTimeParts($alert->event_date);
            $groupedDate = $language->getRelativeDateTimeOutput($alert->event_date, $date, $time, false);

            if ($interval > -2)
            {
                if ($alert->event_date >= $timeRef['today'])
                {
                    $groupedDate = \XF::phrase('sv_alertimprovements_today')->render();
                }
                else if ($alert->event_date >= $timeRef['yesterday'])
                {
                    $groupedDate = \XF::phrase('sv_alertimprovements_yesterday')->render();
                }
            }
            $newAlerts[$groupedDate][$alert->alert_id] = $alert;
        }

        return $newAlerts;
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
