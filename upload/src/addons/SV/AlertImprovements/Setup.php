<?php

namespace SV\AlertImprovements;

use XF\AddOn\AbstractSetup;
use XF\Db\Schema\Alter;
use XF\Db\Schema\Create;

class Setup extends AbstractSetup
{
    public function install(array $stepParams = [])
    {
        $sm = \XF::db()->getSchemaManager();
        $sm->alterTable(
            'xf_user_option', function (Alter $table) {
            $this->addOrChangeColumn($table, 'sv_alerts_page_skips_mark_read', 'tinyint')->setDefault(1);
            $this->addOrChangeColumn($table, 'sv_alerts_page_skips_summarize', 'tinyint')->setDefault(0);
            $this->addOrChangeColumn($table, 'sv_alerts_summarize_threshold', 'int')->setDefault(4);
        }
        );
        $sm->alterTable(
            'xf_user_alert', function (Alter $table) {
            $this->addOrChangeColumn($table, 'summerize_id', 'int')->nullable(true);
        }
        );

        /** @var \XF\Entity\Option $entity */
        $entity = \XF::finder('XF:Option')->where(['option_id', 'registrationDefaults'])->fetchOne();
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
        $sm->alterTable(
            'xf_user_option', function (Alter $table) {
            $table->dropColumns('sv_alerts_page_skips_mark_read');
            $table->dropColumns('sv_alerts_page_skips_summarize');
            $table->dropColumns('sv_alerts_summarize_threshold');
        }
        );
        $sm->alterTable(
            'xf_user_alert', function (Alter $table) {
            $table->dropColumns('summerize_id');
        }
        );
    }

    /**
     * @param Create|Alter $table
     * @param string       $name
     * @param string|null  $type
     * @param string|null  $length
     * @return \XF\Db\Schema\Column
     */
    protected function addOrChangeColumn($table, $name, $type = null, $length = null)
    {
        if ($table instanceof Create)
        {
            $table->checkExists(true);

            return $table->addColumn($name, $type, $length);
        }
        else if ($table instanceof Alter)
        {
            if ($table->getColumnDefinition($name))
            {
                return $table->changeColumn($name, $type, $length);
            }

            return $table->addColumn($name, $type, $length);
        }
        else
        {
            throw new \LogicException("Unknown schema DDL type " . get_class($table));
        }
    }
}
