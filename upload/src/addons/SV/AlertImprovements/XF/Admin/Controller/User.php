<?php
/**
 * @noinspection PhpMissingReturnTypeInspection
 */

namespace SV\AlertImprovements\XF\Admin\Controller;

/**
 * @extends \XF\Admin\Controller\User
 */
class User extends XFCP_User
{
    protected function userSaveProcess(\XF\Entity\User $user)
    {
        $form = parent::userSaveProcess($user);

        $input = $this->filter(
            [
                'option' => [
                    'sv_alerts_popup_read_behavior'  => 'str',
                    'sv_alerts_page_skips_summarize' => 'bool',
                    'sv_alerts_summarize_threshold'  => 'uint',
                ],
            ]
        );

        $userOptions = $user->getRelationOrDefault('Option');
        $form->setupEntityInput($userOptions, $input['option']);

        return $form;
    }
}