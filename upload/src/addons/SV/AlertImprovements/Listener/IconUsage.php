<?php

namespace SV\AlertImprovements\Listener;

use XF\Service\Icon\UsageAnalyzer;

abstract class IconUsage
{
    /** @noinspection PhpUnusedParameterInspection */
    public static function analyzerSteps(array &$steps, UsageAnalyzer $usageAnalyzer): void
    {
        $steps['style_property'][] = function (?int $lastOffset, float $maxRunTime) use ($usageAnalyzer): ?int {
            $data = \XF::db()->fetchAll("
                SELECT property_id, property_value, style_id, depends_on
                FROM xf_style_property
                WHERE property_name IN ('svAlertImprovJustReadAlertIcon','svAlertImprovUnreadAlertIcon','svAlertImprovRecentAlertIcon')
                ORDER BY property_id
            ");

            $app = \XF::app();
            foreach ($data as $row)
            {
                $dependsOn = (string)$row['depends_on'];
                if ($dependsOn)
                {
                    $style = $app->style((int)$row['style_id']);
                    if (!$style->getProperty($dependsOn))
                    {
                        continue;
                    }
                }

                $raw = @json_decode($row['property_value'], true);
                if (is_string($raw))
                {
                    $usageAnalyzer->recordIconsFromClasses('style_property', $row['property_id'], $raw);
                }
            }

            return null;
        };
    }
}