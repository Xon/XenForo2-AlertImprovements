<?php

namespace SV\AlertImprovements;

use SV\AlertImprovements\Enum\PopUpReadBehavior;
use SV\AlertImprovements\Job\AlertTotalRebuild;
use SV\AlertImprovements\Job\MigrateAlertPreferences;
use SV\StandardLib\Helper;
use SV\StandardLib\InstallerHelper;
use XF\AddOn\AbstractSetup;
use XF\AddOn\StepRunnerInstallTrait;
use XF\AddOn\StepRunnerUninstallTrait;
use XF\AddOn\StepRunnerUpgradeTrait;
use XF\Db\Schema\Alter;
use XF\Db\Schema\Create;
use XF\Entity\AddOn as AddOnEntity;
use XF\Entity\Option as OptionEntity;
use XF\Entity\Template;
use XF\Entity\User as UserEntity;
use XF\Job\PermissionRebuild;
use XF\PreEscaped;
use function array_keys;
use function max;
use function microtime;
use function min;
use function strpos;
use function version_compare;

class Setup extends AbstractSetup
{
    use InstallerHelper {
        checkRequirements as protected checkRequirementsTrait;
    }
    use StepRunnerInstallTrait;
    use StepRunnerUpgradeTrait;
    use StepRunnerUninstallTrait;

    public function installStep1(): void
    {
        $this->applySchemaChanges();
    }

    public function applySchemaChanges(): void
    {
        $sm = $this->schemaManager();

        foreach ($this->getTables() as $tableName => $callback)
        {
            $sm->createTable($tableName, $callback);
            $sm->alterTable($tableName, $callback);
        }

        foreach ($this->getAlterTables() as $tableName => $callback)
        {
            if ($sm->tableExists($tableName))
            {
                $sm->alterTable($tableName, $callback);
            }
        }
    }

    public function installStep2(): void
    {
        $this->applyRegistrationDefaults([
            'sv_prompt_on_mark_read'         => 1,
            'sv_alerts_popup_read_behavior'  => PopUpReadBehavior::PerUser,
            'sv_alerts_page_skips_summarize' => 1,
            'sv_alerts_summarize_threshold'  => 4,
        ]);
    }

    public function installStep3(): void
    {
        $this->applyDefaultPermissions(0);
    }

    public function upgrade2050001Step1(): void
    {
        $this->applySchemaChanges();
    }

    public function upgrade2050001Step2(): void
    {
        $this->installStep2();
    }

    public function upgrade2050001Step3(): void
    {
        $this->applyRegistrationDefaults([
            'sv_alerts_popup_read_behavior' => PopUpReadBehavior::PerUser,
        ]);
    }

    public function upgrade2050201Step1(): void
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

