<?php

namespace SV\AlertImprovements;

use XF\AddOn\AbstractSetup;

class Setup extends AbstractSetup
{
	public function install(array $stepParams = [])
	{
		$sm = \XF::db()->getSchemaManager();
		$sm->alterTable('xf_user_option', function(\XF\Db\Schema\Alter $table)
		{
			$table->addColumn('sv_alerts_page_skips_mark_read', 'tinyint')->setDefault(0);
		});
	}

	public function upgrade(array $stepParams = [])
	{
		// TODO: Implement upgrade() method.
	}

	public function uninstall(array $stepParams = [])
	{
		$sm = \XF::db()->getSchemaManager();
		$sm->alterTable('xf_user_option', function(\XF\Db\Schema\Alter $table)
		{
			$table->dropColumns('sv_alerts_page_skips_mark_read');
		});
	}
}