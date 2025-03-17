<?php
/**
 * @noinspection PhpMissingReturnTypeInspection
 */

namespace SV\AlertImprovements\XF\Pub\Controller;

use SV\AlertImprovements\XF\Entity\User as ExtendedUserEntity;
use SV\AlertImprovements\XF\Repository\UserAlert;
use SV\StandardLib\Helper;
use XF\Entity\ConversationMaster as ConversationMasterEntity;
use XF\Entity\ConversationUser as ConversationUserEntity;
use XF\Mvc\Entity\AbstractCollection;
use XF\Mvc\ParameterBag;
use XF\Mvc\Reply\AbstractReply;
use XF\Mvc\Reply\View as ViewReply;
use XF\Repository\UserAlert as UserAlertRepo;
use function is_callable;

/**
 * @extends \XF\Pub\Controller\Account
 */
class Conversation extends XFCP_Conversation
{
    /**
     * @param ParameterBag $params
     * @return AbstractReply
     */
    public function actionView(ParameterBag $params)
    {
        $reply = parent::actionView($params);

        if ($reply instanceof ViewReply &&
            ($reply->getParam('messages')) &&
            ($conversation = $reply->getParam('conversation')))
        {
            /** @var ConversationMasterEntity $conversation */
            /** @var ExtendedUserEntity $visitor */
            $visitor = \XF::visitor();

            if ($visitor->user_id && $visitor->alerts_unread)
            {
                /** @var UserAlert $alertRepo */
                $alertRepo = Helper::repository(UserAlertRepo::class);
                $alertRepo->markAlertsReadForContentIds('conversation', [$conversation->conversation_id]);
            }
        }

        return $reply;
    }

    /**
     * @param ParameterBag $params
     * @return AbstractReply
     */
    public function actionIndex(ParameterBag $params)
    {
        return $this->markConvEssInboxAlertsAsRead(parent::actionIndex($params));
    }

    /**
     * @param ParameterBag $parameterBag
     * @return AbstractReply
     */
    public function actionLabeled(ParameterBag $parameterBag)
    {
        if (!is_callable(parent::class.'::actionLabeled'))
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
        if ($reply instanceof ViewReply && $this->isConvEssActive())
        {
            /** @var AbstractCollection $messages */
            /** @var ConversationMasterEntity $conversation */
            /** @var ExtendedUserEntity $visitor */
            $visitor = \XF::visitor();

            if ($visitor->user_id && $visitor->alerts_unread)
            {
                /** @var UserAlert $alertRepo */
                $alertRepo = Helper::repository(UserAlertRepo::class);

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
     * @param ConversationUserEntity $convUser
     * @param int                    $lastDate
     * @param int                    $limit
     * @return array
     * @noinspection PhpMissingParamTypeInspection
     */
    protected function _getNextLivePosts(ConversationUserEntity $convUser, $lastDate, $limit = 3)
    {
        /**
         * @var AbstractCollection $contents
         * @var int                $lastDate
         */
        /** @noinspection PhpUndefinedMethodInspection */
        [$contents, $lastDate] = parent::_getNextLivePosts($convUser, $lastDate, $limit);

        /** @var ExtendedUserEntity $visitor */
        $visitor = \XF::visitor();

        if ($visitor->user_id && $visitor->alerts_unread)
        {
            /** @var UserAlert $alertRepo */
            $alertRepo = Helper::repository(UserAlertRepo::class);
            $alertRepo->markAlertsReadForContentIds('conversation_message', $alertRepo->getContentIdKeys($contents));
        }

        return [$contents, $lastDate];
    }
}
