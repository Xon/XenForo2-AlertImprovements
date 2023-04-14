<?php

namespace SV\AlertImprovements\Repository;

use SV\AlertImprovements\XF\Repository\UserAlert;
use XF\Mvc\Entity\Repository;
use function array_fill_keys;
use function array_shift;
use function count;
use function explode;
use function implode;

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

    /** @var array<string,array<string,array<string, bool>>> */
    protected $optOutDefaults = [];

    public function getAlertPreferenceDefault(string $type, string $contentType, string $action): bool
    {
        $defaultsByType = $this->optOutDefaults[$type] ?? null;
        if ($defaultsByType === null)
        {
            $this->optOutDefaults[$type] = $defaultsByType = $this->getAlertPreferencesDefaults([$type], $this->getAlertOptOutActionList())[$type];
        }

        return $defaultsByType[$contentType][$action] ?? true;
    }

    /**
     * @param array<string> $types
     * @param array<string,array<string,bool>> $optOutActions
     * @return array<string,array<string,array<string, bool>>>
     */
    public function getAlertPreferencesDefaults(array $types, array $optOutActions): array
    {
        $defaults = array_fill_keys($types, $optOutActions);

        if (\XF::isAddOnActive('SV/ThreadStarterAlerts') && in_array('autoRead', $types, true))
        {
            $defaults['autoRead']['post']['op_insert'] = false;
        }

        return $defaults;
    }

    protected function getAlertRepo(): UserAlert
    {
        /** @noinspection PhpIncompatibleReturnTypeInspection */
        return $this->repository('XF:UserAlert');
    }
}