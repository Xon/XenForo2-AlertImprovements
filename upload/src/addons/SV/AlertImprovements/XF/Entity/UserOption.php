<?php
/**
 * @noinspection PhpMissingReturnTypeInspection
 */

namespace SV\AlertImprovements\XF\Entity;

use SV\AlertImprovements\Repository\AlertPreferences;
use XF\Mvc\Entity\Entity;
use XF\Mvc\Entity\Structure;

/**
 * Extends \XF\Entity\UserOption
 *
 * @property bool $sv_alerts_popup_skips_mark_read
 * @property bool $sv_alerts_page_skips_summarize
 * @property int  $sv_alerts_summarize_threshold
 * @property ?array $sv_alert_pref
 */
class UserOption extends XFCP_UserOption
{
    public function doesAutoReadAlert(string $contentType, string $action): bool
    {
        $alertPreferences = $this->sv_alert_pref;
        if ($alertPreferences['none'] ?? false)
        {
            return false;
        }

        return $alertPreferences['autoRead'][$contentType][$action]
               ?? $this->getSvAlertPreferencesRepo()->getAlertPreferenceDefault('autoRead', $contentType, $action);
    }

    protected function _setupDefaults()
    {
        parent::_setupDefaults();

        $options = \XF::options();

        $defaults = $options->registrationDefaults;
        $this->sv_alerts_popup_skips_mark_read = (bool)($defaults['sv_alerts_popup_skips_mark_read'] ?? false);
        $this->sv_alerts_page_skips_summarize = (bool)($defaults['sv_alerts_page_skips_summarize'] ?? false);
        $this->sv_alerts_summarize_threshold = (int)($defaults['sv_alerts_summarize_threshold'] ?? 4);
    }

    /**
     * @param Structure $structure
     * @return Structure
     */
    public static function getStructure(Structure $structure)
    {
        $structure = parent::getStructure($structure);

        $structure->columns['sv_alerts_popup_skips_mark_read'] = ['type' => Entity::BOOL, 'default' => 0];
        $structure->columns['sv_alerts_page_skips_summarize'] = ['type' => Entity::BOOL, 'default' => 0];
        $structure->columns['sv_alerts_summarize_threshold'] = ['type' => Entity::UINT, 'default' => 4];

        $structure->columns['sv_alert_pref'] = [
            'type' => self::JSON_ARRAY,
            'default' => null,
            'nullable' => true,
            'changeLog' => false,
        ];

        return $structure;
    }

    public function getSvAlertPreferencesRepo(): AlertPreferences
    {
        /** @noinspection PhpIncompatibleReturnTypeInspection */
        return $this->repository('SV\AlertImprovements:AlertPreferences');
    }
}
