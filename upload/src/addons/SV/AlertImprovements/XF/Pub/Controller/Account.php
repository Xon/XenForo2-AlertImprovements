<?php

namespace SV\AlertImprovements\XF\Pub\Controller;

use SV\AlertImprovements\Globals;
use SV\AlertImprovements\XF\Entity\UserOption;
use SV\AlertImprovements\XF\Repository\UserAlert;
use XF\Entity\User;
use XF\Mvc\Entity\AbstractCollection;
use XF\Mvc\Entity\ArrayCollection;
use XF\Mvc\ParameterBag;
use XF\Mvc\Reply\View;

/**
 * Class Account
 *
 * @package SV\AlertImprovements\XF\Pub\Controller
 */
class Account extends XFCP_Account
{
    /**
     * @param User $visitor
     * @return \XF\Mvc\FormAction
     */
    protected function preferencesSaveProcess(User $visitor)
    {
        $form = parent::preferencesSaveProcess($visitor);

        $input = $this->filter(
            [
                'option' => [
                    'sv_alerts_popup_skips_mark_read' => 'bool',
                    'sv_alerts_page_skips_mark_read'  => 'bool',
                    'sv_alerts_page_skips_summarize'  => 'bool',
                    'sv_alerts_summarize_threshold'   => 'uint',
                ],
            ]
        );

        $userOptions = $visitor->getRelationOrDefault('Option');
        $form->setupEntityInput($userOptions, $input['option']);

        return $form;
    }

    /**
     * @param ParameterBag $params
     * @return \XF\Mvc\Reply\Redirect
     * @throws \XF\Mvc\Reply\Exception
     */
    public function actionSummarizeAlerts(/** @noinspection PhpUnusedParameterInspection */ ParameterBag $params)
    {
        $options = \XF::options();
        if (empty($options->sv_alerts_summerize))
        {
            return $this->notFound();
        }

        $this->assertNotFlooding('alertSummarize', max(1, (int)$options->sv_alerts_summerize_flood));

        /** @var UserAlert $alertRepo */
        $alertRepo = $this->repository('XF:UserAlert');
        $alertRepo->summarizeAlertsForUser(\XF::visitor()->user_id);

        return $this->redirect($this->buildLink('account/alerts'));
    }

    /**
     * @param ParameterBag $params
     * @return View
     */
    public function actionAlert(/** @noinspection PhpUnusedParameterInspection */ ParameterBag $params)
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
            'alert'     => $alert,
            'alerts'    => $alerts,

            'page'        => $page,
            'perPage'     => $perPage,
            'totalAlerts' => $alertsFinder->total(),
        ];
        $view = $this->view('XF:Account\Alerts', 'svAlertsImprov_account_alerts_summary', $viewParams);

        return $this->addAccountWrapperParams($view, 'alerts');
    }

    /**
     * @return \XF\Mvc\Reply\Redirect|View
     */
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
                /** @var AbstractCollection $alerts */
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

    public function actionAlertsPopup()
    {
        $visitor = \XF::visitor();
        /** @var UserOption $option */
        $option = $visitor->Option;
        if ($option->sv_alerts_popup_skips_mark_read)
        {
            Globals::$skipMarkAlertsRead = true;
        }
        try
        {
            $reply = parent::actionAlertsPopup();
        }
        finally
        {
            // if skipping alerts read, ensure user-alerts are read anyway, otherwise they don't go away as expected
            if ($visitor->alerts_unread && Globals::$skipMarkAlertsRead)
            {
                /** @var UserAlert $alertRepo */
                $alertRepo = $this->repository('XF:UserAlert');
                $alertRepo->markUserAlertsReadForContent('user', $visitor->user_id);
            }
            Globals::$skipMarkAlertsRead = false;
        }

        if (\XF::options()->svUnreadAlertsAfterReadAlerts &&
            $reply instanceof View &&
            ($alerts = $reply->getParam('alerts')))
        {
            $unreadAlerts = [];
            /** @var \XF\Entity\UserAlert $alert */
            foreach ($alerts as $key => $alert)
            {
                if (!$alert->view_date)
                {
                    $unreadAlerts[$key] = $alert;
                    unset($alerts[$key]);
                }
            }

            if ($unreadAlerts)
            {
                $reply->setTemplateName('svAlertsImprov_account_alerts_popup');
                $reply->setParam('unreadAlerts', new ArrayCollection($unreadAlerts));
            }
        }

        return $reply;
    }

    /**
     * @param AbstractCollection $alerts
     * @return array
     */
    protected function groupAlertsByDay($alerts)
    {
        $dowTranslation = [
            0 => 'sunday',
            1 => 'monday',
            2 => 'tuesday',
            3 => 'wednesday',
            4 => 'thursday',
            5 => 'friday',
            6 => 'saturday',
        ];

        $newAlerts = [];
        $language = $this->app()->language(\XF::visitor()->language_id);
        $timeRef = $language->getDayStartTimestamps();

        /** @var \XF\Entity\UserAlert $alert */
        foreach ($alerts AS $alert)
        {
            $timestamp = $alert->event_date;
            /** @noinspection PhpUnusedLocalVariableInspection */
            list($date, $time) = $language->getDateTimeParts($timestamp);

            if ($timestamp >= $timeRef['today'])
            {
                $groupedDate = \XF::phrase('sv_alertimprovements.today')->render();
            }
            else if ($timestamp >= $timeRef['yesterday'])
            {
                $groupedDate = \XF::phrase('sv_alertimprovements.yesterday')->render();
            }
            else if ($timestamp >= $timeRef['week'])
            {
                $dow = $timeRef['todayDow'] - ceil(($timeRef['today'] - $timestamp) / 86400);
                if ($dow < 0)
                {
                    $dow += 7;
                }

                $groupedDate = \XF::phrase('day_' . $dowTranslation[$dow])->render();
            }
            else
            {
                $groupedDate = $date;
            }


            $newAlerts[$groupedDate][$timestamp] = $alert;
        }

        return $newAlerts;
    }

    /**
     * @param ParameterBag $params
     * @return \XF\Mvc\Reply\Redirect
     */
    public function actionUnreadAlert(/** @noinspection PhpUnusedParameterInspection */ ParameterBag $params)
    {
        $visitor = \XF::visitor();
        $alertId = $this->filter('alert_id', 'int');

        /** @var UserAlert $alertRepo */
        $alertRepo = $this->repository('XF:UserAlert');
        $alertRepo->changeAlertStatus($visitor, $alertId, false);

        $linkParams = [
            'skip_mark_read' => true,
        ];

        return $this->redirect(
            $this->buildLink(
                'account/alerts', [], $linkParams
            )
        );
    }

    /**
     * @param ParameterBag $params
     * @return \XF\Mvc\Reply\Redirect
     */
    public function actionUnsummarizeAlert(/** @noinspection PhpUnusedParameterInspection */ ParameterBag $params)
    {
        $visitor = \XF::visitor();
        $alertId = $this->filter('alert_id', 'int');

        /** @var UserAlert $alertRepo */
        $alertRepo = $this->repository('XF:UserAlert');
        $alertRepo->insertUnsummarizedAlerts($visitor, $alertId);

        $linkParams = [
            'skip_mark_read' => true,
            'skip_summarize' => true,
        ];

        return $this->redirect(
            $this->buildLink(
                'account/alerts', [], $linkParams
            )
        );
    }
}
