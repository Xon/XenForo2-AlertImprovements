<?php

namespace SV\AlertImprovements\XF\Pub\Controller;

use SV\AlertImprovements\Globals;
use SV\AlertImprovements\XF\Entity\UserOption;
use SV\AlertImprovements\XF\Repository\UserAlert as ExtendedUserAlertRepo;
use XF\Entity\User;
use XF\Mvc\Entity\AbstractCollection;
use XF\Mvc\Entity\ArrayCollection;
use XF\Mvc\ParameterBag;
use XF\Mvc\Reply\View;
use SV\AlertImprovements\XF\Entity\UserAlert as ExtendedUserAlertEntity;
use XF\Entity\UserAlert as UserAlertEntity;
use XF\Mvc\Reply\View as ViewReply;
use XF\Mvc\Reply\Redirect as RedirectReply;
use XF\Mvc\Reply\Exception as ExceptionReply;

/**
 * Class Account
 *
 * @package SV\AlertImprovements\XF\Pub\Controller
 */
class Account extends XFCP_Account
{
    public function actionAlertsMarkRead()
    {
        $visitor = \XF::visitor();

        /** @var ExtendedUserAlertRepo $alertRepo */
        $alertRepo = $this->repository('XF:UserAlert');

        $redirect = $this->getDynamicRedirect($this->buildLink('account/alerts',null, ['skip_mark_read' => 1]));

        if ($this->isPost())
        {
            $alertRepo->markUserAlertsRead($visitor);

            return $this->redirect($redirect, \XF::phrase('svAlertImprov_all_alerts_marked_as_read'));
        }

        $viewParams = [
            'redirect' => $redirect
        ];
        $view = $this->view(
            'SV\AlertImprovements\XF:Account\AlertsMarkRead',
            'svAlertImprov_account_alerts_mark_read',
            $viewParams
        );
        return $this->addAccountWrapperParams($view, 'alerts');
    }

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
     * @return \XF\Mvc\Reply\AbstractReply
     * @throws \XF\Mvc\Reply\Exception
     * @noinspection PhpUnusedParameterInspection
     */
    public function actionSummarizeAlerts(ParameterBag $params)
    {
        $options = \XF::options();
        if (empty($options->sv_alerts_summerize))
        {
            return $this->notFound();
        }

        $this->assertNotFlooding('alertSummarize', max(1, (int)$options->sv_alerts_summerize_flood));

        /** @var ExtendedUserAlertRepo $alertRepo */
        $alertRepo = $this->repository('XF:UserAlert');
        $alertRepo->summarizeAlertsForUser(\XF::visitor());

        return $this->redirect($this->buildLink('account/alerts'));
    }

