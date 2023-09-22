<?php

namespace SV\AlertImprovements\Repository;

use SV\AlertImprovements\Enum\PopUpReadBehavior;
use XF\Repository\UserAlert;
use XF\Mvc\Entity\Repository;
use XF\Util\Arr;
use function array_fill_keys;
use function array_keys;
use function array_replace_recursive;
use function array_shift;
use function count;
use function explode;
use function implode;
use function json_decode;
use function json_encode;

class AlertPreferences extends Repository
{
    /** @var ?array */
    protected $alertOptOutActionList = null;

    public static function get(): self
    {
        /** @noinspection PhpIncompatibleReturnTypeInspection */
        return \XF::repository('SV\AlertImprovements:AlertPreferences');
    }

    public function migrateAlertPreferencesForUser(int $userId): array
    {
        $alertActions = $this->getAlertOptOutActionList();

        $convertOptOut = function (string $type, ?string $column, array &$alertPrefs) use ($alertActions) {
            $column = $column ?? '';
            if ($column === '')
            {
                return;
            }
            $optOutList = Arr::stringToArray($column, '/\s*,\s*/');
            foreach ($optOutList as $optOut)
            {
                $parts = $this->convertStringyOptOut($alertActions, $optOut);
                if ($parts === null)
                {
                    // bad data, just skips since it wouldn't do anything
                    continue;
                }
                [$contentType, $action] = $parts;

                $alertPrefs[$type][$contentType][$action] = false;
            }
        };

        $db = $this->db();
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
            $alertPrefs['autoRead']['post']['op_insert'] = true;
        }

        $db->query('
                UPDATE xf_user_option
                SET sv_alert_pref = ?
                WHERE user_id = ?
            ', [json_encode($alertPrefs), $userId]);

        $db->commit();

        return $alertPrefs;
    }

    /**
     * @return array<string,array<string,bool>>
     */
    public function getAlertOptOutActionList(bool $fromCache = true): array
    {
        if ($fromCache && $this->alertOptOutActionList !== null)
        {
            return $this->alertOptOutActionList;
        }

        $handlers = $this->getAlertRepo()->getAlertHandlers();

        $actions = [];
        foreach ($handlers AS $contentType => $handler)
        {
            foreach ($handler->getOptOutActions() AS $action)
            {
                $actions[$contentType][$action] = true;
            }
        }

        $this->alertOptOutActionList = $actions;

        return $actions;
    }

    public function getAlertPreferenceTypes(): array
    {
        $types = [
            'alert',
            'autoRead',
            'push',
        ];
        if (\XF::isAddOnActive('NF/Discord'))
        {
            $types [] = 'discord';
        }

        return $types;
    }

    /**
     * Converts from "{$contentType}_{$action}" => [$contentType,$action]
     * While this is ambiguous if '_' is used in the content-type, XF doesn't handle that case well either
     *
     * @param array  $optOutActions
     * @param string $optOut
     * @return array|null
     */
    public function convertStringyOptOut(array $optOutActions, string $optOut): ?array
    {
        $parts = explode('_', $optOut);
        assert(is_array($parts));
        if (count($parts) < 2)
        {
            return null;
        }
        if (count($parts) === 2)
        {
            return $parts;
        }

        $contentType = array_shift($parts);
        while (count($parts) !== 0)
        {
            $action = implode('_', $parts);
            if (isset($optOutActions[$contentType][$action]))
            {
                return [$contentType, $action];
            }
            $contentType .= '_' . array_shift($parts);
        }

        return null;
    }

    protected $optOutDefaults = null;

    public function getGlobalAlertPreferenceDefaults(?array $alertTypes = null, ?array $svAlertPreferencesValue = null): array
    {
        $alertTypes = $alertTypes ?? $this->getAlertPreferenceTypes();
        $alertActions = $this->getAlertOptOutActionList();

        $globalDefaults = $this->getAllAlertPreferencesDefaults($alertTypes, $alertActions);
        $alertOptOutDefaults = $svAlertPreferencesValue ?? \XF::options()->svAlertPreferences ?? [];
        $alertOptOutDefaults = array_replace_recursive($globalDefaults, $alertOptOutDefaults);

        return [$alertOptOutDefaults, $alertTypes, $alertActions];
    }

    public function getAlertPreferenceDefault(string $type, string $contentType, string $action): bool
    {
        if ($this->optOutDefaults === null)
        {
            [$optOutDefaults] = $this->getGlobalAlertPreferenceDefaults();
            $this->optOutDefaults = $this->setUpAlertPreferenceDefaults($optOutDefaults, false);
        }

        return $this->optOutDefaults[$type][$contentType][$action] ?? true;
    }

    protected function setUpAlertPreferenceDefaults(array $preferences, bool $allDefaults): array
    {
        if (($allDefaults || !isset($preferences['autoRead']['post']['op_insert'])) && \XF::isAddOnActive('SV/ThreadStarterAlerts'))
        {
            $preferences['autoRead']['post']['op_insert'] = false;
        }

        return $preferences;
    }

    /**
     * @param array<string> $types
     * @param array<string,array<string,bool>> $optOutActions
     * @return array<string,array<string,array<string, bool>>>
     */
    public function getAllAlertPreferencesDefaults(array $types, array $optOutActions): array
    {
        return $this->setUpAlertPreferenceDefaults(array_fill_keys($types, $optOutActions), true);
    }

    public function getAlertPopupBehaviourPairs(): array
    {
        return PopUpReadBehavior::getPairs();
    }

    protected function getAlertRepo(): UserAlert
    {
        /** @noinspection PhpIncompatibleReturnTypeInspection */
        return $this->repository('XF:UserAlert');
    }
}