<?php

namespace SV\AlertImprovements\XF\Pub\Controller;

use SV\AlertImprovements\XF\Entity\User as ExtendedUserEntity;
use SV\AlertImprovements\XF\Repository\UserAlert;
use XF\Mvc\ParameterBag;
use XF\Mvc\Reply\AbstractReply;
use XF\Mvc\Reply\View;

/**
 * Class Report
 *
 * @package SV\AlertImprovements\XF\Pub\Controller
 */
class Report extends XFCP_Report
{
    /**
     * @param ParameterBag $params
     * @return AbstractReply
     */
    public function actionView(ParameterBag $params)
    {
        $reply = parent::actionView($params);

        if ($reply instanceof View &&
            ($reply->getParam('report')) &&
            ($comments = $reply->getParam('comments')))
        {
            /** @var ExtendedUserEntity $visitor */
            $visitor = \XF::visitor();

            if ($visitor->user_id && $visitor->alerts_unread)
            {
                /** @var UserAlert $alertRepo */
                $alertRepo = $this->repository('XF:UserAlert');
                $alertRepo->markAlertsReadForContentIds('report_comment', $alertRepo->getContentIdKeys($comments));
            }
        }

        return $reply;
    }
}
