<?php

namespace SV\AlertImprovements\XF\Pub\Controller;

use SV\AlertImprovements\XF\Entity\User as ExtendedUserEntity;
use SV\AlertImprovements\XF\Repository\UserAlert;
use SV\StandardLib\Helper;
use XF\Mvc\ParameterBag;
use XF\Mvc\Reply\AbstractReply;
use XF\Mvc\Reply\View;
use XF\Repository\UserAlert as UserAlertRepo;

/**
 * @extends \XF\Pub\Controller\Report
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
                $alertRepo = Helper::repository(UserAlertRepo::class);
                $alertRepo->markAlertsReadForContentIds('report_comment', $alertRepo->getContentIdKeys($comments));
            }
        }

        return $reply;
    }
}
