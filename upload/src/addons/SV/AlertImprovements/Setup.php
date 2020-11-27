<?php

namespace SV\AlertImprovements;

use SV\StandardLib\InstallerHelper;
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
            'sv_alerts_popup_skips_mark_read' => 0,
            'sv_alerts_page_skips_mark_read'  => 0,
            'sv_alerts_page_skips_summarize'  => 0,
            'sv_alerts_summarize_threshold'   => 4,
        ]);
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

    public function upgrade2050201Step1()
    {
        $db = $this->db();

        $db->query("
          DELETE
          FROM xf_user_alert
          WHERE summerize_id IS NULL AND action IN ('like_summary', 'rate_summary', 'rating_summary')
        ");

        $db->query('
          UPDATE xf_user_alert
          SET summerize_id = NULL
          WHERE summerize_id IS NOT NULL
        ');
    }

    public function upgrade2050201Step2()
    {
        $this->db()->query("
            UPDATE xf_user_alert
            SET depends_on_addon_id = 'SV/AlertImprovements'
            WHERE summerize_id IS NULL AND action IN ('like_summary', 'rate_summary', 'rating_summary')
        ");
    }


    public function upgrade2080002Step1()
    {
        $this->installStep1();
    }

    public function upgrade2080002Step2()
    {
        if (\XF::$versionId >= 2020000)
        {
            return;
        }

        /** @noinspection SqlWithoutWhere */
        $this->executeUpgradeQuery("
			UPDATE xf_user_alert
			SET read_date = view_date
		");
    }

    public function upgrade2080002Step3()
    {
        if (\XF::$versionId >= 2020000)
        {
            return;
        }

        /** @noinspection SqlWithoutWhere */
        $this->executeUpgradeQuery("
			UPDATE xf_user
			SET alerts_unviewed = alerts_unread
		");
    }

    public function upgrade2080100Step1()
    {
        $this->renameOption('sv_alerts_summerize', 'svAlertsSummarize');
        $this->renameOption('sv_alerts_summerize_flood', 'svAlertsSummarizeFlood');
        $this->renameOption('sv_alerts_groupByDate', 'svAlertsGroupByDate');
    }

    public function upgrade2080606Step1()
    {
        $this->installStep1();
    }

//    public function upgrade2080002Step5()
//    {
//        /** @var \XF\Entity\Option $option */
//        $option = \XF::app()->finder('XF:Option')
//                     ->where('option_id', '=', 'registrationDefaults')
//                     ->fetchOne();
//        $registrationDefaults = $option->option_value;
//        $registrationDefaults['sv_alerts_popup_skips_mark_read'] = 0;
//        $registrationDefaults['sv_alerts_page_skips_mark_read'] = 0;
//        $option->option_value = $registrationDefaults;
//        $option->saveIfChanged();
//    }
//
//    public function upgrade2080002Step6()
//    {
//        $this->db()->update('xf_user_option', [
//            'sv_alerts_popup_skips_mark_read' => 0,
//            'sv_alerts_page_skips_mark_read' => 0,
//        ], '');
//    }

    public function upgrade2081101Step1(array $stepParams)
    {
        $templateRenames = [
            // admin - pre-2.7
            'user_edits_alerts' => 'svAlertImprov_user_edits_alerts',
            'option_template_registrationDefaults_alerts' => 'svAlertImprov_option_template_registrationDefaults',
            // public - pre-2.7
            'account_alerts_summary' => 'svAlertImprov_account_alerts_summary',
            'account_preferences_alerts_extra' => 'svAlertImprov_account_preferences_extra',
            // admin - 2.7 - 2.8
            'svAlertsImprov_user_edits_alerts' => 'svAlertImprov_user_edits_alerts',
            'svAlertsImprov_option_template_registrationDefaults' => 'svAlertImprov_option_template_registrationDefaults',
            // public - 2.7 - 2.8
            'svAlertsImprov_account_preferences_extra' => 'svAlertImprov_account_preferences_extra',
            'svAlertsImprov_account_alerts_summary' => 'svAlertImprov_account_alerts_summary',
            'svAlertsImprov_account_alerts_popup' => 'svAlertImprov_account_alerts_popup',
            'svAlertsImprov_alerts' => 'svAlertImprov_alerts',
            'svAlertsImprov_macros' => 'svAlertImprov_macros',
        ];

        $finder = \XF::finder('XF:Template')
                     ->where('title', '=', \array_keys($templateRenames));
        $stepData = isset($stepParams[2]) ? $stepParams[2] : [];
        if (!isset($stepData['max']))
        {
            $stepData['max'] = $finder->total();
        }
        $templates = $finder->fetch();
        if (!$templates->count())
        {
            return null;
        }

        $next = isset($stepParams[0]) ? $stepParams[0] : 0;
        $maxRunTime = max(min(\XF::app()->config('jobMaxRunTime'), 4), 1);
        $startTime = \microtime(true);
        foreach($templates as $template)
        {
            /** @var \XF\Entity\Template $template*/
            if (empty($templateRenames[$template->title]))
            {
                continue;
            }

            $next++;

            $template->title = $templateRenames[$template->title];
            $template->version_id = 2081101;
            $template->version_string = "2.8.11";
            $template->save(false, true);

            if (microtime(true) - $startTime >= $maxRunTime)
            {
                break;
            }
        }

        return [
            $next,
            "{$next} / {$stepData['max']}",
            $stepData
        ];
    }

    public function upgrade2081101Step2()
    {
        $this->renamePhrases([
            'svAlerts_select_all_for'        => 'svAlertImprov_select_all_for',
            'sv_alertimprovements.today'     => 'svAlertImprov_date.today',
            'sv_alertimprovements.yesterday' => 'svAlertImprov_date.yesterday',
            'sv_alerts_page_and_summary'     => 'svAlertImprov_alerts_page_and_summary',
            'sv_alert_preferences'           => 'svAlertImprov_alert_preferences',
            'sv_on_viewing_alerts_page'      => 'svAlertImprov_on_viewing_alerts_page',
            'sv_resummarize_alerts'          => 'svAlertImprov_resummarize_alerts',
            'sv_unread_alert'                => 'svAlertImprov_new_or_unread_alert',
        ]);
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
            DELETE 
            FROM xf_user_alert
            WHERE depends_on_addon_id = 'SV/AlertImprovements'
        ");
    }

    public function postUpgrade($previousVersion, array &$stateChanges)
    {
        if ($previousVersion >= 2080000 && $previousVersion < 2080400)
        {
            \XF::app()->jobManager()->enqueueUnique('svAlertTotalRebuild', 'SV\AlertImprovements:AlertTotalRebuild', [], true);
        }
    }

    /**
     * @return array
     */
    public function getAlterTables()
    {
        $tables = [];

        $tables['xf_user_option'] = function (Alter $table) {
            $this->addOrChangeColumn($table, 'sv_alerts_popup_skips_mark_read', 'tinyint')->setDefault(0);
            $this->addOrChangeColumn($table, 'sv_alerts_page_skips_mark_read', 'tinyint')->setDefault(0);
            $this->addOrChangeColumn($table, 'sv_alerts_page_skips_summarize', 'tinyint')->setDefault(0);
            $this->addOrChangeColumn($table, 'sv_alerts_summarize_threshold', 'int')->setDefault(4);
        };

        $tables['xf_user_alert'] = function (Alter $table) {
            $hasReadDate = (bool)$table->getColumnDefinition('read_date');
            $hasAutoRead = (bool)$table->getColumnDefinition('auto_read');

            $this->addOrChangeColumn($table, 'read_date', 'int')->setDefault(0)->after('view_date');
            $col2 = $this->addOrChangeColumn($table, 'auto_read', 'tinyint')->setDefault(1);
            if ($hasReadDate || !$hasAutoRead)
            {
                // XF always does modifies before adds.
                $col2->after('read_date');
            }

            $this->addOrChangeColumn($table, 'summerize_id', 'int')->nullable(true)->setDefault(null);

            // index is superseded
            $table->dropIndexes(['contentType_contentId', 'alertedUserId_viewDate']);

            // for basic content type lookups
            $table->addKey(['content_type', 'content_id', 'user_id']);

            // primarily for looking up active alerts for a user from a set of content -- content_id will generally
            // be a multiple element list, so further columns aren't particularly helpful
            $table->addKey(['alerted_user_id', 'content_type', 'content_id']);

            // for unviewed calculations
            $table->addKey(['alerted_user_id', 'view_date']);
        };

        $tables['xf_user'] = function (Alter $table) {
            $this->addOrChangeColumn($table,'alerts_unviewed', 'smallint', 5)->setDefault(0)->after('trophy_points');
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
