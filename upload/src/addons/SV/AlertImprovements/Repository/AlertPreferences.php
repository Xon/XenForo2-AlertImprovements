<?php

namespace SV\AlertImprovements\Repository;

use SV\AlertImprovements\XF\Repository\UserAlert;
use XF\Mvc\Entity\Repository;
use function array_fill_keys;
use function array_shift;
use function count;
use function explode;
use function implode;
use function in_array;

class AlertPreferences extends Repository
{
    /** @var ?array */
    protected $alertOptOutActionList = null;

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

    public function getAlertPreferenceDefault(string $type, string $contentType, string $action): bool
    {
        if ($this->optOutDefaults === null)
        {
            $optOutDefaults = \XF::options()->svAlertPreferences ?? [];
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

    protected function getAlertRepo(): UserAlert
    {
        /** @noinspection PhpIncompatibleReturnTypeInspection */
        return $this->repository('XF:UserAlert');
    }
}