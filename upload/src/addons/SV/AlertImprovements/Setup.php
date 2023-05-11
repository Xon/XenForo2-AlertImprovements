<?php

namespace SV\AlertImprovements;

use SV\AlertImprovements\Repository\AlertPreferences;
use SV\StandardLib\InstallerHelper;
use XF\AddOn\AbstractSetup;
use XF\AddOn\StepRunnerInstallTrait;
use XF\AddOn\StepRunnerUninstallTrait;
use XF\AddOn\StepRunnerUpgradeTrait;
use XF\Db\Schema\Alter;
use XF\Db\Schema\Create;
use XF\Entity\Template;
use XF\PrintableException;
use XF\Util\Arr;
use function count;
use function implode;
use function json_decode;
use function json_encode;
use function min, max, microtime, array_keys, strpos;

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

    public function installStep1(): void
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
            'sv_alerts_popup_skips_mark_read' => '',
            'sv_alerts_page_skips_summarize'  => '',
            'sv_alerts_summarize_threshold'   => 4,
        ]);
    }

    public function upgrade2050001Step1(): void
    {
        $this->installStep1();
    }

    public function upgrade2050001Step2(): void
    {
        $this->installStep2();
    }

    public function upgrade2050001Step3(): void
    {
        $this->applyRegistrationDefaults([
            'sv_alerts_popup_skips_mark_read' => 0,
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
        $this->installStep1();
    }

    public function upgrade2080100Step1(): void
    {
        $this->renameOption('sv_alerts_summerize', 'svAlertsSummarize');
        $this->renameOption('sv_alerts_summerize_flood', 'svAlertsSummarizeFlood');
        $this->renameOption('sv_alerts_groupByDate', 'svAlertsGroupByDate');
    }

    public function upgrade2080606Step1(): void
    {
        $this->installStep1();
    }

    /**
     * @param array $stepParams
     * @return array|null
     * @throws PrintableException
     */
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

        $finder = \XF::finder('XF:Template')
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
        $this->installStep1();
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

    public function upgrade1683812804Step4(array $stepData): ?array
    {
        $columns = [
            "alert_optout <> ''",
            "push_optout <> ''",
        ];

        if ($this->columnExists('xf_user_option', 'sv_skip_auto_read_for_op'))
        {
            $columns[] = 'sv_skip_auto_read_for_op = 0';
        }

        if ($this->columnExists('xf_user_option', 'nf_discord_optout'))
        {
            $columns[] = "nf_discord_optout <> ''";
        }
        $sqlWhere = '(('. implode(') OR (', $columns) .'))';

        $db = $this->db();
        $next = $stepData['userId'] ?? 0;
        if (!isset($stepData['max']))
        {
            $stepData['max'] = (int)$db->fetchOne('
                SELECT max(user_id) 
                FROM xf_user_option 
                WHERE ' . $sqlWhere
            );
        }

        /** @var AlertPreferences $alertPrefsRepo */
        $alertPrefsRepo = $this->app->repository('SV\AlertImprovements:AlertPreferences');
        $optOutActionList = $alertPrefsRepo->getAlertOptOutActionList();

        $convertOptOut = function (string $type, ?string $column, array &$alertPrefs) use ($alertPrefsRepo, $optOutActionList) {
            $column = $column ?? '';
            if ($column === '')
            {
                return;
            }
            $optOutList = Arr::stringToArray($column, '/\s*,\s*/');
            foreach ($optOutList as $optOut)
            {
                $parts = $alertPrefsRepo->convertStringyOptOut($optOutActionList, $optOut);
                if ($parts === null)
                {
                    // bad data, just skips since it wouldn't do anything
                    continue;
                }
                [$contentType, $action] = $parts;

                $alertPrefs[$type][$contentType][$action] = false;
            }
        };

        $maxRunTime = max(min(\XF::app()->config('jobMaxRunTime'), 4), 1);
        $startTime = microtime(true);

        $userIds = $db->fetchAllColumn('
            SELECT user_id 
            FROM xf_user_option 
            WHERE user_id > ? AND ' . $sqlWhere .'
            LIMIT 100
        ', [$next]);
        if (count($userIds) === 0)
        {
            return null;
        }

        foreach ($userIds as $userId)
        {
            $userId = (int)$userId;
            $stepData['userId'] = $userId;

            $db->beginTransaction();
            $userOption = $db->fetchRow('
                SELECT * 
                FROM xf_user_option 
                WHERE user_id = ? 
                FOR UPDATE
            ', [$userId]);

            $alertPrefs = @json_decode($userOption['sv_alert_pref'] ?? '', true) ?: [];

            $convertOptOut('alert', $userOption['alert_optout'] ?? '', $alertPrefs);
            $convertOptOut('push', $userOption['push_optout'] ?? '', $alertPrefs);
            $convertOptOut('discord', $userOption['nf_discord_optout'] ?? '', $alertPrefs);
            if (isset($userOption['sv_skip_auto_read_for_op']) && !$userOption['sv_skip_auto_read_for_op'])
            {
                $alertPrefs['autoRead']['post']['op_insert'] = false;
            }

            $db->query('
                UPDATE xf_user_option
                SET sv_alert_pref = ?
                WHERE user_id = ?
            ', [json_encode($alertPrefs), $userId]);

            $db->commit();

            if (microtime(true) - $startTime >= $maxRunTime)
            {
                break;
            }
        }

        return $stepData;
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

    public function postUpgrade($previousVersion, array &$stateChanges): void
    {
        $previousVersion = (int)$previousVersion;
        parent::postUpgrade($previousVersion, $stateChanges);

        if ($previousVersion >= 2080000 && $previousVersion < 2080400)
        {
            \XF::app()->jobManager()->enqueueUnique('svAlertTotalRebuild', 'SV\AlertImprovements:AlertTotalRebuild', [], true);
        }
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
            $this->addOrChangeColumn($table, 'sv_alerts_popup_skips_mark_read', 'tinyint')->setDefault(0);
            $this->addOrChangeColumn($table, 'sv_alerts_page_skips_summarize', 'tinyint')->setDefault(0);
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
}
