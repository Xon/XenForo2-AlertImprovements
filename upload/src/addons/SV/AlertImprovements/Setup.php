<?php

namespace SV\AlertImprovements;

use SV\Utils\InstallerHelper;
use XF\AddOn\AbstractSetup;
use XF\AddOn\StepRunnerInstallTrait;
use XF\AddOn\StepRunnerUninstallTrait;
use XF\AddOn\StepRunnerUpgradeTrait;
use XF\Db\Schema\Alter;

/**
 * Class Setup
 *
 * @package SV\AlertImprovements
 */
class Setup extends AbstractSetup
{
    // from https://github.com/Xon/XenForo2-Utils cloned to src/addons/SV/Utils
    use InstallerHelper;
    use StepRunnerInstallTrait;
    use StepRunnerUpgradeTrait;
    use StepRunnerUninstallTrait;

    public function installStep1()
    {
        $sm = $this->schemaManager();

        foreach ($this->getAlterTables() as $tableName => $callback)
        {
            if ($sm->tableExists($tableName))
            {
                $sm->alterTable($tableName, $callback);
            }
        }
    }

    public function installStep2()
    {
        $this->applyRegistrationDefaults([
            'sv_alerts_page_skips_mark_read' => 1,
            'sv_alerts_page_skips_summarize' => 0,
            'sv_alerts_summarize_threshold'  => 4
        ]);
    }

    /**
     * @throws \XF\Db\Exception
     */
    public function upgrade2000073Step1()
    {
        $db = $this->db();

        $db->query("
          delete
          from xf_user_alert
          where summerize_id IS NULL AND (action like '%_like_summary' OR action like '%_rate_summary' OR action like '%_rating_summary')
        ");

        $db->query('
          update xf_user_alert
          set summerize_id = null
          where summerize_id IS NOT NULL
        ');
    }

    public function upgrade2020000Step1()
    {
        $this->db()->query("
            update xf_user_alert
            set depends_on_addon_id = 'SV/AlertImprovements'
            where summerize_id IS NULL AND (action like '%_like_summary' OR action like '%_rate_summary' OR action like '%_rating_summary')
        ");
    }

    public function upgrade2050001Step1()
    {
        $this->installStep1();
    }

    public function upgrade2050001Step2()
    {
        $this->installStep2();
    }

    public function upgrade2050001Step3()
    {
        $this->applyRegistrationDefaults([
            'sv_alerts_popup_skips_mark_read' => 0,
        ]);
    }

    public function upgrade2050200Step1()
    {
        $this->db()->query("
            UPDATE xf_user_alert
            SET action = replace(action,'_rate_summary',?)
            WHERE summerize_id IS NULL AND (action LIKE '%_rate_summary')
        ", [\XF::$versionId > 2010000 ? '_rating_summary' : '_reaction_summary']);

        $this->db()->query("
            UPDATE xf_user_alert
            SET action = replace(action,'_like_summary',?)
            WHERE summerize_id IS NULL AND (action LIKE '%_like_summary')
        ", [\XF::$versionId > 2010000 ? '_rating_summary' : '_reaction_summary']);
    }

    public function uninstallStep1()
    {
        $sm = $this->schemaManager();

        foreach ($this->getRemoveAlterTables() as $tableName => $callback)
        {
            if ($sm->tableExists($tableName))
            {
                $sm->alterTable($tableName, $callback);
            }
        }
    }

    /**
     * @throws \XF\Db\Exception
     */
    public function uninstallStep2()
    {
        $this->db()->query("
            delete 
            from xf_user_alert
            where depends_on_addon_id = 'SV/AlertImprovements'
        ");
    }

    /**
     * @return array
     */
    public function getAlterTables()
    {
        $tables = [];

        $tables['xf_user_option'] = function (Alter $table) {
            $this->addOrChangeColumn($table, 'sv_alerts_popup_skips_mark_read', 'tinyint')->setDefault(0);
            $this->addOrChangeColumn($table, 'sv_alerts_page_skips_mark_read', 'tinyint')->setDefault(1);
            $this->addOrChangeColumn($table, 'sv_alerts_page_skips_summarize', 'tinyint')->setDefault(0);
            $this->addOrChangeColumn($table, 'sv_alerts_summarize_threshold', 'int')->setDefault(4);
        };

        $tables['xf_user_alert'] = function (Alter $table) {
            $this->addOrChangeColumn($table, 'summerize_id', 'int')->nullable(true)->setDefault(null);
        };

        return $tables;
    }

    /**
     * @return array
     */
    protected function getRemoveAlterTables()
    {
        $tables = [];

        $tables['addOrChangeColumn'] = function (Alter $table) {
            $table->dropIndexes(['sv_alerts_popup_skips_mark_read', 'sv_alerts_page_skips_mark_read', 'sv_alerts_page_skips_summarize', 'sv_alerts_summarize_threshold']);
        };

        $tables['xf_user_alert'] = function (Alter $table) {
            $table->dropIndexes('summerize_id');
        };

        return $tables;
    }
}
