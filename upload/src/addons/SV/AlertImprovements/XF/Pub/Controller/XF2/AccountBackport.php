<?php

namespace SV\AlertImprovements\XF\Pub\Controller\XF2;

use SV\AlertImprovements\XF\Pub\Controller\XFCP_AccountBackport;
use SV\AlertImprovements\XF\Repository\UserAlert as ExtendedUserAlertRepo;
use XF\Entity\UserAlert as UserAlertEntity;
use XF\Mvc\Entity\Entity;
use XF\Mvc\Reply\Exception as ExceptionReply;
use XF\Mvc\Reply\Redirect as RedirectReply;
use XF\Mvc\Reply\View as ViewReply;

class AccountBackport extends XFCP_AccountBackport
{
    /**
     * @return RedirectReply|ViewReply
     *
     * @throws ExceptionReply
     */
    public function actionAlertToggle()
    {
        /** @var UserAlertEntity $alert */
        $alert = $this->assertViewableRecord('XF:UserAlert', $this->filter('alert_id', 'uint'));

        $newUnreadStatus = $this->filter('unread', '?bool');
        if ($newUnreadStatus === null)
        {
            $newUnreadStatus = $alert->isUnread() ? false : true;
        }

        $redirect = $this->getDynamicRedirect($this->buildLink('account/alerts'));
        if ($this->isPost())
        {
            /** @var ExtendedUserAlertRepo $alertRepo */
            $alertRepo = $this->repository('XF:UserAlert');

            if ($newUnreadStatus)
            {
                $alertRepo->markUserAlertUnread($alert);
                $message = \XF::phrase('svAlertImprov_alert_marked_as_unread');
            }
            else
            {
                $alertRepo->markUserAlertRead($alert);
                $message = \XF::phrase('svAlertImprov_alert_marked_as_read');
            }

            return $this->redirect($redirect, $message);
        }

        $viewParams = [
            'alert' => $alert,
            'newUnreadStatus' => $newUnreadStatus,
            'redirect' => $redirect
        ];
        $view = $this->view(
            'SV\AlertImprovements\XF:Account\AlertToggle',
            'svAlertImprov_account_alert_toggle',
            $viewParams
        );
        return $this->addAccountWrapperParams($view, 'alerts');
    }

    public function actionAlertsMarkRead()
    {
        $visitor = \XF::visitor();

        /** @var ExtendedUserAlertRepo $alertRepo */
        $alertRepo = $this->repository('XF:UserAlert');

        $redirect = $this->getDynamicRedirect($this->buildLink('account/alerts'));

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
     * @param int $id
     * @param array|string|null $with
     * @param string|null $phraseKey
     *
     * @return Entity|UserAlertEntity
     *
     * @throws ExceptionReply
     */
    protected function assertViewableAlert($id, $with = null, $phraseKey = null)
    {
        return $this->assertViewableRecord('XF:UserAlert', $id, $with, $phraseKey);
    }
}