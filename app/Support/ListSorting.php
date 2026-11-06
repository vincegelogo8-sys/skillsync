<?php

namespace App\Support;

use Illuminate\Http\Request;

class ListSorting
{
    public static function parse(Request $request, array $allowed, string $defaultSort, string $defaultDir = 'asc'): array
    {
        $sort = $request->query('sort');
        $dir = $request->query('dir');

        return [
            'sort' => is_string($sort) && in_array($sort, $allowed, true) ? $sort : $defaultSort,
            'dir' => is_string($dir) && in_array($dir, ['asc', 'desc'], true) ? $dir : $defaultDir,
        ];
    }
}
