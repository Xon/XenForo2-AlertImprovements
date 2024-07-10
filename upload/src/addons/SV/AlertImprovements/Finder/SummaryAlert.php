<?php

namespace SV\AlertImprovements\Finder;

use SV\StandardLib\Helper;
use XF\Mvc\Entity\AbstractCollection as AbstractCollection;
use XF\Mvc\Entity\Finder as Finder;
use SV\AlertImprovements\Entity\SummaryAlert as SummaryAlertEntity;

 /**
 * @method AbstractCollection<SummaryAlertEntity>|SummaryAlertEntity[] fetch(?int $limit = null, ?int $offset = null)
 * @method SummaryAlertEntity|null fetchOne(?int $offset = null)
 * @implements \IteratorAggregate<string|int,SummaryAlertEntity>
 * @extends Finder<SummaryAlertEntity>
 */
class SummaryAlert extends Finder
{
    /**
      * @return static
      */
    public static function finder(): self
    {
        return Helper::finder(self::class);
    }
}
