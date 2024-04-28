<?php
/**
 * @noinspection PhpMissingReturnTypeInspection
 */

namespace SV\AlertImprovements\XF\Pub\Controller;

use SV\AlertImprovements\XF\Entity\User as ExtendedUserEntity;
use SV\AlertImprovements\XF\Repository\UserAlert;
use SV\StandardLib\Helper;
use XF\Entity\Thread as ThreadEntity;
use XF\Mvc\Entity\AbstractCollection;
use XF\Repository\UserAlert as UserAlertRepo;

/**
 * Class Thread
 *
 * @package SV\AlertImprovements\XF\Pub\Controller
 */
class Thread extends XFCP_Thread
{
    /**
     * @param ThreadEntity $thread
     * @param int          $lastDate
     * @param int          $limit
     * @return array
     * @noinspection PhpMissingParamTypeInspection
     */
    protected function _getNextLivePosts(ThreadEntity $thread, $lastDate, $limit = 3)
    {
        /**
         * @var AbstractCollection $contents
         * @var int                $lastDate
         */
        /** @noinspection PhpUndefinedMethodInspection */
        [$contents, $lastDate] = parent::_getNextLivePosts($thread, $lastDate, $limit);

        /** @var ExtendedUserEntity $visitor */
        $visitor = \XF::visitor();

        if ($visitor->user_id && $visitor->alerts_unread)
        {
            /** @var UserAlert $alertRepo */
            $alertRepo = Helper::repository(UserAlertRepo::class);
            $alertRepo->markAlertsReadForContentIds('post', $alertRepo->getContentIdKeys($contents));
        }

        return [$contents, $lastDate];
    }
}
