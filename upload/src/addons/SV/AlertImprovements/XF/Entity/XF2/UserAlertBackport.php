<?php


namespace SV\AlertImprovements\XF\Entity\XF2;

use SV\AlertImprovements\XF\Entity\XFCP_UserAlertBackport;
use XF\Mvc\Entity\Structure;

/**
 * Class UserAlert
 * COLUMNS
 * @property bool auto_read
 *
 */
class UserAlertBackport extends XFCP_UserAlertBackport
{
    /**
     * @return bool
     */
    public function isUnread()
    {
        return !$this->view_date;
    }

    /**
     * @return bool
     */
    public function isUnreadInUi(): bool
    {
        if ($this->getOption('force_unread_in_ui'))
        {
            return true;
        }

        return !$this->view_date;
    }

    public function isRecentlyRead()
    {
        return ($this->view_date && $this->view_date >= \XF::$time - 900);
    }

    protected function _preSave()
    {
        $extra = $this->extra_data;
        if (isset($extra['autoRead']))
        {
            $this->auto_read = $extra['autoRead'];
            unset($extra['autoRead']);
            $this->extra_data = $extra;
        }

        parent::_preSave();
    }

    /**
     * @param Structure $structure
     * @return Structure
     */
    public static function getStructure(Structure $structure)
    {
        $structure = parent::getStructure($structure);

        $structure->columns['auto_read'] = ['type' => self::BOOL, 'default' => true];
        $structure->options = [
            'force_unread_in_ui' => false
        ];
        return $structure;
    }
}
