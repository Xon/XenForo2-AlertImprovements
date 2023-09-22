<?php

namespace SV\AlertImprovements\Job;

use SV\AlertImprovements\Repository\AlertPreferences as AlertPreferencesRepo;
use XF\Job\AbstractRebuildJob;
use function array_key_exists;
use function implode;

class MigrateAlertPreferences extends AbstractRebuildJob
{
    /**
     * @param int $start
     * @param int $batch
     * @return array
     */
    protected function getNextIds($start, $batch): array
    {
        $columns = [
            "alert_optout <> ''",
            "push_optout <> ''",
        ];

        $db = \XF::db();
        $sm = $db->getSchemaManager();

        if (!array_key_exists('hasThreadStarterColumn', $this->data))
        {
            $this->data['hasThreadStarterColumn'] = $sm->columnExists('xf_user_option', 'sv_skip_auto_read_for_op');
        }
        if ($this->data['hasThreadStarterColumn'])
        {
            $columns[] = 'sv_skip_auto_read_for_op = 0';
        }

        if (!array_key_exists('hasDiscordColumn', $this->data))
        {
            $this->data['hasDiscordColumn'] = $sm->columnExists('xf_user_option', 'nf_discord_optout');
        }
        if ($this->data['hasDiscordColumn'])
        {
            $columns[] = "nf_discord_optout <> ''";
        }
        $sqlWhere = '(('. implode(') OR (', $columns) .'))';

        return $db->fetchAllColumn($db->limit('
            SELECT user_id 
            FROM xf_user_option 
            WHERE user_id > ? AND ' . $sqlWhere .'
        ', $batch), [$start]);
    }

    /**
     * @param int $id
     * @return void
     */
    protected function rebuildById($id): void
    {
        AlertPreferencesRepo::get()->migrateAlertPreferencesForUser($id);
    }

    protected function getStatusType()
    {
        // TODO: Implement getStatusType() method.
    }
}