<?php
/** @noinspection PhpIncludeInspection */

use SV\StandardLib\Helper;

ignore_user_abort(true);

$dir = dirname(__DIR__);
require($dir . '/src/XF.php');

XF::start($dir);
$app = XF::setupApp('XF\Pub\App');

$userId = 7;
$uniquePosts = 5;
$reactionsPerPost = 20;

$testUser = Helper::find(\XF\Entity\User::class, $userId);

$reactionRepo = Helper::repository(\XF\Repository\Reaction::class);

$userRepo =  Helper::repository(\XF\Repository\User::class);

$reaction = Helper::find(\XF\Entity\Reaction::class, 1);;

/** @var array<int,\XF\Entity\Post> $contents */
$contents = Helper::finder(\XF\Finder\Post::class)
                ->where('user_id', $userId)
                ->where('message_state', 'visible')
                ->where('Thread.discussion_state', 'visible')
                ->order('post_date', 'desc')
                ->limit($uniquePosts)
                ->fetch()
                ->toArray();

/** @var array<int,\XF\Entity\User> $users */
$users = Helper::finder(\XF\Finder\User::class)
             ->where('user_id', '<>', $userId)
             ->where('is_banned', 0)
             ->where('user_state', 'valid')
             ->limit($reactionsPerPost)
             ->fetch()
             ->toArray();


// wipe alerts
$app->db()->query("
    DELETE FROM xf_user_alert
    WHERE alerted_user_id = ?
", [$userId]);
foreach ($contents as $content)
{
    $reactionRepo->fastDeleteReactions($content->getEntityContentType(), $content->getEntityId());
}

/** @var \SV\AlertImprovements\XF\Repository\UserAlert $userAlertRepo */
$userAlertRepo = Helper::repository(\XF\Repository\UserAlert::class);
$userAlertRepo->updateUnreadCountForUserId($userId);
$userAlertRepo->updateUnviewedCountForUserId($userId);
$userAlertRepo->refreshUserAlertCounters($testUser);

$reactionContentStructure = $app->em()->getEntityStructure('XF:ReactionContent');
$userAlertStructure = $app->em()->getEntityStructure('XF:UserAlert');
$oldTime = \XF::$time;
foreach ($contents as $content)
{
    foreach ($users as $user)
    {
        \XF::$time = $oldTime - ((int)substr((string)$user->user_id, 0, 1)) * 86400;
        \XF::$time = \XF::$time - (\XF::$time % 86400);
        $reactionContentStructure->columns['reaction_date']['default'] = \XF::$time;
        $userAlertStructure->columns['event_date']['default'] = \XF::$time;
        \XF::asVisitor($user, function () use ($user, $content, $reaction, $reactionRepo) {
            $reactionRepo->insertReaction(
                $reaction->reaction_id,
                $content->getEntityContentType(),
                $content->getEntityId(),
                $user
            );
        });
        assert(!$app->db()->inTransaction());
    }
}
//\XF::$time = $oldTime;
