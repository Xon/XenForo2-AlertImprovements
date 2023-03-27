<?php
/**
 * @noinspection PhpMissingReturnTypeInspection
 */

namespace SV\AlertImprovements\XF\Pub\Controller;

use SV\AlertImprovements\ControllerPlugin\AlertAction;
use SV\AlertImprovements\Globals;
use SV\AlertImprovements\XF\Repository\UserAlert as ExtendedUserAlertRepo;
use XF\Entity\User;
use XF\Mvc\Entity\AbstractCollection;
use XF\Mvc\Entity\ArrayCollection;
use XF\Mvc\ParameterBag;
use XF\Mvc\Reply\AbstractReply;
use XF\Mvc\Reply\View;
use SV\AlertImprovements\XF\Entity\UserAlert as ExtendedUserAlertEntity;
use SV\AlertImprovements\XF\Entity\User as ExtendedUserEntity;
use XF\Mvc\Reply\View as ViewReply;
use XF\Mvc\Reply\Redirect as RedirectReply;
use XF\Mvc\Reply\Exception as ExceptionReply;
use function array_slice, count, max, array_merge;

/**
 * Class Account
 *
 * @package SV\AlertImprovements\XF\Pub\Controller
 */
class Account extends XFCP_Account
{
    public function actionAlertsMarkRead()
    {
        /** @var ExtendedUserEntity $visitor */
        $visitor = \XF::visitor();

        /** @var ExtendedUserAlertRepo $alertRepo */
        $alertRepo = $this->repository('XF:UserAlert');

        $redirect = $this->getDynamicRedirect($this->buildLink('account/alerts'));

        if ($this->isPost())
        {
            $alertRepo->markUserAlertsRead($visitor);

            $inlist = $this->filter('inlist', 'bool');
            $alertIds = $this->filter('alert_ids', 'array-uint');
            if ($alertIds)
            {
                /** @var \XF\Entity\UserAlert[]|AbstractCollection $alerts */
                $alerts = $alertRepo->findAlertByIdsForUser($visitor->user_id, $alertIds)
                                    ->limit(\XF::options()->alertsPerPage * 2)
                                    ->fetch();
                $alertRepo->addContentToAlerts($alerts);
                $alerts = $alerts->filterViewable();

                $viewParams = [
                    'alerts'             => $alerts,
                    'showSelectCheckbox' => $inlist,
                    'inAlertsPopup'      => !$inlist,
                ];

                return $this->view('XF:Account\Alert', 'svAlertImprov_alerts', $viewParams);
            }

            return $this->redirect($redirect, \XF::phrase('svAlertImprov_all_alerts_marked_as_read'));
        }

        $viewParams = [
            'redirect' => $redirect,
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
     * @return AbstractReply
     * @throws ExceptionReply
     * @noinspection PhpUnusedParameterInspection
     */
    public function actionSummarizeAlerts(ParameterBag $params)
    {
        $options = \XF::options();
        if (empty($options->svAlertsSummarize))
        {
            return $this->notFound();
        }

        $floodingLimit = max(1, $options->svAlertsSummarizeFlood ?? 1);
        $this->assertNotFlooding('alertSummarize', $floodingLimit);

        /** @var ExtendedUserAlertRepo $alertRepo */
        $alertRepo = $this->repository('XF:UserAlert');
        // summarize & mark as read as of now
        $alertRepo->summarizeAlertsForUser(\XF::visitor(), \XF::$time);

        return $this->redirect($this->buildLink('account/alerts', null, ['show_only' => 'all']));
    }

    /**
     * @param ParameterBag $params
     * @return AbstractReply
     */
    public function actionAlert(ParameterBag $params)
    {
        /** @var ExtendedUserEntity $visitor */
        $visitor = \XF::visitor();
        $alert = $this->assertViewableAlert((int)$params->get('alert_id'));

        $skipMarkAsRead = $this->filter('skip_mark_read', 'bool');
        $page = $this->filterPage();
        $options = $this->options();
        $perPage = $options->alertsPerPage;
        /** @var ExtendedUserAlertRepo $alertRepo */
        $alertRepo = $this->repository('XF:UserAlert');

        if (!$skipMarkAsRead && $page === 1 && $alert->auto_read)
        {
            $alertRepo->markUserAlertRead($alert);
        }

        /** @var ExtendedUserAlertRepo $alertRepo */
        $alertRepo = $this->repository('XF:UserAlert');

        Globals::$forSummarizedAlertView = true;
        try
        {
            $alertsFinder = $alertRepo->findAlertsForUser($visitor->user_id);
        }
        finally
        {
            Globals::$forSummarizedAlertView = false;
        }
        $alertsFinder->where('summerize_id', '=', $alert->alert_id);
        /** @var ExtendedUserAlertEntity[]|AbstractCollection $alerts */
        $alerts = $alertsFinder->limitByPage($page, $perPage)->fetch();

        $alertRepo->addContentToAlerts($alerts);
        $alertRepo->autoMarkUserAlertsRead($alerts, $visitor);
        $alerts = $alerts->filterViewable();

        $groupedAlerts = !empty($options->svAlertsGroupByDate) ? $this->groupAlertsByDay($alerts) : null;

        $viewParams = [
            'navParams'     => [],
            'alert'         => $alert,
            'alerts'        => $alerts,
            'groupedAlerts' => $groupedAlerts,

            'page'        => $page,
            'perPage'     => $perPage,
            'totalAlerts' => $alertsFinder->total(),
        ];
        $view = $this->view('XF:Account\AlertsSummary', 'svAlertImprov_account_alerts_summary', $viewParams);

        return $this->addAccountWrapperParams($view, 'alerts');
    }

    /**
     * @param ParameterBag $params
     * @return AbstractReply
     */
    public function actionAlertRead(ParameterBag $params)
    {
        $alert = $this->assertViewableAlert((int)$params->get('alert_id'));

        /** @var AlertAction $alertAction */
        $alertAction = $this->plugin('SV\AlertImprovements:AlertAction');
        return $alertAction->doAction($alert, function(ExtendedUserAlertEntity $alert) {
            $inlist = $this->filter('inlist', 'bool');

            /** @var ExtendedUserAlertRepo $alertRepo */
            $alertRepo = $this->repository('XF:UserAlert');

            $alertRepo->markUserAlertRead($alert, \XF::$time);

            $redirect = $this->filter('_xfRedirect', 'str');
            if ($redirect)
            {
                return $this->redirect($redirect, '');
            }

            $alert->setOption('force_unread_in_ui', true);

            $viewParams = [
                'alerts'             => new ArrayCollection([$alert]),
                'showSelectCheckbox' => $inlist,
                'inAlertsPopup'      => !$inlist,
            ];

            return $this->view('XF:Account\Alert', 'svAlertImprov_alerts', $viewParams);
        }, \XF::phrase('svAlertImprov_mark_read'),
            \XF::phrase('svAlertImprov_mark_read'),
            \XF::phrase('svAlertImprov_please_confirm_that_you_want_to_mark_this_alert_read'),
            $this->buildLink('account/alert/read', $alert)
        );
    }

    /**
     * @param ParameterBag $params
     * @return AbstractReply
     */
    public function actionAlertUnread(ParameterBag $params)
    {
        $alert = $this->assertViewableAlert((int)$params->get('alert_id'));

        /** @var AlertAction $alertAction */
        $alertAction = $this->plugin('SV\AlertImprovements:AlertAction');
        return $alertAction->doAction($alert, function(ExtendedUserAlertEntity $alert) {
            $inlist = $this->filter('inlist', 'bool');

            /** @var ExtendedUserAlertRepo $alertRepo */
            $alertRepo = $this->repository('XF:UserAlert');

            $alertRepo->markUserAlertUnread($alert, true);

            $redirect = $this->filter('_xfRedirect', 'str');
            if ($redirect)
            {
                return $this->redirect($redirect, '');
            }

            $viewParams = [
                'alerts'             => new ArrayCollection([$alert]),
                'showSelectCheckbox' => $inlist,
                'inAlertsPopup'      => !$inlist,
            ];

            return $this->view('XF:Account\Alert', 'svAlertImprov_alerts', $viewParams);
        }, \XF::phrase('svAlertImprov_mark_unread'),
            \XF::phrase('svAlertImprov_mark_unread'),
            \XF::phrase('svAlertImprov_please_confirm_that_you_want_to_mark_this_alert_unread'),
            $this->buildLink('account/alert/unread', $alert)
        );
    }

    /**
     * @param ParameterBag $params
     * @return AbstractReply
     */
    public function actionAlertUnsummarize(ParameterBag $params)
    {
        $alert = $this->assertViewableAlert((int)$params->get('alert_id'));
        if (!$alert->is_summary)
        {
            return $this->notFound();
        }

        /** @var AlertAction $alertAction */
        $alertAction = $this->plugin('SV\AlertImprovements:AlertAction');
        return $alertAction->doAction($alert, function(ExtendedUserAlertEntity $alert) {
            /** @var ExtendedUserAlertRepo $alertRepo */
            $alertRepo = $this->repository('XF:UserAlert');
            $alertRepo->insertUnsummarizedAlerts($alert);
        }, \XF::phrase('svAlertImprov_unsummarize_alert'),
           \XF::phrase('svAlertImprov_unsummarize_alert'),
           \XF::phrase('svAlertImprov_please_confirm_that_you_want_to_unsummarize_this_alert'),
            $this->buildLink('account/alert/unsummarize', $alert),
            ''
        );
    }

    protected function hasRecentlySummarizedAlerts(int $floodCheck = null): bool
    {
        $options = \XF::options();
        if (!($options->svAlertsSummarize ?? false))
        {
            return true;
        }
        $floodingLimit = (int)($options->svAlertsSummarizeFlood ?? 1);
        if ($floodingLimit <= 0)
        {
            return false;
        }
        if ($floodCheck !== null)
        {
            $floodingLimit = $floodCheck;
        }

        /** @var ExtendedUserEntity $visitor */
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
     * @return AbstractReply
     */
    public function actionAlerts()
    {
        /** @var ExtendedUserEntity $visitor */
        $visitor = \XF::visitor();
        $option = $visitor->Option;
        $showOnlyFilter = $this->filter('show_only', '?str');
        $skipSummarize = $this->filter('skip_summarize', '?bool');
        $page = $this->filterPage();
        $options = $this->options();
        if ($page > 1 || !empty($option->sv_alerts_page_skips_summarize))
        {
            $skipSummarize = true;
        }

        $hasUnreadAlerts = ($visitor->alerts_unread || $visitor->alerts_unviewed);
        // defaults
        $skipSummarize = $skipSummarize ?? false;
        $showOnlyFilter = $showOnlyFilter ?? ($hasUnreadAlerts ? 'unread' : 'all');

        // make XF mark-alert handling sane
        $this->request->set('skip_mark_read', 1);
        Globals::$skipMarkAlertsRead = true;
        Globals::$skipSummarize = $skipSummarize || $this->hasRecentlySummarizedAlerts(1);
        Globals::$showUnreadOnly = $showOnlyFilter === 'unread';
        try
        {
            $response = parent::actionAlerts();
        }
        finally
        {
            Globals::$skipMarkAlertsRead = false;
            Globals::$skipSummarize = true;
            Globals::$showUnreadOnly = false;
        }
        if ($response instanceof View)
        {
            /** @var AbstractCollection|ExtendedUserAlertEntity[] $alerts */
            $alerts = $response->getParam('alerts');
            if ($alerts)
            {
                $this->markViewedAlertsRead($alerts, true);

                $groupedAlerts = empty($options->svAlertsGroupByDate) ? null : $this->groupAlertsByDay($alerts);

                $response->setParam('groupedAlerts', $groupedAlerts);

                // This condition is likely because of an unviewable alerts.
                // Rebuilding alert totals will likely not fix this, so big-hammer mark-all-as-read
                if ($page === 1 && $showOnlyFilter === 'unread' && $alerts->count() === 0 && $hasUnreadAlerts)
                {
                    /** @var ExtendedUserAlertRepo $alertRepo */
                    $alertRepo = $this->repository('XF:UserAlert');
                    $alertRepo->markUserAlertsRead($visitor);
                }
            }

            $navParams = $response->getParam('navParams') ?? [];
            $navParams = array_merge([
                'skip_summarize' => $skipSummarize,
                'show_only'      => $showOnlyFilter,
            ], $navParams);
            $response->setParam('navParams', $navParams);
        }

        return $response;
    }

    /**
     * @return AbstractReply
     */
    public function actionAlertsPopup()
    {
        /** @var ExtendedUserEntity $visitor */
        $visitor = \XF::visitor();
        $option = $visitor->Option;
        $skipMarkAsRead = Globals::isPrefetchRequest() || !empty($option->sv_alerts_popup_skips_mark_read);
        Globals::$skipMarkAlertsRead = true;
        Globals::$skipSummarize = $this->hasRecentlySummarizedAlerts(1);
        Globals::$doAlertPopupRewrite = true;
        try
        {
            $reply = parent::actionAlertsPopup();
        }
        finally
        {
            Globals::$doAlertPopupRewrite = false;
            Globals::$skipMarkAlertsRead = false;
            Globals::$skipSummarize = true;
        }

        if ($reply instanceof ViewReply)
        {
            /** @var AbstractCollection|ExtendedUserAlertEntity[] $alerts */
            $alerts = $reply->getParam('alerts');
            if ($alerts)
            {
                $this->markViewedAlertsRead($alerts, $skipMarkAsRead);

                $unreadAlertsAfterReadAlerts = \XF::options()->svUnreadAlertsAfterReadAlerts ?? false;
                if ($unreadAlertsAfterReadAlerts)
                {
                    $unreadAlerts = [];
                    /**
                     * @var int $key
                     * @var ExtendedUserAlertEntity $alert
                     */
                    foreach ($alerts as $key => $alert)
                    {
                        if ($alert->isUnreadInUi())
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

            // just use svAlertImprov_account_alerts_popup
            if ($reply->getTemplateName() === 'account_alerts_popup')
            {
                $reply->setTemplateName('svAlertImprov_account_alerts_popup');
            }
        }

        return $reply;
    }

    /**
     * @param AbstractCollection|ExtendedUserAlertEntity[] $alerts
     * @param bool $skipMarkAsRead
     */
    protected function markViewedAlertsRead($alerts, bool $skipMarkAsRead)
    {
        /** @var ExtendedUserEntity $visitor */
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
        $language = \XF::language();
        $dayStartTimestamps = $language->getDayStartTimestamps();

        return $alerts->groupBy(function (ExtendedUserAlertEntity $alert) use($language, $dayStartTimestamps)
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

            $timestamp = $alert->event_date;
            /** @noinspection PhpUnusedLocalVariableInspection */
            [$date, $time] = $language->getDateTimeParts($timestamp);

            if ($timestamp >= $dayStartTimestamps['today'])
            {
                $groupedDate = \XF::phrase('svAlertImprov_date.today')->render();
            }
            else if ($timestamp >= $dayStartTimestamps['yesterday'])
            {
                $groupedDate = \XF::phrase('svAlertImprov_date.yesterday')->render();
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

        /** @var ExtendedUserEntity $visitor */
        $visitor = \XF::visitor();
        $alertIds = $this->filter('alert_ids', 'array-uint');
        $alertIds = array_slice($alertIds, 0, $this->options()->alertsPerPage); // in case some genius passes a very long list of alert ids :>
        if (!count($alertIds))
        {
            throw $this->exception($this->error(\XF::phrase('svAlertImprov_please_select_at_least_one_alert_to_update')));
        }

        $redirectParams = $this->filter([
            'show_only'      => '?str',
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

    /**
     * @param int  $id
     * @param null $with
     * @param null $phraseKey
     * @return ExtendedUserAlertEntity
     * @throws ExceptionReply
     */
    protected function assertViewableAlert($id, $with = null, $phraseKey = null)
    {
        /** @var ExtendedUserAlertRepo $alertRepo */
        $alertRepo = $this->repository('XF:UserAlert');
        /** @var ExtendedUserAlertEntity $alert */
        $alert = $alertRepo->findAlertByIdsForUser(\XF::visitor()->user_id, $id)
                           ->with($with ?: [])
                           ->fetchOne();
        if (!$alert)
        {
            throw $this->exception($this->notFound());
        }

        if (!$alert->canView())
        {
            // an alert for a user is not visible, mark as read to get rid of it
            $alertRepo->markUserAlertRead($alert);

            throw $this->exception($this->notFound());
        }

        return $alert;
    }
}
