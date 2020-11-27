<?php

namespace SV\AlertImprovements\ControllerPlugin;

use SV\AlertImprovements\XF\Entity\UserAlert;
use XF\ControllerPlugin\AbstractPlugin;
use XF\Mvc\Reply\AbstractReply;

class AlertAction extends AbstractPlugin
{
    /**
     * @param UserAlert         $alert
     * @param \Closure          $actionCallback
     * @param \XF\Phrase|string $contentTitle
     * @param \XF\Phrase|string $buttonText
     * @param \XF\Phrase|string $actionText
     * @param string            $confirmUrl
     * @param string|null       $redirectMsg
     * @param string|null       $returnUrl
     * @param string|null       $template
     * @param array             $params
     * @param bool              $addAccountWrapper
     * @return AbstractReply
     */
    public function doAction(UserAlert $alert, \Closure $actionCallback, $contentTitle, $buttonText, $actionText, string $confirmUrl, string $redirectMsg = null, string $returnUrl = null, string $template = null, array $params = [], bool $addAccountWrapper = true): AbstractReply
    {
        if ($alert->hasErrors())
        {
            return $this->error($alert->getErrors());
        }

        if (!$returnUrl)
        {
            $linkParams = [
                'skip_mark_read' => true,
                'skip_summarize' => true,
            ];

            $returnUrl = $this->buildLink('account/alerts', [], $linkParams);
        }

        if ($this->isPost())
        {
            $result = $actionCallback($alert);
            if ($result instanceof AbstractReply)
            {
                return $result;
            }

            return $this->redirect($returnUrl, $redirectMsg);
        }
        $viewParams = [
            'alert'        => $alert,
            'confirmUrl'   => $confirmUrl,
            'contentTitle' => $contentTitle,
            'buttonText'   => $buttonText,
            'actionText'   => $actionText,
            'redirect'     => $returnUrl,
        ];
        $viewParams = $viewParams + $params;

        $view = $this->view('XF:Account\AlertAction', $template ?: 'svAlertImprov_account_alert_action', $viewParams);

        if ($addAccountWrapper)
        {
            $view->setParam('pageSelected', 'alerts');
        }

        return $view;
    }
}