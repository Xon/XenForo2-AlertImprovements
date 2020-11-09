<?php

namespace SV\AlertImprovements\XF\Pub\Controller\XF2;

use SV\AlertImprovements\XF\Pub\Controller\XFCP_AccountBackport;
use SV\AlertImprovements\XF\Repository\UserAlert as ExtendedUserAlertRepo;

class AccountBackport extends XFCP_AccountBackport
{
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
}