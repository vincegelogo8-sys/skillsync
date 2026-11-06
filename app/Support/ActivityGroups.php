<?php

namespace App\Support;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class ActivityGroups
{
    /**
     * @return array<int, array{label: string, date: string, items: array<int, array{model: mixed, at: Carbon}>}>
     */
    public static function make(Collection $items, callable $timeOf, int $limit = 10, int $days = 7): array
    {
        if ($limit <= 0 || $days <= 0) {
            return [];
        }

        $timezone = config('app.display_timezone', 'Asia/Manila');
        $now = Carbon::now($timezone);
        $start = $now->copy()->startOfDay()->subDays($days - 1);
        $grouped = [];

        $normalized = $items->map(function ($model) use ($timeOf, $timezone): ?array {
            $time = $timeOf($model);
            if ($time === null) {
                return null;
            }

            $at = $time instanceof CarbonInterface
                ? Carbon::instance($time)
                : Carbon::parse($time, 'UTC');
            $at->setTimezone($timezone);

            return ['model' => $model, 'at' => $at];
        })->filter(fn (?array $item): bool => $item !== null)
            ->filter(fn (array $item): bool => $item['at']->betweenIncluded($start, $now))
            ->sortByDesc(fn (array $item): int => $item['at']->getTimestamp())
            ->take(max(0, $limit));

        foreach ($normalized as $item) {
            $date = $item['at']->toDateString();
            if (! isset($grouped[$date])) {
                $label = match ($date) {
                    $now->toDateString() => 'Today',
                    $now->copy()->subDay()->toDateString() => 'Yesterday',
                    default => $item['at']->format('M j, Y'),
                };
                $grouped[$date] = ['label' => $label, 'date' => $date, 'items' => []];
            }

            $grouped[$date]['items'][] = $item;
        }

        return array_values($grouped);
    }
}
