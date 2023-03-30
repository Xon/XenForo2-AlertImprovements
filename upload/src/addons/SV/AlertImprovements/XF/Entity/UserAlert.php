<?php
/**
 * @noinspection PhpMissingReturnTypeInspection
 */

namespace SV\AlertImprovements\XF\Entity;

use SV\AlertImprovements\Entity\SummaryAlert;
use XF\Mvc\Entity\Structure;
use function array_key_exists, is_array, preg_match, implode, trim, mb_strtolower;
use function assert;

/**
 * Class UserAlert
 * COLUMNS
 *
 * @property int            $summerize_id
 * @property bool           $auto_read
 * @property bool           $read_date
 * GETTERS
 * @property-read bool      $is_unread
 * @property-read bool      $is_new
 * @property-read bool      $is_summary
 * @property-read UserAlert|null $SummaryAlert
 * @property-read SummaryAlert|null $Summary
 * RELATIONS
 * @property-read SummaryAlert|null $Summary_
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

    protected function getReadDate(): int
    {
        return $this->view_date;
    }

    /** @noinspection PhpMissingParentCallCommonInspection */
    public function isUnread()
    {
        return $this->view_date === 0;
    }

    protected function getIsNew()
    {
        $viewDate = $this->view_date;

        return $viewDate === 0 || $viewDate > \XF::$time - 600 || $this->getOption('force_unread_in_ui');
    }

    /** @noinspection PhpMissingParentCallCommonInspection */
    public function isUnreadInUi(): bool
    {
        if ($this->getOption('force_unread_in_ui'))
        {
            return true;
        }

        return $this->view_date === 0;
    }

    /** @noinspection PhpMissingParentCallCommonInspection */
    public function isRecentlyRead()
    {
        return ($this->view_date !== 0 && $this->view_date >= \XF::$time - 900);
    }

    protected function getIsSummary(): bool
    {
        if ($this->summerize_id === null)
        {
            return (bool)preg_match('/^.*_summary$/', $this->action);
        }

        return false;
    }

    public function getSummary(): ?SummaryAlert
    {
        if (!$this->is_summary)
        {
            return null;
        }

        return $this->Summary;
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

    protected $svIsSummaryAlertSetup = false;
    public function setupSummaryAlert(array $summaryAlert): void
    {
        assert(!$this->exists());

        $this->svIsSummaryAlertSetup = true;
        // we need to treat this as unread for the current request so it can display the way we want
        $this->setOption('force_unread_in_ui', true);
        $this->bulkSet($summaryAlert);

        // denormalize the alert summary data
        $summary = $this->getRelationOrDefault('Summary');
        foreach ($summary->structure()->columns as $column => $def)
        {
            if ($this->isValidColumn($column))
            {
                $value = $this->get($column) ?? $this->_getDeferredValue(function () use ($column) {
                    return $this->get($column);
                }, 'save');

                $summary->set($column, $value);
            }
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

    protected function _postSave()
    {
        if ($this->svIsSummaryAlertSetup && $this->isInsert())
        {
            // stock XF on insert will decrement the counters if inserting with the already read flag set
            if ($this->view_date !== 0)
            {
                unset($this->_newValues['view_date']);
            }
            if ($this->read_date !== 0)
            {
                unset($this->_newValues['read_date']);
            }
        }

        parent::_postSave();
    }

    protected function _postDelete()
    {
        parent::_postDelete();
        $this->db()->query('DELETE FROM xf_sv_user_alert_summary WHERE alert_id  = ?', $this->alert_id);
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
        $structure->getters['Summary'] = ['getter' => 'getSummary', 'cache' => true];

        $structure->relations['SummaryAlert'] = [
            'entity'     => 'XF:UserAlert',
            'type'       => self::TO_ONE,
            'conditions' => [['alert_id', '=', '$summerize_id']],
            'primary'    => true,
        ];
        $structure->relations['Summary'] = [
            'entity'     => 'SV\AlertImprovements:SummaryAlert',
            'type'       => self::TO_ONE,
            'conditions' => 'alert_id',
            'primary'    => true,
        ];

        $structure->options['svAlertImprov'] = true;
        $structure->options['svInjectUserRecordLock'] = true;

        return $structure;
    }
}
