<?php

namespace SV\AlertImprovements\Repository;

use SV\AlertImprovements\XF\Entity\UserOption;
use SV\AlertImprovements\XF\Repository\UserAlert;
use XF\Mvc\Entity\Repository;
use function array_fill_keys;
use function array_shift;
use function count;
use function explode;
use function implode;

class AlertPreferences extends Repository
{
    /**
     * @return array<string,array<string,bool>>
     */
    public function getAlertOptOutActionList(): array
    {
        $handlers = $this->getAlertRepo()->getAlertHandlers();

        $actions = [];
        foreach ($handlers AS $contentType => $handler)
        {
            foreach ($handler->getOptOutActions() AS $action)
            {
                $actions[$contentType][$action] = true;
            }
        }

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

    /**
     * @param array<string> $types
     * @param array<string,array<string,bool>> $optOutActions
     * @return array<string,array<string,array<string, bool>>>
     */
    public function getAlertOptOutsDefaults(array $types, array $optOutActions): array
    {
        return array_fill_keys($types, $optOutActions);
    }

    protected function getAlertRepo(): UserAlert
    {
        /** @noinspection PhpIncompatibleReturnTypeInspection */
        return $this->repository('XF:UserAlert');
    }
}