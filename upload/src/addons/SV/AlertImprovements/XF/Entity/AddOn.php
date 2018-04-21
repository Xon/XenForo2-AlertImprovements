<?php

namespace SV\AlertImprovements\XF\Entity;

use XF\Mvc\Entity\Manager;
use XF\Mvc\Entity\Structure;

class AddOn extends XFCP_AddOn
{
    protected $svAlertImprovements_summarizeTypes = [];

    public function __construct(Manager $em, Structure $structure, array $values = [], array $relations = [])
    {
        parent::__construct($em, $structure, $values, $relations);

        $this->svAlertImprovements_summarizeTypes = [
            'conversation_message' => ['like', 'rating'],
            'post' => ['like', 'rating'],
            'profile_post' => ['like', 'rating'],
            'profile_post_comment' => ['like', 'rating'],
            'report_comment' => ['like', 'rating'],
            'user' => ['like', 'rating']
        ];
    }

    protected function _postDelete()
    {
        parent::_postDelete();

        \XF::runOnce('svAlertImprovementsExternalAddOnPostUninstall' . str_replace('//', '', $this->addon_id), function()
        {
            $db = $this->db();

            foreach ($this->svAlertImprovements_summarizeTypes AS $contentType => $supportedSummarizeTypes)
            {
                foreach ($supportedSummarizeTypes AS $supportedSummarizeType)
                {
                    $db->update('xf_user_alert', [
                        'summerize_id' => null
                    ], 'content_type = ? AND action = ? AND summerize_id IS NOT NULL', [
                        $contentType, $supportedSummarizeType
                    ]);

                    $db->delete('xf_user_alert', 'content_type = ? AND action = ?', [
                        $contentType, $supportedSummarizeType . '_summary'
                    ]);
                }
            }
        });
    }
}