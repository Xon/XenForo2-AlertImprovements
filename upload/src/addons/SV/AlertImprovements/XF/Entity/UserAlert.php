<?php
/**
 * @noinspection PhpMissingReturnTypeInspection
 */

namespace SV\AlertImprovements\XF\Entity;

use XF\Mvc\Entity\Structure;
use function array_key_exists, is_array, preg_match, implode, trim, mb_strtolower;

/**
 * Class UserAlert
 * COLUMNS
 * @property int                                      summerize_id
 * @property bool                                     auto_read
 * @property bool                                     read_date
 *
 * GETTERS
 * @property bool                                     is_unread
 * @property bool                                     is_new
 * @property bool                                     is_summary
 * @property UserAlert                                SummaryAlert
 */
class UserAlert extends XFCP_UserAlert
{
    public function getHandler()
    {
        if (array_key_exists('Handler', $this->_getterCache))
        {
            return $this->_getterCache['Handler'];
        }

        $this->_getterCache['Handler'] = parent::getHandler();

        return $this->_getterCache['Handler'];
    }

    /**
     * @return int
     */
    protected function getReadDate()
    {
        return $this->view_date;
    }

    /**
     * @return bool
     */
    public function isUnread()
    {
        return $this->view_date === 0;
    }

    protected function getIsNew()
    {
        $viewDate = $this->view_date;

        return $viewDate === 0 || $viewDate > \XF::$time - 600 || $this->getOption('force_unread_in_ui');
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

        return $this->view_date === 0;
    }

    public function isRecentlyRead()
    {
        return ($this->view_date !== 0 && $this->view_date >= \XF::$time - 900);
    }

    /**
     * @return bool
     */
    protected function getIsSummary()
    {
        if ($this->summerize_id === null)
        {
            return (bool)preg_match('/^.*_summary$/', $this->action);
        }

        return false;
    }

    public function getReactedContentSummary(string $glue = ' '): string
    {
        $extra = $this->extra_data;
        if (isset($extra['ct']) && is_array($extra['ct']))
        {
            $phrases = [];
            foreach ($extra['ct'] as $contentType => $count)
            {
                if ($count)
                {
                    $contentTypePhrase = \XF::app()->getContentTypePhrase($contentType, $count > 1);
                    if ($contentTypePhrase)
                    {
                        $phrases[] = \XF::phraseDeferred('sv_x_of_y_content_type', ['count' => $count, 'contentType' => mb_strtolower($contentTypePhrase)]);
                    }
                }
            }

            if ($phrases)
            {
                return trim(implode($glue, $phrases));
            }
        }

        return '';
    }

    protected function forceSetAutoRead(): void
    {
        if (!$this->auto_read && $this->depends_on_addon_id === '')
        {
            // stock XF injects autoRead flags in most alert types even if it doesn't make any sense
            $this->auto_read = true;
        }
    }

    protected function _preSave()
    {
        $this->read_date = $this->view_date;

        $extra = $this->extra_data;
        if (isset($extra['autoRead']))
        {
            $this->auto_read = $extra['autoRead'];
            unset($extra['autoRead']);
            $this->extra_data = $extra;
        }
        else
        {
            $this->forceSetAutoRead();
        }

        parent::_preSave();
    }

    protected function _saveToSource()
    {
        if ($this->isInsert() && $this->getOption('svInjectUserRecordLock'))
        {
            // Use an update-lock on the user record, to avoid deadlocks.
            // This ensuring consistent table lock ordering when marking as read/unread & alert summarization
            // This is only needed during insert, as read_date/view_date are updated in other transactions after the user record is locked
            $userId = $this->alerted_user_id;
            if ($userId !== 0)
            {
                $this->db()->fetchOne('SELECT user_id FROM xf_user WHERE user_id = ? FOR UPDATE', [$userId]);
            }
        }

        parent::_saveToSource();
    }

    /**
     * @param Structure $structure
     * @return Structure
     */
    public static function getStructure(Structure $structure)
    {
        $structure = parent::getStructure($structure);

        // Pre-XF2.2.9 bug, where UINT assumes 32bit value, so explicitly set 'max'
        $structure->columns['summerize_id'] = ['type' => self::UINT, 'max' => \PHP_INT_MAX, 'nullable' => true, 'default' => null];

        $structure->getters['read_date'] = ['getter' => 'getReadDate', 'cache' => false];
        $structure->getters['is_new'] = ['getter' => 'getIsNew', 'cache' => true];
        $structure->getters['is_summary'] = ['getter' => 'getIsSummary', 'cache' => true];

        $structure->relations['SummaryAlert'] = [
            'entity'     => 'XF:UserAlert',
            'type'       => self::TO_ONE,
            'conditions' => [['alert_id', '=', '$summerize_id']],
            'primary'    => true,
        ];

        $structure->options['svAlertImprov'] = true;
        $structure->options['svInjectUserRecordLock'] = true;

        return $structure;
    }
}
