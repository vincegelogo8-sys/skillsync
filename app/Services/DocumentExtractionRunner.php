<?php

namespace App\Services;

use App\Exceptions\DocumentExtractionException;
use Illuminate\Support\Facades\Log;
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
            $php,
            '-d', 'memory_limit=256M',
            '-d', 'display_errors=0',
            base_path('artisan'),
            'proposals:extract-text', $path, $type, '--no-ansi', '--no-interaction',
        ], base_path());
        $process->setTimeout(30);
                // Give the child php.exe a clean, explicit environment so Windows can find its DLLs (e.g. curl).
        $pathKey = 'PATH';
        foreach (array_keys(getenv()) as $key) {
            if (strcasecmp($key, 'PATH') === 0) {
                $pathKey = $key;
                break;
            }
        }
        $process->setEnv([
            $pathKey => dirname($php).PATH_SEPARATOR.(getenv($pathKey) ?: ''),
            'SystemRoot' => getenv('SystemRoot') ?: 'C:\\Windows',
        ]);
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
            Log::error('Extraction subprocess failed', [
                'php' => $php,
                'exit' => $process->getExitCode(),
                'stdout' => mb_substr($process->getOutput(), 0, 800),
                'stderr' => mb_substr($process->getErrorOutput(), 0, 800),
            ]);
            throw new DocumentExtractionException('Unable to read this document within the processing limits. Please upload a simpler text-based PDF or DOCX file.');
        }

        return $result['text'];
    }
}