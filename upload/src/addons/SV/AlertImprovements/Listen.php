<?php

namespace SV\AlertImprovements;

use XF\Mvc\Entity\Entity;

class Listen
{
	public static function userOptionEntityStructure(\XF\Mvc\Entity\Manager $em, \XF\Mvc\Entity\Structure &$structure)
	{
		$structure->columns['sv_alerts_page_skips_mark_read'] = ['type' => Entity::UINT, 'default' => 0];
	}
}