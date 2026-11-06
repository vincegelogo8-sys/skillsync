<?php

namespace App\Support;

use Illuminate\Support\Str;

class NameSortKey
{
    private const PARTICLES = ['al', 'bin', 'da', 'de', 'del', 'dela', 'di', 'la', 'le', 'st', 'van', 'von'];

    private const SUFFIXES = ['ii', 'iii', 'iv', 'jr', 'sr'];

    public function key(string $name): string
    {
        $parts = preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $firstParts = $parts;
        while ($firstParts !== [] && preg_match('/^(?:dr|prof|professor|mr|mrs|ms|miss)\.?$/iu', $firstParts[0])) {
            array_shift($firstParts);
        }

        $surnameParts = $parts;
        if ($surnameParts === []) {
            return "\0\0";
        }

        $last = mb_strtolower(preg_replace('/[.,]+$/u', '', end($surnameParts)));
        if (in_array($last, self::SUFFIXES, true) && count($surnameParts) > 1) {
            array_pop($surnameParts);
        }

        $start = count($surnameParts) - 1;
        while ($start > 0) {
            $previous = mb_strtolower(preg_replace('/[.,]+$/u', '', $surnameParts[$start - 1]));
            if (! in_array($previous, self::PARTICLES, true)) {
                break;
            }
            $start--;
        }

        $surname = Str::ascii(implode(' ', array_slice($surnameParts, $start)));
        $firstName = Str::ascii($firstParts[0] ?? '');

        return mb_strtolower($surname)."\0".mb_strtolower($firstName)."\0".mb_strtolower(Str::ascii($name));
    }
}
