<?php
/**
 * @noinspection PhpMissingReturnTypeInspection
 */

namespace SV\AlertImprovements\XF\Pub\Controller;

use SV\AlertImprovements\ControllerPlugin\AlertAction;
use SV\AlertImprovements\Globals;
use SV\AlertImprovements\Repository\AlertPreferences as AlertPreferencesRepo;
use SV\AlertImprovements\Repository\AlertSummarization as AlertSummarizationRepo;
use SV\AlertImprovements\XF\Entity\UserOption;
use SV\AlertImprovements\XF\Repository\UserAlert as ExtendedUserAlertRepo;
use XF\Entity\User;
use XF\Entity\UserAlert;
use XF\Mvc\Entity\AbstractCollection;
use XF\Mvc\Entity\ArrayCollection;
use XF\Mvc\FormAction;
use XF\Mvc\ParameterBag;
use XF\Mvc\Reply\AbstractReply;
use XF\Mvc\Reply\View;
use SV\AlertImprovements\XF\Entity\UserAlert as ExtendedUserAlertEntity;
use SV\AlertImprovements\XF\Entity\User as ExtendedUserEntity;
use XF\Mvc\Reply\View as ViewReply;
use XF\Mvc\Reply\Redirect as RedirectReply;
use XF\Mvc\Reply\Exception as ExceptionReply;
use XF\Service\FloodCheck;
use function array_filter;
use function array_key_exists;
use function array_keys;
use function array_slice, count, max, array_merge;
use function array_values;
use function assert;
use function is_callable;

/**
 * Class Account
 *
 * @package SV\AlertImprovements\XF\Pub\Controller
 */
class Account extends XFCP_Account
{
    /** @noinspection PhpMissingParentCallCommonInspection */
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
                /** @var UserAlert[]|AbstractCollection $alerts */
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

    public function actionPreferences()
    {
        $reply = parent::actionPreferences();

        if ($reply instanceof ViewReply)
        {
            $userOption = \XF::visitor()->Option ?? null;
            assert($userOption instanceof UserOption);
            $alertPrefs = $userOption->sv_alert_pref;

            if ($alertPrefs === [] || $alertPrefs === null)
            {
                $alertOption = 'defaults';
            }
            else if (isset($alertPrefs['none']))
            {
                $alertOption = 'none';
                // configure custom alerting options as the default
                $userOption->sv_alert_pref = [];
                $userOption->setReadOnly(true);
            }
            else
            {
                $alertOption = 'custom';
            }

            $reply->setParam('svAlertOptions', $alertOption);
        }


        return $reply;
    }

    /**
     * @param User $visitor
     * @return FormAction
     */
    protected function preferencesSaveProcess(User $visitor)
    {
        $form = parent::preferencesSaveProcess($visitor);

        $input = $this->filter(
            [
                'option' => [
                    'sv_alerts_popup_read_behavior'  => 'str',
                    'sv_alerts_page_skips_summarize' => 'bool',
                    'sv_alerts_summarize_threshold'  => 'uint',
                ],
            ]
        );

        if (!(\XF::options()->svAlertsSummarize ?? false))
        {
            unset($input['option']['sv_alerts_page_skips_summarize']);
            unset($input['option']['sv_alerts_summarize_threshold']);
        }

        assert( $visitor instanceof ExtendedUserEntity);
        $userOptions = $visitor->getRelationOrDefault('Option');
        $form->setupEntityInput($userOptions, $input['option']);

        $alertOptions = $this->filter('svAlertOptions', 'str');
        switch ($alertOptions)
        {
            case 'none':
                $form->setupEntityInput($userOptions, [
                    'sv_alert_pref' => ['none' => true],
                ]);
                break;
            case 'defaults':
                $form->setupEntityInput($userOptions, [
                    'sv_alert_pref' => [],
                ]);
                break;
            case 'custom':
            default:
                $patchedOptOuts = $this->svGetAlertOptOutFromInputs($visitor);
                $form->setupEntityInput($userOptions, $patchedOptOuts);
                break;
        }

        return $form;
    }

    protected function svGetOptOutsTypes(): array
    {
        $visitor = \XF::visitor();

        $types = [
            'alert' => ['alert_optout', 'alert', null],
            'autoRead' => [null, 'autoread', null],
        ];
        if ($visitor->canUsePushNotifications())
        {
            $types['push'] = ['push_optout', 'push', 'push_shown'];
        }
        // NF/Discord add-on support
        if (is_callable([$this, 'canMirrorDiscordAlerts']) && $this->canMirrorDiscordAlerts())
        {
            $types['discord'] = ['nf_discord_optout', 'nf_discord', null];
        }

        return $types;
    }

