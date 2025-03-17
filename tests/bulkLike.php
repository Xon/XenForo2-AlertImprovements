<?php
/** @noinspection PhpIncludeInspection */

use SV\AlertImprovements\XF\Repository\UserAlert as ExtendedUserAlertRepo;
use SV\StandardLib\Helper;
use XF\Entity\Post as PostEntity;
use XF\Entity\Reaction as ReactionEntity;
use XF\Entity\ReactionContent as ReactionContentEntity;
use XF\Entity\User as UserEntity;
use XF\Entity\UserAlert as UserAlertEntity;
use XF\Finder\Post as PostFinder;
use XF\Finder\User as UserFinder;
use XF\Repository\Reaction as ReactionRepo;
use XF\Repository\User as UserRepo;
use XF\Repository\UserAlert as UserAlertRepo;

ignore_user_abort(true);

$dir = dirname(__DIR__);
require($dir . '/src/XF.php');

XF::start($dir);
$app = XF::setupApp('XF\Pub\App');

$userId = 7;
$uniquePosts = 5;
$reactionsPerPost = 20;

$testUser = Helper::find(UserEntity::class, $userId);

$reactionRepo = Helper::repository(ReactionRepo::class);

$userRepo =  Helper::repository(UserRepo::class);

$reaction = Helper::find(ReactionEntity::class, 1);

/** @var array<int,PostEntity> $contents */
$contents = Helper::finder(PostFinder::class)
                ->where('user_id', $userId)
                ->where('message_state', 'visible')
                ->where('Thread.discussion_state', 'visible')
                ->order('post_date', 'desc')
                ->limit($uniquePosts)
                ->fetch()
                ->toArray();

/** @var array<int,UserEntity> $users */
$users = Helper::finder(UserFinder::class)
             ->where('user_id', '<>', $userId)
             ->where('is_banned', 0)
             ->where('user_state', 'valid')
             ->limit($reactionsPerPost)
             ->fetch()
             ->toArray();


// wipe alerts
$app->db()->query('
    DELETE FROM xf_user_alert
    WHERE alerted_user_id = ?
', [$userId]);
foreach ($contents as $content)
{
    $reactionRepo->fastDeleteReactions($content->getEntityContentType(), $content->getEntityId());
}

/** @var ExtendedUserAlertRepo $userAlertRepo */
$userAlertRepo = Helper::repository(UserAlertRepo::class);
$userAlertRepo->updateUnreadCountForUserId($userId);
$userAlertRepo->updateUnviewedCountForUserId($userId);
$userAlertRepo->refreshUserAlertCounters($testUser);


$reactionContentStructure = Helper::getEntityStructure(ReactionContentEntity::class);
$userAlertStructure = Helper::getEntityStructure(UserAlertEntity::class);
$oldTime = XF::$time;
foreach ($contents as $content)
{
    foreach ($users as $user)
    {
        XF::$time = $oldTime - ((int)substr((string)$user->user_id, 0, 1)) * 86400;
        XF::$time = XF::$time - (XF::$time % 86400);
        $reactionContentStructure->columns['reaction_date']['default'] = XF::$time;
        $userAlertStructure->columns['event_date']['default'] = XF::$time;
        XF::asVisitor($user, function () use ($user, $content, $reaction, $reactionRepo) {
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
