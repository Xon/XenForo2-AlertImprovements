<?php
/** @noinspection PhpIncludeInspection */

ignore_user_abort(true);

$dir = dirname(__DIR__);
require($dir . '/src/XF.php');

XF::start($dir);
$app = XF::setupApp('XF\Pub\App');

$userId = 7;
$uniquePosts = 5;
$reactionsPerPost = 20;

$testUser = $app->find('XF:User', $userId);
assert($testUser instanceof XF\Entity\User);

$reactionRepo = $app->repository('XF:Reaction');
assert($reactionRepo instanceof \XF\Repository\Reaction);

$userRepo = $app->repository('XF:User');
assert($userRepo instanceof \XF\Repository\User);

$reaction = $app->find('XF:Reaction', 1);
assert($reaction instanceof \XF\Entity\Reaction);

/** @var array<int,\XF\Entity\Post> $contents */
$contents = $app->finder('XF:Post')
                ->where('user_id', $userId)
                ->where('message_state', 'visible')
                ->where('Thread.discussion_state', 'visible')
                ->order('post_date', 'desc')
                ->limit($uniquePosts)
                ->fetch()
                ->toArray();

/** @var array<int,\XF\Entity\User> $users */
$users = $app->finder('XF:User')
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

$userAlertRepo = $app->repository('XF:UserAlert');
assert($userAlertRepo instanceof \SV\AlertImprovements\XF\Repository\UserAlert);
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
