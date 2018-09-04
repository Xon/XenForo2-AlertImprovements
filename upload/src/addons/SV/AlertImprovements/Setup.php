<?php

namespace SV\AlertImprovements;

use SV\Utils\InstallerHelper;
use XF\AddOn\AbstractSetup;
use XF\AddOn\StepRunnerInstallTrait;
use XF\AddOn\StepRunnerUninstallTrait;
use XF\AddOn\StepRunnerUpgradeTrait;
use XF\Db\Schema\Alter;
use XF\Db\Schema\Create;

class Setup extends AbstractSetup
{
    // from https://github.com/Xon/XenForo2-Utils cloned to src/addons/SV/Utils
    use InstallerHelper;
    use StepRunnerInstallTrait;
    use StepRunnerUpgradeTrait;
    use StepRunnerUninstallTrait;

    public function installStep1()
    {
        $sm = \XF::db()->getSchemaManager();
        $sm->alterTable('xf_user_option', function (Alter $table)
        {
            $this->addOrChangeColumn($table, 'sv_alerts_page_skips_mark_read', 'tinyint')->setDefault(1);
            $this->addOrChangeColumn($table, 'sv_alerts_page_skips_summarize', 'tinyint')->setDefault(0);
            $this->addOrChangeColumn($table, 'sv_alerts_summarize_threshold', 'int')->setDefault(4);
        });
    }

    public function installStep2()
    {
        $sm = \XF::db()->getSchemaManager();
        $sm->alterTable('xf_user_alert', function (Alter $table)
        {
            $this->addOrChangeColumn($table, 'summerize_id', 'int')->nullable(true);
        });
    }

    public function installStep3()
    {
        $this->applyRegistrationDefaults([
            'sv_alerts_page_skips_mark_read' => 1,
            'sv_alerts_page_skips_summarize' => 0,
            'sv_alerts_summarize_threshold'  => 4
        ]);
    }

    public function upgrade2000073Step1()
    {
        $this->db()->query("delete from xf_user_alert where summerize_id IS NULL AND (action like '%_like_summary' OR action like '%_rate_summary' OR action like '%_rating_summary') ");
    }

    public function upgrade2000300Step1()
    {
        $this->installStep1();
    }

    public function upgrade2000300Step2()
    {
        $this->installStep2();
    }

    public function upgrade2000300Step3()
    {
        $this->installStep3();
    }

    public function uninstallStep1()
    {
        $sm = \XF::db()->getSchemaManager();

        $sm->alterTable('xf_user_option', function (Alter $table)
        {
            $table->dropColumns('sv_alerts_page_skips_mark_read');
            $table->dropColumns('sv_alerts_page_skips_summarize');
            $table->dropColumns('sv_alerts_summarize_threshold');
        });
    }

    public function uninstallStep2()
    {
        $this->db()->query("delete from xf_user_alert where summerize_id IS NULL AND `action` like '%_summary' ");
    }

    public function uninstallStep3()
    {
        $sm = \XF::db()->getSchemaManager();

        $sm->alterTable('xf_user_alert', function (Alter $table)
        {
            $table->dropColumns('summerize_id');
        });
    }
}
