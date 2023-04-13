<?php
/**
 * @noinspection PhpMissingReturnTypeInspection
 */

namespace SV\AlertImprovements\XF\Admin\Controller;

use XF\Mvc\FormAction;
use XF\Mvc\Reply\Exception;

/**
 * Class User
 *
 * @package SV\AlertImprovements\XF\Admin\Controller
 */
class User extends XFCP_User
{
    /**
     * @param \XF\Entity\User $user
     * @return FormAction
     * @throws Exception
     */
    protected function userSaveProcess(\XF\Entity\User $user)
    {
        $form = parent::userSaveProcess($user);

        $input = $this->filter(
            [
                'option' => [
                    'sv_alerts_popup_skips_mark_read' => 'bool',
                    'sv_alerts_page_skips_summarize'  => 'bool',
                    'sv_alerts_summarize_threshold'   => 'uint',
                ],
            ]
        );

        $userOptions = $user->getRelationOrDefault('Option');
        $form->setupEntityInput($userOptions, $input['option']);

        return $form;
    }
}