    /**
     * @param ParameterBag $params
     * @return \XF\Mvc\Reply\AbstractReply
     * @noinspection PhpUnusedParameterInspection
     */
    public function actionAlert(ParameterBag $params)
    {
        $visitor = \XF::visitor();
        $alertId = $this->filter('alert_id', 'int');
        $skipMarkAsRead = $this->filter('skip_mark_read', 'bool');
        $page = $this->filterPage();
        $perPage = $this->options()->alertsPerPage;

        /** @var ExtendedUserAlertRepo $alertRepo */
        $alertRepo = $this->repository('XF:UserAlert');
        /** @var ExtendedUserAlertEntity $alert */
        $alert = $alertRepo->findAlertForUser($visitor, $alertId)->fetchOne();

        if ($alert && !$skipMarkAsRead && $page === 1 && $alert->auto_read)
        {
            $alertRepo->markUserAlertRead($alert);
        }
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
        /** @var UserAlertEntity[]|AbstractCollection $alerts */
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
     * @return \XF\Mvc\Reply\AbstractReply
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

        Globals::$showUnreadOnly = $this->filter('show_only', 'str') === 'unread';
        try
        {
            $response = parent::actionAlerts();
        }
        finally
        {
            Globals::$skipSummarize = false;
            Globals::$showUnreadOnly = false;
        }
        if ($response instanceof View)
        {
            if ($this->app->options()->sv_alerts_groupByDate)
            {
                /** @var AbstractCollection $alerts */
                $alerts = $response->getParam('alerts');
                $newAlerts = $this->groupAlertsByDay($alerts);
                $response->setParam('groupedAlerts', $newAlerts);
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
                /** @var ExtendedUserAlertRepo $alertRepo */
                $alertRepo = $this->repository('XF:UserAlert');
                $alertRepo->markUserAlertsReadForContent('user', $visitor->user_id);
            }
            Globals::$skipMarkAlertsRead = false;
        }

        if ($reply instanceof ViewReply)
        {
            if (\XF::options()->svUnreadAlertsAfterReadAlerts && ($alerts = $reply->getParam('alerts')))
            {
                $unreadAlerts = [];
                /** @var UserAlertEntity $alert */
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
        }

        return $reply;
    }

    protected function markInaccessibleAlertsReadIfNeeded(AbstractCollection $displayedAlerts = null)
    {
        // no-op this, as stock calls this stupidly often and loads all unread alerts without sanity checks...
    }

    /**
     * @param AbstractCollection|ExtendedUserAlertEntity[] $alerts
     */
    protected function groupAlertsByDay(AbstractCollection $alerts): array
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

        $language = \XF::language();
        $dayStartTimestamps = $language->getDayStartTimestamps();

        return $alerts->groupBy(function (UserAlertEntity $alert) use($language, $dayStartTimestamps, $dowTranslation)
        {
            $timestamp = $alert->event_date;
            /** @noinspection PhpUnusedLocalVariableInspection */
            list($date, $time) = $language->getDateTimeParts($timestamp);

            if ($timestamp >= $dayStartTimestamps['today'])
            {
                $groupedDate = \XF::phrase('sv_alertimprovements.today')->render();
            }
            else if ($timestamp >= $dayStartTimestamps['yesterday'])
            {
                $groupedDate = \XF::phrase('sv_alertimprovements.yesterday')->render();
            }
            else if ($timestamp >= $dayStartTimestamps['week'])
            {
                $dow = $dayStartTimestamps['todayDow'] - \ceil(($dayStartTimestamps['today'] - $timestamp) / 86400);
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

            return $groupedDate;
        });
    }

    /**
     * @param ParameterBag $params
     * @return \XF\Mvc\Reply\Redirect
     * @noinspection PhpUnusedParameterInspection
     */
    public function actionUnsummarizeAlert(ParameterBag $params)
    {
        $visitor = \XF::visitor();
        $alertId = $this->filter('alert_id', 'int');

        /** @var ExtendedUserAlertRepo $alertRepo */
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

    /**
     * Forcing this to return 404 not found because we support marking alerts read via the "checkboxes"
     * aka the inline moderation wrapper.
     *
     * @throws ExceptionReply
     */
    public function actionAlertToggle()
    {
        throw $this->exception($this->notFound());
    }

    /**
     * @throws ExceptionReply
     */
    public function actionBulkUpdateAlerts() : RedirectReply
    {
        $this->assertPostOnly();

        $visitor = \XF::visitor();
        $alertIds = $this->filter('alert_ids', 'array-uint');
        $alertIds = \array_slice($alertIds, 0, $this->options()->alertsPerPage); // in case some genius passes a very long list of alert ids :>
        if (!\count($alertIds))
        {
            throw $this->exception($this->error(\XF::phrase('svAlertImprov_please_select_at_least_one_alert_to_update')));
        }

        /** @var ExtendedUserAlertRepo $alertRepo */
        $alertRepo = $this->repository('XF:UserAlert');
        switch ($this->filter('state', 'str'))
        {
            case 'read':
                $alertRepo->markAlertIdsAsReadAndViewed($visitor, $alertIds, \XF::$time);
                break;

            case 'unread':
                $alertRepo->markAlertIdsAsUnreadAndUnviewed($visitor, $alertIds);
                break;

            default:
                throw $this->exception($this->error(\XF::phrase('svAlertImprov_please_select_valid_action_to_perform')));
        }

        return $this->redirect(
            $this->getDynamicRedirect($this->buildLink('account/alerts')),
            \XF::phrase('svAlertImprov_selected_alerts_have_been_updated')
        );
    }
}
