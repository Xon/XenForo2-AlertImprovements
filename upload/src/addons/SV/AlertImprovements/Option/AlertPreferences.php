<?php
/**
 * @noinspection PhpUnusedParameterInspection
 */

namespace SV\AlertImprovements\Option;

use SV\AlertImprovements\Repository\AlertPreferences as AlertPreferencesRepo;
use XF\Entity\Option as OptionEntity;
use XF\Option\AbstractOption;
use function array_replace_recursive;
use function count;

class AlertPreferences extends AbstractOption
{
    public static function renderOption(OptionEntity $option, array $htmlParams): string
    {
        [$defaults, $alertTypes, $alertActions] = AlertPreferencesRepo::get()->getGlobalAlertPreferenceDefaults(null, $option->option_value ?? []);

        return self::getTemplate('admin:svAlertImprov_option_template_alert_preferences', $option, $htmlParams, [
            'alertActions' => $alertActions,
            'alertTypes'   => $alertTypes,
            'optionValue'  => $defaults,
        ]);
    }

    public static function verifyOption(array &$values, OptionEntity $option, string $optionId): bool
    {
        [$allValues] = AlertPreferencesRepo::get()->getGlobalAlertPreferenceDefaults(null, []);

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