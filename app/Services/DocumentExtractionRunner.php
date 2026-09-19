<?php

namespace App\Services;

use App\Exceptions\DocumentExtractionException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

class DocumentExtractionRunner
{
    public function extract(string $path, string $type): string
    {
        // Bound parser memory/time without changing the web server's PHP settings.
        $php = (new PhpExecutableFinder)->find(false);
        if (! $php) {
            throw new DocumentExtractionException('The local document reader is unavailable. Please contact Admin.');
        }
        $process = new Process([
            $php, '-d', 'memory_limit=256M', base_path('artisan'),
            'proposals:extract-text', $path, $type, '--no-ansi', '--no-interaction',
        ], base_path());
        $process->setTimeout(30);
        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            throw new DocumentExtractionException('This document took too long to read. Please upload a simpler text-based PDF or DOCX file.');
        }

        $result = json_decode($process->getOutput(), true);
        if (is_array($result) && isset($result['error']) && is_string($result['error'])) {
            throw new DocumentExtractionException($result['error']);
        }
        if (! $process->isSuccessful() || ! is_array($result) || ! isset($result['text']) || ! is_string($result['text'])) {
            throw new DocumentExtractionException('Unable to read this document within the processing limits. Please upload a simpler text-based PDF or DOCX file.');
        }

        return $result['text'];
    }
}
