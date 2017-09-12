<?php

namespace SV\AlertImprovements;

use XF\Mvc\Entity\Entity;

class Listen
{
    public static function userOptionEntityStructure(\XF\Mvc\Entity\Manager $em, \XF\Mvc\Entity\Structure &$structure)
    {
        $structure->columns['sv_alerts_page_skips_mark_read'] = ['type' => Entity::BOOL, 'default' => 1];
        $structure->columns['sv_alerts_page_skips_summarize'] = ['type' => Entity::BOOL, 'default' => 0];
        $structure->columns['sv_alerts_summarize_threshold'] = ['type' => Entity::UINT, 'default' => 4];
    }
}
