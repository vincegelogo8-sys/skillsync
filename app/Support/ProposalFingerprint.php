<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use RuntimeException;

class ProposalFingerprint
{
    public static function fileHash(UploadedFile|string $fileOrPath): string
    {
        $path = $fileOrPath instanceof UploadedFile
            ? ($fileOrPath->getRealPath() ?: $fileOrPath->getPathname())
            : $fileOrPath;
        $hash = hash_file('sha256', $path);

        if ($hash === false) {
            throw new RuntimeException('Unable to fingerprint the proposal document.');
        }

        return $hash;
    }

    public static function titleKey(string $title): string
    {
        $key = mb_strtolower(Str::ascii($title));
        $key = preg_replace('/[^a-z0-9]+/', ' ', $key);

        return trim($key ?? '');
    }
}