    protected function svGetAlertOptOutFromInputs(ExtendedUserEntity $visitor): array
    {
        $types = $this->svGetOptOutsTypes();

        $alertPrefsRepo = AlertPreferencesRepo::get();
        $alertActions = $alertPrefsRepo->getAlertOptOutActionList();
        $alertOptOutDefaults = $alertPrefsRepo->getAllAlertPreferencesDefaults(array_keys($types), $alertActions);

        $alertRepo = $this->repository('XF:UserAlert');
        assert($alertRepo instanceof ExtendedUserAlertRepo);
        /** @var array<string> $optOutActions */
        $optOutActions = array_keys($alertRepo->getAlertOptOutActions());

        /** @var array<bool> $reset */
        $reset = $this->filter('reset-alerts', 'array-bool');
        $sv_alert_pref = $visitor->Option->sv_alert_pref;
        $entityInputs = [
            'sv_alert_pref' => $sv_alert_pref ?? [],
        ];
        unset($entityInputs['sv_alert_pref']['none']);

        foreach ($types as $type => $alertConfig)
        {
            [$oldOutputName, $inputKey, $isShownKey] = $alertConfig;
            if (!array_key_exists($type, $alertOptOutDefaults))
            {
                continue;
            }

            $optOuts = [];
            /** @var array<bool> $inputs */
            $inputs = $this->filter($inputKey, 'array-bool');
            $isShown = $isShownKey ? $this->filter($isShownKey, 'array-bool') : null;
            foreach ($optOutActions as $optOut)
            {
                $parts = $alertPrefsRepo->convertStringyOptOut($alertActions, $optOut);
                if ($parts === null)
                {
                    // bad data, just skips since it wouldn't do anything
                    continue;
                }
                [$contentType, $action] = $parts;

                $wasShowed = $isShown === null || isset($isShown[$optOut]);
                $defaultValue = $alertOptOutDefaults[$type][$contentType][$action] ?? true;
                $value = $inputs[$optOut] ?? false;
                if ($reset[$optOut] ?? false)
                {
                    $value = $defaultValue;
                }

                if ($oldOutputName !== null && !$value && $wasShowed)
                {
                    $optOuts[$optOut] = $optOut;
                }
                if ($wasShowed)
                {
                    if ($defaultValue !== $value)
                    {
                        $entityInputs['sv_alert_pref'][$type][$contentType][$action] = $value;
                    }
                    else
                    {
                        unset($entityInputs['sv_alert_pref'][$type][$contentType][$action]);
                    }
                }
            }
            if ($oldOutputName !== null)
            {
                $entityInputs[$oldOutputName] = array_values($optOuts);
            }
        }

        // don't story empty lists to reduce json parsing needed
        foreach ($entityInputs['sv_alert_pref'] as &$contentTypes)
        {
            $contentTypes = array_filter($contentTypes, function (array $item) {
                return count($item) !== 0;
            });
        }
        $entityInputs['sv_alert_pref'] = array_filter($entityInputs['sv_alert_pref'], function (array$item) {
            return count($item) !== 0;
        });

        return $entityInputs;
    }

    /**
     * @param ParameterBag $params
     * @return AbstractReply
     * @throws ExceptionReply
     * @noinspection PhpUnusedParameterInspection
     */
    public function actionSummarizeAlerts(ParameterBag $params)
    {
        if (!Globals::isResummarizeAlertsEnabled())
        {
            return $this->notFound();
        }

        $floodingLimit = max(1, $options->svAlertsSummarizeFlood ?? 1);
        $this->assertNotFlooding('alertSummarize', $floodingLimit);

        $visitor = \XF::visitor();
        assert($visitor instanceof ExtendedUserEntity);
        // summarize & mark as read as of now
        AlertSummarizationRepo::get()->resummarizeAlertsForUser($visitor, \XF::$time);

        return $this->redirect($this->buildLink('account/alerts', null, ['show_only' => 'all']));
    }

    /**
     * @param ParameterBag $params
     * @return AbstractReply
     * @throws ExceptionReply
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
     * @throws ExceptionReply
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
     * @throws ExceptionReply
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
     * @throws ExceptionReply
     */
    public function actionAlertUnsummarize(ParameterBag $params)
    {
        if (!Globals::isResummarizeAlertsEnabled())
        {
            return $this->notFound();
        }

        $alert = $this->assertViewableAlert((int)$params->get('alert_id'));
        if (!$alert->is_summary)
        {
            return $this->notFound();
        }

        /** @var AlertAction $alertAction */
        $alertAction = $this->plugin('SV\AlertImprovements:AlertAction');
        return $alertAction->doAction($alert, function(ExtendedUserAlertEntity $alert) {
            $floodingLimit = max(1, $options->svAlertsSummarizeFlood ?? 1);
            $this->assertNotFlooding('alertSummarize', $floodingLimit);

            AlertSummarizationRepo::get()->insertUnsummarizedAlerts($alert);
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

        /** @var FloodCheck $floodChecker */
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
            $response->setParam('canResummarize', Globals::isResummarizeAlertsEnabled());
            /** @var AbstractCollection|ExtendedUserAlertEntity[] $alerts */
            $alerts = $response->getParam('alerts');
            if ($alerts)
            {
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
        $skipMarkAsRead = Globals::isPrefetchRequest();
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
                if (!$skipMarkAsRead)
                {
                    $alertRepo = $this->repository('XF:UserAlert');
                    assert($alertRepo instanceof ExtendedUserAlertRepo);
                    $alertRepo->autoMarkUserAlertsRead($alerts, $visitor);
                }

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

    /** @noinspection PhpMissingParentCallCommonInspection */
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
     * @noinspection PhpMissingParentCallCommonInspection
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
     * @noinspection PhpMissingParentCallCommonInspection
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

    /**
     * @deprecated
     */
    protected function getAlertSummarizationRepo(): AlertSummarizationRepo
    {
        return AlertSummarizationRepo::get();
    }
}
