<?php

namespace Tests\Unit;

use App\Support\ActivityGroups;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Tests\TestCase;

class ActivityGroupsTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_it_filters_groups_orders_and_limits_activity_in_the_display_timezone(): void
    {
        config(['app.display_timezone' => 'Asia/Manila']);
        Carbon::setTestNow(Carbon::parse('2026-11-06 17:30:00', 'UTC'));
        $items = collect([
            (object) ['name' => 'Yesterday', 'at' => '2026-11-06 15:00:00'],
            (object) ['name' => 'Today first', 'at' => '2026-11-06 16:30:00'],
            (object) ['name' => 'Today newest', 'at' => '2026-11-06 17:00:00'],
            (object) ['name' => 'Too old', 'at' => '2026-10-30 15:00:00'],
        ]);

        $groups = ActivityGroups::make($items, fn (object $item) => $item->at, limit: 3, days: 7);

        $this->assertSame(['Today', 'Yesterday'], array_column($groups, 'label'));
        $this->assertSame('2026-11-07', $groups[0]['date']);
        $this->assertSame('Today newest', $groups[0]['items'][0]['model']->name);
        $this->assertSame('Today first', $groups[0]['items'][1]['model']->name);
        $this->assertSame('Yesterday', $groups[1]['items'][0]['model']->name);
        $this->assertSame('2026-11-07 01:00:00', $groups[0]['items'][0]['at']->format('Y-m-d H:i:s'));
        $this->assertSame('Asia/Manila', $groups[0]['items'][0]['at']->getTimezone()->getName());
        $this->assertSame(3, collect($groups)->sum(fn (array $group) => count($group['items'])));
    }

    public function test_it_labels_older_dates_and_returns_empty_for_nonpositive_limits(): void
    {
        config(['app.display_timezone' => 'Asia/Manila']);
        Carbon::setTestNow(Carbon::parse('2026-11-06 17:30:00', 'UTC'));
        $items = new Collection([
            (object) ['at' => '2026-11-02 00:00:00'],
        ]);

        $this->assertSame('Nov 2, 2026', ActivityGroups::make($items, fn (object $item) => $item->at)[0]['label']);
        $this->assertSame([], ActivityGroups::make($items, fn (object $item) => $item->at, limit: 0));
        $this->assertSame([], ActivityGroups::make($items, fn (object $item) => $item->at, days: 0));
    }
}
