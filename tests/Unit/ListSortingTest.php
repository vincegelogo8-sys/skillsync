<?php

namespace Tests\Unit;

use App\Support\ListSorting;
use App\Support\NameSortKey;
use Illuminate\Http\Request;
use Tests\TestCase;

class ListSortingTest extends TestCase
{
    public function test_name_sort_key_orders_surnames_with_titles_particles_and_suffixes(): void
    {
        $keys = app(NameSortKey::class);
        $names = [
            'Prof. Angel M. Moyong',
            'Frances Aimee Barrera',
            'Dr. Juan dela Cruz',
            'Maria de la Cruz',
            'Jose Moyong Jr.',
        ];
        usort($names, fn (string $left, string $right) => strcmp($keys->key($left), $keys->key($right)));

        $this->assertSame([
            'Frances Aimee Barrera',
            'Maria de la Cruz',
            'Dr. Juan dela Cruz',
            'Prof. Angel M. Moyong',
            'Jose Moyong Jr.',
        ], $names);
    }

    public function test_invalid_sort_and_direction_fall_back_to_defaults(): void
    {
        $request = Request::create('/items?sort=created_at;drop&dir=sideways');

        $this->assertSame(
            ['sort' => 'name', 'dir' => 'desc'],
            ListSorting::parse($request, ['name', 'date'], 'name', 'desc')
        );
    }
}
