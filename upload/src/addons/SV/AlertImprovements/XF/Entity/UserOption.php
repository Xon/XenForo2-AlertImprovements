<?php
/**
 * @noinspection PhpMissingReturnTypeInspection
 */

namespace SV\AlertImprovements\XF\Entity;

use SV\AlertImprovements\Enum\PopUpReadBehavior;
use SV\AlertImprovements\Repository\AlertPreferences as AlertPreferencesRepo;
use XF\Mvc\Entity\Entity;
use XF\Mvc\Entity\Structure;

/**
 * @extends \XF\Entity\UserOption
 *
 * @property string  $sv_alerts_popup_read_behavior
 * @property bool $sv_alerts_page_skips_summarize
 * @property int  $sv_alerts_summarize_threshold
 * @property array $sv_alert_pref
 * @property ?array $sv_alert_pref_
 */
class UserOption extends XFCP_UserOption
{
    public function isCustomizedAlertPreference(string $contentType, string $action): bool
    {
        $alertPreferences = $this->sv_alert_pref;
        if ($alertPreferences['none'] ?? false)
        {
            return false;
        }

        foreach ($alertPreferences as $perTypeConfig)
        {
            if (isset($perTypeConfig[$contentType][$action]))
            {
                return true;
            }
        }

        return false;
    }

    public function doesAutoReadAlert(string $contentType, string $action): bool
    {
        $alertPreferences = $this->sv_alert_pref;
        if ($alertPreferences['none'] ?? false)
        {
            return false;
        }

        return $alertPreferences['autoRead'][$contentType][$action]
               ?? AlertPreferencesRepo::get()->getAlertPreferenceDefault('autoRead', $contentType, $action);
    }

    protected function _setupDefaults()
    {
        parent::_setupDefaults();

        $options = \XF::options();

        $defaults = $options->registrationDefaults;
        $this->sv_alerts_popup_read_behavior = (string)($defaults['sv_alerts_popup_read_behavior'] ?? PopUpReadBehavior::PerUser);
        $this->sv_alerts_page_skips_summarize = (bool)($defaults['sv_alerts_page_skips_summarize'] ?? false); // boolean values of false don't save
        $this->sv_alerts_summarize_threshold = (int)($defaults['sv_alerts_summarize_threshold'] ?? 4);
    }

    protected function getSvAlertPref(): array
    {
        $alertPreferences = $this->sv_alert_pref_;
        if ($alertPreferences === null && $this->exists())
        {
            $alertPrefsRepo = AlertPreferencesRepo::get();
            $alertPreferences = $alertPrefsRepo->migrateAlertPreferencesForUser($this->user_id);

            if (!$this->_writeRunning)
            {
                $this->sv_alert_pref = $alertPreferences;
            }
            else
            {
                $this->setAsSaved('sv_alert_pref', $alertPreferences);
            }
        }

        return $alertPreferences ?? [];
    }

    /**
     * @param Structure $structure
     * @return Structure
     */
    public static function getStructure(Structure $structure)
    {
        $structure = parent::getStructure($structure);

        $structure->columns['sv_alerts_popup_read_behavior'] = ['type' => Entity::STR, 'default' => PopUpReadBehavior::PerUser, 'allowedValues' => PopUpReadBehavior::get()];
        $structure->columns['sv_alerts_page_skips_summarize'] = ['type' => Entity::BOOL, 'default' => true];
        $structure->columns['sv_alerts_summarize_threshold'] = ['type' => Entity::UINT, 'default' => 4];
        $structure->columns['sv_alert_pref'] = [
            'type' => self::JSON_ARRAY,
            'default' => null,
            'nullable' => true,
            'changeLog' => false,
        ];
        $structure->getters['sv_alert_pref'] = ['getter' => 'getSvAlertPref', 'cache' => true];

        return $structure;
    }

    /**
     * @deprecated
     */
    public function getSvAlertPreferencesRepo(): AlertPreferencesRepo
    {
        return AlertPreferencesRepo::get();
    }
}
