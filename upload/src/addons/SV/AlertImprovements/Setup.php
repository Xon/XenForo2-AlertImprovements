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
			$table->addColumn('sv_alerts_page_skips_mark_read', 'tinyint')->setDefault(1);
            $table->addColumn('sv_alerts_page_skips_summarize', 'tinyint')->setDefault(0);
            $table->addColumn('sv_alerts_summarize_threshold', 'int')->setDefault(4);
		});
        $sm->alterTable('xf_user_alert', function(\XF\Db\Schema\Alter $table)
        {
            $table->addColumn('summerize_id', 'int')->nullable(true);
        });

        /** @var \XF\Entity\Option $entity */
        $entity = \XF::finder('XF:Option')->where(['option_id','registrationDefaults'])->fetchOne();
        $registrationDefaults = $entity->option_value;
        if (!isset($registrationDefaults['sv_alerts_page_skips_mark_read']))
        {
            $registrationDefaults['sv_alerts_page_skips_mark_read'] = 0;
        }
        if (!isset($registrationDefaults['sv_alerts_page_skips_summarize']))
        {
            $registrationDefaults['sv_alerts_page_skips_summarize'] = 0;
        }
        if (!isset($registrationDefaults['sv_alerts_summarize_threshold']))
        {
            $registrationDefaults['sv_alerts_summarize_threshold'] = 4;
        }
        $entity->option_value = $registrationDefaults;
        $entity->saveIfChanged();
	}

	public function upgrade(array $stepParams = [])
	{
		$this->install($stepParams);
	}

	public function uninstall(array $stepParams = [])
	{
		$sm = \XF::db()->getSchemaManager();
		$sm->alterTable('xf_user_option', function(\XF\Db\Schema\Alter $table)
		{
			$table->dropColumns('sv_alerts_page_skips_mark_read');
            $table->dropColumns('sv_alerts_page_skips_summarize');
            $table->dropColumns('sv_alerts_summarize_threshold');
		});
        $sm->alterTable('xf_user_alert', function(\XF\Db\Schema\Alter $table)
        {
            $table->dropColumns('summerize_id');
        });
	}
}
