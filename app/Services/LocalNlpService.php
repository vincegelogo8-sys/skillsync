<?php

namespace App\Services;

use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Validation\ValidationException;
use JsonException;
use LengthException;
use Throwable;

class LocalNlpService
{
    public const UNAVAILABLE = 'Local proposal analysis could not be completed. Please verify the local NLP installation and try again.';

    /** One local process, strict strings-only response, and no network fallback. */
    public function analyze(string $title, string $text): array
    {
        $python = config('local_nlp.python');
        $script = config('local_nlp.script');
        if (! config('local_nlp.enabled') || ! is_string($python) || ! is_file($python) || ! is_string($script) || ! is_file($script)) {
            $this->fail('configuration');
        }
        if (mb_strlen($title) + mb_strlen($text) > config('local_nlp.max_input_characters')) {
            $this->fail('input_limit');
        }
        $bytes = 0;
        // Symfony may filter getenv() against $_SERVER on web requests. Windows
        // needs SystemRoot for Winsock and profile paths for NLTK's import-time
        // directory initialization, even when all actual assets are local.
        $windowsEnvironment = [];
        if (PHP_OS_FAMILY === 'Windows') {
            $systemRoot = getenv('SystemRoot') ?: getenv('windir');
            if (! is_string($systemRoot) || $systemRoot === '') {
                $this->fail('windows_environment');
            }
            $windowsEnvironment['SystemRoot'] = $systemRoot;
            foreach (['USERPROFILE', 'HOMEDRIVE', 'HOMEPATH', 'APPDATA', 'LOCALAPPDATA', 'TEMP', 'TMP'] as $name) {
                $value = getenv($name);
                if (is_string($value) && $value !== '') {
                    $windowsEnvironment[$name] = $value;
                }
            }
        }
        $limit = (int) config('local_nlp.max_output_bytes');
        $timeout = max(1, (int) config('local_nlp.timeout'));
        $executionLimit = (int) ini_get('max_execution_time');
        if (PHP_SAPI !== 'cli' && $executionLimit > 0) {
            // Give the configured child timeout room to finish and be handled.
            // This changes only the current request, never the server setting.
            $timerReset = function_exists('set_time_limit')
                && set_time_limit(max($executionLimit, $timeout + 20));
            // Symfony may spend up to 10 seconds stopping a timed-out child.
            // Reserve that shutdown time plus time to save the controlled error.
            $remaining = $timerReset
                ? (int) ini_get('max_execution_time') - 15
                : (int) floor($executionLimit - (microtime(true) - ($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true))) - 15);
            if ($remaining < 1) {
                $this->fail('request_timeout');
            }
            $timeout = min($timeout, $remaining);
        }
        try {
            $result = Process::path(base_path())->timeout($timeout)
                ->env([...$windowsEnvironment, 'PYTHONUTF8' => '1', 'PYTHONIOENCODING' => 'utf-8', 'HF_HUB_OFFLINE' => '1',
                    'TRANSFORMERS_OFFLINE' => '1', 'HF_HUB_DISABLE_TELEMETRY' => '1',
                    'HF_HUB_DISABLE_IMPLICIT_TOKEN' => '1', 'HF_TOKEN' => false,
                    'HUGGING_FACE_HUB_TOKEN' => false, 'OPENAI_API_KEY' => false])
                ->input(json_encode(['title' => $title, 'text' => $text], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE))
                ->run([$python, $script, '--stdin'], function (string $type, string $output) use (&$bytes, $limit) {
                    $bytes += strlen($output);
                    if ($bytes > $limit) {
                        throw new LengthException('Local NLP output limit exceeded.');
                    }
                });
        } catch (ProcessTimedOutException) {
            $this->fail('timeout');
        } catch (LengthException) {
            $this->fail('output_limit');
        } catch (Throwable) {
            $this->fail('process_start');
        }
        if (strlen($result->output()) + strlen($result->errorOutput()) > $limit) {
            $this->fail('output_limit');
        }
        if (! $result->successful()) {
            // Never log arbitrary stderr: it can contain document text or local paths.
            $this->fail('python_failure', $result->exitCode());
        }
        try {
            $payload = json_decode($result->output(), false, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $this->fail('invalid_json');
        }
        if (! $payload instanceof \stdClass || array_keys(get_object_vars($payload)) !== ['keywords'] || ! is_array($payload->keywords)
            || count($payload->keywords) > 12) {
            $this->fail('invalid_schema');
        }
        $keywords = [];
        foreach ($payload->keywords as $keyword) {
            if (! is_string($keyword) || mb_strlen($keyword) > 160 || ! preg_match('/\p{L}/u', $keyword)
                || preg_match('/[<>`%\x00-\x1F]/u', $keyword)) {
                $this->fail('invalid_keyword');
            }
            $keyword = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $keyword)));
            $keywords[] = $keyword;
        }
        $keywords = array_values(array_unique($keywords));
        if ($keywords === []) {
            $this->fail('empty_concepts');
        }

        return $keywords;
    }

    private function fail(string $category, ?int $exitCode = null): never
    {
        Log::warning('Local proposal analysis unavailable.', array_filter([
            'category' => $category, 'exit_code' => $exitCode,
        ], fn ($value) => $value !== null));
        throw ValidationException::withMessages(['analysis' => self::UNAVAILABLE]);
    }
}
