<?php
/**
 * @noinspection RedundantSuppression
 * @noinspection PhpMissingReturnTypeInspection
 */

namespace SV\AlertImprovements\XF\Repository;

/**
 * @extends \XF\Repository\UserAlert
 */
class UserAlertAutoReadPatch extends XFCP_UserAlertAutoReadPatch
{
    public function insertAlert($receiverId, $senderId, $senderName, $contentType, $contentId, $action, array $extra = [], array $options = [])
    {
        /** @var UserAlertPatch $this */
        $this->patchAutoReadForInsertAlert($receiverId, $contentType, $action, $extra, $options);
        return parent::insertAlert($receiverId, $senderId, $senderName, $contentType, $contentId, $action, $extra, $options);
    }
}