    public function upgrade2050201Step2(): void
    {
        $this->db()->query("
            UPDATE xf_user_alert
            SET depends_on_addon_id = 'SV/AlertImprovements'
            WHERE summerize_id IS NULL AND action IN ('like_summary', 'rate_summary', 'rating_summary')
        ");
    }

    public function upgrade2080002Step1(): void
    {
        $this->applySchemaChanges();
    }

    public function upgrade2080100Step1(): void
    {
        $this->renameOption('sv_alerts_summerize', 'svAlertsSummarize');
        $this->renameOption('sv_alerts_summerize_flood', 'svAlertsSummarizeFlood');
        $this->renameOption('sv_alerts_groupByDate', 'svAlertsGroupByDate');
    }

    public function upgrade2080606Step1(): void
    {
        $this->applySchemaChanges();
    }

    public function upgrade2081101Step1(array $stepParams): ?array
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

        $finder = Helper::finder(\XF\Finder\Template::class)
                     ->where('title', '=', array_keys($templateRenames));
        $stepData = $stepParams[2] ?? [];
        if (!isset($stepData['max']))
        {
            $stepData['max'] = $finder->total();
        }
        $templates = $finder->fetch();
        if ($templates->count() === 0)
        {
            return null;
        }

        $next = $stepParams[0] ?? 0;
        $maxRunTime = max(min(\XF::app()->config('jobMaxRunTime'), 4), 1);
        $startTime = microtime(true);
        foreach($templates as $template)
        {
            /** @var Template $template*/
            if (empty($templateRenames[$template->title]))
            {
                continue;
            }

            $next++;

            $template->title = $templateRenames[$template->title];
            $template->version_id = 2081101;
            $template->version_string = '2.8.11';
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

    public function upgrade2081500Step2(): void
    {
        // purge broken jobs
        $this->db()->query('DELETE FROM xf_job WHERE unique_key IN (?,?)', ['sViewedAlertCleanup', 'svUnviewedAlertCleanup']);
    }

    public function upgrade2090005Step1(): void
    {
        $this->schemaManager()->alterTable('xf_user_option', function(Alter $table) {
            $table->dropColumns('sv_alerts_page_skips_mark_read');
        });
    }

    public function upgrade1683812804Step1(): void
    {
        $this->applySchemaChanges();
    }

    public function upgrade1683812804Step2(): void
    {
        $this->installStep2();
    }

    public function upgrade1683812804Step3(): void
    {
        $this->query('
            INSERT INTO xf_sv_user_alert_summary (alert_id, alerted_user_id, content_type, content_id, `action`)
            SELECT alert_id, alerted_user_id, content_type, content_id, `action`
            FROM xf_user_alert
            WHERE summerize_id IS NULL AND `action` LIKE \'%_summary\';
        ');
    }

    public function upgrade1685991237Step1(): void
    {
        $this->applySchemaChanges();
    }

    public function upgrade1685991237Step2(): void
    {
        $sm = $this->schemaManager();
        if ($sm->columnExists('xf_user_option', 'sv_alerts_popup_skips_mark_read'))
        {
            /** @noinspection SqlResolve */
            $this->db()->query('
                UPDATE xf_user_option
                SET sv_alerts_popup_read_behavior = ?
                WHERE sv_alerts_popup_skips_mark_read = 1
            ', [PopUpReadBehavior::NeverMarkRead]);
        }
    }

    public function upgrade1685991237Step3(): void
    {
        $this->schemaManager()->alterTable('xf_user_option', function (Alter $table) {
            $table->dropColumns('sv_alerts_popup_skips_mark_read');
        });
    }

    public function upgrade1685991237Step4(): void
    {
        $option = Helper::find(OptionEntity::class, 'registrationDefaults');
        $registrationDefaults = $option->option_value;

        $intVal = (int)($registrationDefaults['sv_alerts_popup_skips_mark_read'] ?? 0);
        if ($intVal === 1)
        {
            $registrationDefaults['sv_alerts_popup_read_behavior'] = PopUpReadBehavior::NeverMarkRead;
        }
        else
        {
            $registrationDefaults['sv_alerts_popup_read_behavior'] = PopUpReadBehavior::PerUser;
        }

        $option->option_value = $registrationDefaults;
        $option->saveIfChanged();
    }

    public function upgrade1695694021Step1(): void
    {
        $this->renamePhrases([
            'svAlertImprov_alerts_page_and_summary' => 'svAlertImprov_alert_preferences_header',
        ]);
    }

    public function upgrade1685991237Step5(): void
    {
        $db = $this->db();
        $perUser = $db->quote(PopUpReadBehavior::PerUser);
        $neverMarkRead = $db->quote(PopUpReadBehavior::NeverMarkRead);

        $db->query("
            UPDATE xf_change_log
            SET field = ?, 
                old_value = (case when old_value = 0 then $perUser when old_value = 1 then $neverMarkRead else old_value end),
                new_value = (case when new_value = 0 then $perUser when new_value = 1 then $neverMarkRead else new_value end)
            WHERE field = ?
        ", ['sv_alerts_popup_read_behavior', 'sv_alerts_popup_skips_mark_read']);
    }

    public function upgrade1723557306Step1(): void // 2.12.2
    {
        $this->applySchemaChanges();
    }

    public function upgrade1723557306Step2(): void // 2.12.2
    {
        $this->applyRegistrationDefaults([
            'sv_prompt_on_mark_read'         => 1,
        ]);
    }

    public function uninstallStep1(): void
    {
        $sm = $this->schemaManager();

        foreach ($this->getTables() as $tableName => $callback)
        {
            $sm->dropTable($tableName);
        }
    }

    public function uninstallStep2(): void
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

    public function uninstallStep3(): void
    {
        $this->db()->query("
            DELETE 
            FROM xf_user_alert
            WHERE depends_on_addon_id = 'SV/AlertImprovements'
        ");
    }

    public function postInstall(array &$stateChanges): void
    {
        parent::postInstall($stateChanges);

        \XF::app()->jobManager()->enqueueUnique('svMigrateAlertPreferences', MigrateAlertPreferences::class, [], false);
    }

    public function postUpgrade($previousVersion, array &$stateChanges): void
    {
        $previousVersion = (int)$previousVersion;
        parent::postUpgrade($previousVersion, $stateChanges);

        if ($this->applyDefaultPermissions($previousVersion))
        {
            \XF::app()->jobManager()->enqueueUnique('permissionRebuild', PermissionRebuild::class, [], true);
        }

        if ($previousVersion >= 2080000 && $previousVersion < 2080400)
        {
            \XF::app()->jobManager()->enqueueUnique('svAlertTotalRebuild', AlertTotalRebuild::class, [], true);
        }
        if ($previousVersion < 1683812804)
        {
            \XF::app()->jobManager()->enqueueUnique('svMigrateAlertPreferences', MigrateAlertPreferences::class, [], false);
        }
    }

    protected function applyDefaultPermissions(int $previousVersion = 0): bool
    {
        $applied = false;

        if ($previousVersion < 1695694020)
        {
            $this->applyGlobalPermissionByGroup('general', 'svCustomizeAdvAlertPrefs', [
                UserEntity::GROUP_REG,
                UserEntity::GROUP_MOD,
                UserEntity::GROUP_ADMIN,
            ]);

            $applied = true;
        }

        return $applied;
    }

    public function getTables(): array
    {
        $tables = [];

        $tables['xf_sv_user_alert_rebuild'] = function ($table) {
            /** @var Alter|Create $table */
            $this->addOrChangeColumn($table, 'user_id', 'int')->primaryKey();
            $this->addOrChangeColumn($table, 'rebuild_date', 'int');
            $table->addKey('rebuild_date');
        };

        $tables['xf_sv_user_alert_summary'] = function ($table ) {
            /** @var Alter|Create $table */
            $this->addOrChangeColumn($table, 'alert_id', 'int')->primaryKey();
            $this->addOrChangeColumn($table, 'alerted_user_id', 'int');
            $this->addOrChangeColumn($table, 'content_type', 'varbinary', 25);
            $this->addOrChangeColumn($table, 'content_id', 'int');
            $this->addOrChangeColumn($table, 'action', 'varbinary', 30);

            $table->addKey(['alerted_user_id', 'content_type', 'content_id', 'action'], 'alerted_user_id_content_type_content_id_action');
        };

        return $tables;
    }

    public function getAlterTables(): array
    {
        $tables = [];

        $tables['xf_user_option'] = function (Alter $table) {
            $this->addOrChangeColumn($table, 'sv_prompt_on_mark_read', 'tinyint')->setDefault(1);
            $this->addOrChangeColumn($table, 'sv_alerts_popup_read_behavior', 'enum')
                 ->values(PopUpReadBehavior::get())
                 ->setDefault(PopUpReadBehavior::PerUser);
            $this->addOrChangeColumn($table, 'sv_alerts_page_skips_summarize', 'tinyint')->setDefault(1);
            $this->addOrChangeColumn($table, 'sv_alerts_summarize_threshold', 'int')->setDefault(4);
            $this->addOrChangeColumn($table, 'sv_alert_pref', 'blob')->nullable()->setDefault(null);
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

            // ensure summerize_id type matches the alert_id column
            $rawType = $table->getColumnDefinition('alert_id')['Type'] ?? 'int(10) unsigned';
            $idType = strpos($rawType, 'bigint') !== false ? 'bigint' : 'int';
            $this->addOrChangeColumn($table, 'summerize_id', $idType)->nullable(true)->setDefault(null);

            // index is superseded
            $table->dropIndexes(['contentType_contentId', 'alertedUserId_viewDate']);

            // for basic content type lookups
            $table->addKey(['content_type', 'content_id', 'user_id']);

            // primarily for looking up active alerts for a user from a set of content -- content_id will generally
            // be a multiple element list, so further columns aren't particularly helpful
            $table->addKey(['alerted_user_id', 'content_type', 'content_id']);

            // for unviewed calculations
            $table->addKey(['alerted_user_id', 'view_date']);
            // for summarization
            $table->addKey(['alerted_user_id', 'summerize_id'], 'alerted_user_id_summerize_id');
        };

        $tables['xf_user'] = function (Alter $table) {
            $this->addOrChangeColumn($table,'alerts_unviewed', 'smallint', 5)->setDefault(0)->after('trophy_points');
        };

        return $tables;
    }

    protected function getRemoveAlterTables(): array
    {
        $tables = [];

        $tables['xf_user_option'] = function (Alter $table) {
            $table->dropColumns([
                'sv_alert_pref',
                'sv_alerts_popup_skips_mark_read',
                'sv_alerts_popup_read_behavior',
                'sv_alerts_page_skips_mark_read',
                'sv_alerts_page_skips_summarize',
                'sv_alerts_summarize_threshold',
            ]);
        };

        $tables['xf_user_alert'] = function (Alter $table) {
            $table->dropIndexes('summerize_id');
        };

        return $tables;
    }

    /**
     * @param array $errors
     * @param array $warnings
     * @noinspection PhpMissingParentCallCommonInspection
     */
    public function checkRequirements(&$errors = [], &$warnings = []): void
    {
        $this->checkRequirementsTrait($errors, $warnings);

        if ($this->isCliRecommendedCheck(0, 0, 500000, 50000))
        {
            /** @var ?AddOnEntity $addon */
            $addon = $this->addOn->getInstalledAddOn();
            if ($addon !== null && version_compare($addon->version_string, '2.9.5', '<='))
            {
                $html = 'For busy sites, it is recommended to close the site when upgrading from before v2.10.0 as to avoid alerts being generated with the wrong auto-read/mark-as-read configuration while user settings are migrated';
                $warnings[] = new PreEscaped($html);
            }
        }
    }
}
