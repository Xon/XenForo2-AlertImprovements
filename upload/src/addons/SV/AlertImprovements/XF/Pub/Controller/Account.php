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
     * @throws ExceptionReply
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

        return $this->redirect($this->buildLink('account/alerts', null, ['show_only' => 'all']));
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
        $options = $this->options();
        $perPage = $options->alertsPerPage;

        /** @var ExtendedUserAlertRepo $alertRepo */
        $alertRepo = $this->repository('XF:UserAlert');
        /** @var ExtendedUserAlertEntity $alert */
        $alert = $alertRepo->findAlertForUser($visitor, $alertId)->fetchOne();

        if ($alert && !$skipMarkAsRead && $page === 1 && $alert->auto_read)
        {
            $alertRepo->markUserAlertRead($alert);
        }
        if (!$alert || !$alert->canView())
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
        $alertRepo->autoMarkUserAlertsRead($alerts, $visitor);
        $alerts = $alerts->filterViewable();

        $groupedAlerts = !empty($options->sv_alerts_groupByDate) ? $this->groupAlertsByDay($alerts) : null;

        $viewParams = [
            'navParams'     => ['alert_id' => $alert->alert_id],
            'alert'         => $alert,
            'alerts'        => $alerts,
            'groupedAlerts' => $groupedAlerts,

            'page'        => $page,
            'perPage'     => $perPage,
            'totalAlerts' => $alertsFinder->total(),
        ];
        $view = $this->view('XF:Account\Alerts', 'svAlertsImprov_account_alerts_summary', $viewParams);

        return $this->addAccountWrapperParams($view, 'alerts');
    }

    protected function hasRecentlySummarizedAlerts(): bool
    {
        $options = \XF::options();
        if (empty($options->sv_alerts_summerize))
        {
            return true;
        }
        $floodingLimit = max(1, isset($options->sv_alerts_summerize_flood) ? $options->sv_alerts_summerize_flood : 1);

        $visitor = \XF::visitor();
        if ($visitor->hasPermission('general', 'bypassFloodCheck'))
        {
            return false;
        }

        /** @var \XF\Service\FloodCheck $floodChecker */
        $floodChecker = $this->service('XF:FloodCheck');
        $timeRemaining = $floodChecker->checkFlooding('alertSummarize', $visitor->user_id, $floodingLimit);

        return $timeRemaining > 0;
    }

    /**
     * @return \XF\Mvc\Reply\AbstractReply
     */
    public function actionAlerts()
    {
        $visitor = \XF::visitor();
        /** @var UserOption $option */
        $option = $visitor->Option;
        $showOnlyFilter = $this->filter('show_only', '?str');
        $skipMarkAsRead = $this->filter('skip_mark_read', '?bool');
        $skipSummarize = $this->filter('skip_summarize', '?bool');
        $page = $this->filterPage();
        $options = $this->options();
        if (Globals::isPrefetchRequest())
        {
            $skipMarkAsRead = $skipSummarize = true;
        }
        if (!empty($option->sv_alerts_page_skips_mark_read))
        {
            $skipMarkAsRead = true;
        }
        if ($page > 1 || !empty($option->sv_alerts_page_skips_summarize))
        {
            $skipSummarize = true;
        }

        // defaults
        $skipMarkAsRead = $skipMarkAsRead ?? false;
        $skipSummarize = $skipSummarize ?? false;
        $showOnlyFilter = $showOnlyFilter ?? ($visitor->alerts_unread ? 'unread' : 'all');

        // make XF mark-alert handling sane
        $this->request->set('skip_mark_read', 1);
        Globals::$skipMarkAlertsRead = true;
        Globals::$skipSummarize = $skipSummarize || $this->hasRecentlySummarizedAlerts();
        Globals::$showUnreadOnly = $showOnlyFilter === 'unread';
        try
        {
            $response = parent::actionAlerts();
        }
        finally
        {
            Globals::$skipMarkAlertsRead = false;
            Globals::$skipSummarize = false;
            Globals::$showUnreadOnly = false;
        }
        if ($response instanceof View)
        {
            /** @var AbstractCollection|UserAlertEntity[] $alerts */
            $alerts = $response->getParam('alerts');
            if ($alerts)
            {
                $this->markViewedAlertsRead($alerts, $skipMarkAsRead);

                $groupedAlerts = empty($options->sv_alerts_groupByDate) ? null : $this->groupAlertsByDay($alerts);

                $response->setParam('groupedAlerts', $groupedAlerts);
            }

            $navParams = $response->getParam('navParams') ?? [];
            $navParams = array_merge([
                'skip_mark_read' => $skipMarkAsRead,
                'skip_summarize' => $skipSummarize,
                'show_only'      => $showOnlyFilter,
            ], $navParams);
            $response->setParam('navParams', $navParams);
        }

        return $response;
    }

    public function actionAlertsPopup()
    {
        $visitor = \XF::visitor();
        /** @var UserOption $option */
        $option = $visitor->Option;
        $skipMarkAsRead = Globals::isPrefetchRequest() || !empty($option->sv_alerts_popup_skips_mark_read);
        Globals::$skipMarkAlertsRead = true;
        Globals::$skipSummarize = $this->hasRecentlySummarizedAlerts();
        try
        {
            $reply = parent::actionAlertsPopup();
        }
        finally
        {
            Globals::$skipMarkAlertsRead = false;
            Globals::$skipSummarize = false;
        }

        if ($reply instanceof ViewReply)
        {
            /** @var AbstractCollection|UserAlertEntity[] $alerts */
            $alerts = $reply->getParam('alerts');
            if ($alerts)
            {
                $this->markViewedAlertsRead($alerts, $skipMarkAsRead);

                if (\XF::options()->svUnreadAlertsAfterReadAlerts)
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
                        $reply->setParam('unreadAlerts', new ArrayCollection($unreadAlerts));
                    }
                }
            }

            // just use svAlertsImprov_account_alerts_popup
            if ($reply->getTemplateName() === 'account_alerts_popup')
            {
                $reply->setTemplateName('svAlertsImprov_account_alerts_popup');
            }
        }

        return $reply;
    }

    /**
     * @param AbstractCollection|UserAlertEntity[] $alerts
     * @param bool $skipMarkAsRead
     */
    protected function markViewedAlertsRead($alerts, bool $skipMarkAsRead)
    {
        $visitor = \XF::visitor();
        if ($skipMarkAsRead)
        {
            // if skipping alerts read, ensure non-auto read user-alerts are read anyway, otherwise they don't go away as expected
            if ($visitor->alerts_unread)
            {
                /** @var ExtendedUserAlertRepo $alertRepo */
                $alertRepo = $this->repository('XF:UserAlert');
                $alertRepo->markAlertsReadForContentIds('user', [$visitor->user_id], null, 0, $visitor, null, true);
            }
        }
        else
        {
            /** @var ExtendedUserAlertRepo $alertRepo */
            $alertRepo = $this->repository('XF:UserAlert');
            $alertRepo->autoMarkUserAlertsRead($alerts, $visitor);
        }
    }

    protected function markInaccessibleAlertsReadIfNeeded(AbstractCollection $displayedAlerts = null)
    {
        // no-op this, as stock calls this stupidly often and loads all unread alerts without sanity checks...
    }

    /**
     * @param AbstractCollection|ExtendedUserAlertEntity[] $alerts
     * @return array
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
     * @return RedirectReply
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

        $redirectParams = $this->filter([
            'show_only'      => '?str',
            'skip_mark_read' => '?bool',
            'skip_summarize' => '?bool',
            'page'           => 'int',
        ]);

        /** @var ExtendedUserAlertRepo $alertRepo */
        $alertRepo = $this->repository('XF:UserAlert');
        switch ($this->filter('state', 'str'))
        {
            case 'read':
                $alertRepo->markAlertIdsAsReadAndViewed($visitor, $alertIds, \XF::$time);
                break;

            case 'unread':
                $alertRepo->markAlertIdsAsUnreadAndUnviewed($visitor, $alertIds, true);
                break;

            default:
                throw $this->exception($this->error(\XF::phrase('svAlertImprov_please_select_valid_action_to_perform')));
        }

        return $this->redirect(
            $this->buildLink('account/alerts', null, $redirectParams),
            \XF::phrase('svAlertImprov_selected_alerts_have_been_updated')
        );
    }
}
