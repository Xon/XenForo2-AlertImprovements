<?php


namespace SV\AlertImprovements\XF\Entity;

use XF\Mvc\Entity\AbstractCollection;
use XF\Mvc\Entity\Structure;

/**
 * Class UserAlert
 * COLUMNS
 * @property int                                      summerize_id
 *
 * GETTERS
 * @property bool                                     is_unread
 * @property bool                                     is_new
 * @property bool                                     is_summary
 * @property UserAlert                                SummaryAlert
 * @property \XF\Entity\Reaction[]|AbstractCollection sv_rating_types
 */
class UserAlert extends XFCP_UserAlert
{
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
        return !$this->view_date;
    }

    protected function getIsNew()
    {
        $viewDate = $this->view_date;

        return $viewDate === 0 || $viewDate > \XF::$time - 600;
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

    /**
     * @return null|\XF\Mvc\Entity\ArrayCollection
     */
    protected function getSvRatingTypes()
    {
        if (isset($this->extra_data['extra_data']['reaction_id']) &&
            is_array($this->extra_data['extra_data']['reaction_id']))
        {
            $ratings = $this->extra_data['extra_data']['reaction_id'];

            /** @var \SV\ContentRatings\XF\Repository\Reaction $ratingTypeRepo */
            $ratingTypeRepo = $this->repository('SV\ContentRatings:RatingType');
            $ratingTypes = $ratingTypeRepo->getReactionsAsEntities();

            return $ratingTypes->filter(function ($item) use ($ratings) {
                /** @noinspection PhpUndefinedClassInspection */
                /** @var \SV\ContentRatings\Entity\RatingType|\XF\Entity\Reaction $item */
                return isset($ratings[$item->reaction_id]);
            });
        }

        return null;
    }

    /**
     * @param string $glue
     * @return string
     */
    public function getLikedContentSummary($glue = ' ')
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
                        $phrases[] = \XF::phraseDeferred('sv_x_of_y_content_type', ['count' => $count, 'contentType' => \utf8_strtolower($contentTypePhrase)]);
                    }
                }
            }

            if ($phrases)
            {
                return implode($glue, $phrases);
            }
        }

        return '';
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

        parent::_preSave();
    }

    /**
     * @param Structure $structure
     * @return Structure
     */
    public static function getStructure(Structure $structure)
    {
        $structure = parent::getStructure($structure);

        if (\XF::$versionId < 2020000)
        {
            $structure->columns['auto_read'] = ['type' => self::BOOL, 'default' => true];
            $structure->options['force_unread_in_ui'] = false;
        }

        $structure->columns['summerize_id'] = ['type' => self::UINT, 'nullable' => true, 'default' => null];

        $structure->getters['read_date'] = ['getter' => 'getReadDate', 'cache' => false];
        $structure->getters['is_new'] = ['getter' => 'getIsNew', 'cache' => true];
        $structure->getters['is_summary'] = ['getter' => 'getIsSummary', 'cache' => true];
        $structure->getters['sv_rating_types'] = ['getter' => 'getSvRatingTypes', 'cache' => true];

        $structure->relations['SummaryAlert'] = [
            'entity'     => 'XF:UserAlert',
            'type'       => self::TO_ONE,
            'conditions' => [['alert_id', '=', '$summerize_id']],
            'primary'    => true,
        ];

        return $structure;
    }
}
