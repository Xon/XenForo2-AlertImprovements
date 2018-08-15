<?php

namespace SV\AlertImprovements\XF\Entity;

use XF\Mvc\Entity\Entity;
use XF\Mvc\Entity\Structure;

/**
 * Extends \XF\Entity\UserOption
 *
 * @property bool sv_alerts_page_skips_mark_read
 * @property bool sv_alerts_page_skips_summarize
 * @property int  sv_alerts_summarize_threshold
 */
class UserOption extends XFCP_UserOption
{
    protected function _setupDefaults()
    {
        parent::_setupDefaults();

        $options = \XF::options();

        $defaults = $options->registrationDefaults;
        $this->sv_alerts_page_skips_mark_read = $defaults['sv_alerts_page_skips_mark_read'] ? true : false;
        $this->sv_alerts_page_skips_summarize = $defaults['sv_alerts_page_skips_summarize'] ? true : false;
        $this->sv_alerts_summarize_threshold = $defaults['sv_alerts_summarize_threshold'];
    }

    /**
     * @param Structure $structure
     * @return Structure
     */
    public static function getStructure(Structure $structure)
    {
        $structure = parent::getStructure($structure);

        $structure->columns['sv_alerts_page_skips_mark_read'] = ['type' => Entity::BOOL, 'default' => 1];
        $structure->columns['sv_alerts_page_skips_summarize'] = ['type' => Entity::BOOL, 'default' => 0];
        $structure->columns['sv_alerts_summarize_threshold'] = ['type' => Entity::UINT, 'default' => 4];

        return $structure;
    }
}
