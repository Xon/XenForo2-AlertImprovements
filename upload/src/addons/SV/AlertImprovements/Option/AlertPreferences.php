<?php
/**
 * @noinspection PhpUnusedParameterInspection
 */

namespace SV\AlertImprovements\Option;

use XF\Entity\Option;
use XF\Option\AbstractOption;
use function array_replace_recursive;
use function count;

class AlertPreferences extends AbstractOption
{
    public static function renderOption(Option $option, array $htmlParams): string
    {
        /** @var \SV\AlertImprovements\Repository\AlertPreferences $alertPrefsRepo */
        $alertPrefsRepo = \XF::repository('SV\AlertImprovements:AlertPreferences');
        /** @var array<string> $optOutActions */
        $alertActions = $alertPrefsRepo->getAlertOptOutActionList();
        $alertTypes = $alertPrefsRepo->getAlertPreferenceTypes();

        $defaults = $alertPrefsRepo->getAllAlertPreferencesDefaults($alertTypes, $alertActions);
        $optionValue = $option->option_value ?? [];
        $optionValue = array_replace_recursive($defaults, $optionValue);

        return self::getTemplate('admin:svAlertImprov_option_template_alert_preferences', $option, $htmlParams, [
            'alertActions' => $alertActions,
            'alertTypes'   => $alertTypes,
            'optionValue'  => $optionValue,
        ]);
    }

    public static function verifyOption(array &$values, Option $option, string $optionId): bool
    {
        /** @var \SV\AlertImprovements\Repository\AlertPreferences $alertPrefsRepo */
        $alertPrefsRepo = \XF::repository('SV\AlertImprovements:AlertPreferences');
        /** @var array<string> $optOutActions */
        $alertActions = $alertPrefsRepo->getAlertOptOutActionList();
        $alertTypes = $alertPrefsRepo->getAlertPreferenceTypes();
        $allValues = $alertPrefsRepo->getAllAlertPreferencesDefaults($alertTypes, $alertActions);

        $newOptionValue = $values;
        $optionValue = [];
        foreach ($allValues as $alertType => $contentTypes)
        {
            foreach ($contentTypes as $contentType => $actions)
            {
                foreach ($actions as $action => $defaultValue)
                {
                    $newValue = (bool)($newOptionValue[$alertType][$contentType][$action] ?? false);
                    if ($defaultValue !== $newValue)
                    {
                        $optionValue[$alertType][$contentType][$action] = $newValue;
                    }
                    unset($newOptionValue[$alertType][$contentType][$action]);
                }
                if (count($newOptionValue[$alertType][$contentType] ?? []) === 0)
                {
                    unset($newOptionValue[$alertType][$contentType]);
                }
            }
            if (count($newOptionValue[$alertType] ?? []) === 0)
            {
                unset($newOptionValue[$alertType]);
            }
        }
        // preserve unknown values
        if (count($newOptionValue) !== 0)
        {
            foreach ($newOptionValue as $alertType => $contentTypes)
            {
                foreach ($contentTypes as $contentType => $actions)
                {
                    foreach ($actions as $action => $value)
                    {
                        $newOptionValue[$alertType][$contentType][$action] = (bool)$value;
                    }
                }
            }
            $optionValue = array_replace_recursive($newOptionValue, $optionValue);
        }

        $values = $optionValue;

        return true;
    }
}