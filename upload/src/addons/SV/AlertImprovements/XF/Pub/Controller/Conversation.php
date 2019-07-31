<?php

namespace SV\AlertImprovements\XF\Pub\Controller;

use SV\AlertImprovements\XF\Repository\UserAlert;
use XF\Entity\ConversationUser;
use XF\Mvc\Entity\AbstractCollection;
use XF\Mvc\ParameterBag;
use XF\Mvc\Reply\AbstractReply;
use XF\Mvc\Reply\View;

/**
 * Class Conversation
 *
 * @package SV\AlertImprovements\XF\Pub\Controller
 */
class Conversation extends XFCP_Conversation
{
    /**
     * @param ParameterBag $params
     * @return View
     */
    public function actionView(ParameterBag $params)
    {
        $reply = parent::actionView($params);

        if ($reply instanceof View &&
            ($messages = $reply->getParam('messages')) &&
            ($conversation = $reply->getParam('conversation')))
        {
            /** @var AbstractCollection $messages */
            /** @var \XF\Entity\ConversationMaster $conversation */
            $visitor = \XF::visitor();

            if ($visitor->user_id && $visitor->alerts_unread)
            {
                $contentIds = $messages->keys();
                $contentType = 'conversation_message';

                /** @var UserAlert $alertRepo */
                $alertRepo = $this->repository('XF:UserAlert');
                $alertRepo->markAlertsReadForContentIds($contentType, $contentIds, null, 2010000);


                $contentIds = [$conversation->conversation_id];
                $contentType = 'conversation';
                $alertRepo->markAlertsReadForContentIds($contentType, $contentIds);
            }
        }

        return $reply;
    }

    /**
     * @param ParameterBag $params
     * @return AbstractReply|\XF\Mvc\Reply\Reroute|View
     * @throws \XF\Db\Exception
     */
    public function actionIndex(ParameterBag $params)
    {
        return $this->markConvEssInboxAlertsAsRead(parent::actionIndex($params));
    }

    /**
     * @param ParameterBag $parameterBag
     * @return AbstractReply
     * @throws \XF\Db\Exception
     */
    public function actionLabeled(ParameterBag $parameterBag)
    {
        if (!\is_callable('parent::actionLabeled'))
        {
            return $this->notFound();
        }

        /** @noinspection PhpUndefinedMethodInspection */
        return $this->markConvEssInboxAlertsAsRead(parent::actionLabeled($parameterBag));
    }

    /**
     * @param AbstractReply $reply
     * @param array         $actions
     * @return AbstractReply
     */
    protected function markConvEssInboxAlertsAsRead(AbstractReply $reply, array $actions = ['conversation_kick', 'inbox_full'])
    {
        if ($reply instanceof View && $this->isConvEssActive())
        {
            /** @var AbstractCollection $messages */
            /** @var \XF\Entity\ConversationMaster $conversation */
            $visitor = \XF::visitor();

            if ($visitor->user_id && $visitor->alerts_unread)
            {
                /** @var UserAlert $alertRepo */
                $alertRepo = $this->repository('XF:UserAlert');

                $alertRepo->markAlertsReadForContentIds('user', [$visitor->user_id], $actions);
            }
        }

        return $reply;
    }

    /**
     * @return bool
     */
    protected function isConvEssActive()
    {
        $addOns = \XF::app()->container('addon.cache');

        return isset($addOns['SV/ConversationEssentials']);
    }

    /**
     * @param ConversationUser $convUser
     * @param int              $lastDate
     * @param int              $limit
     * @return array
     */
    protected function _getNextLivePosts(ConversationUser $convUser, $lastDate, $limit = 3)
    {
        /** @noinspection PhpUndefinedMethodInspection */
        /**
         * @var AbstractCollection $contents
         * @var int                $lastDate
         */
        list ($contents, $lastDate) = parent::_getNextLivePosts($convUser, $lastDate, $limit);

        $contentIds = $contents->keys();
        $contentType = 'conversation_message';

        /** @var UserAlert $alertRepo */
        $alertRepo = $this->repository('XF:UserAlert');
        $alertRepo->markAlertsReadForContentIds($contentType, $contentIds);

        return [$contents, $lastDate];
    }
}
