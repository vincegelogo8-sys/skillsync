<?php

namespace Tests\Feature;

use App\Services\LocalNlpService;
use App\Services\ProposalRtaTextBuilder;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Process\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LocalNlpServiceTest extends TestCase
{
    private function response(string $output, int $exitCode = 0): void
    {
        Process::swap(new Factory);
        Process::preventStrayProcesses();
        Process::fake(['*' => Process::result(output: $output, errorOutput: $exitCode ? 'private traceback' : '', exitCode: $exitCode)]);
    }

    public function test_strings_only_stdin_contract_and_one_process_without_any_http(): void
    {
        $this->response('{"keywords":["Machine Learning","machine learning","image classification"]}');
        $text = "Full proposal \"quoted\" & $(whoami) Unicode: café\nNext section.";
        $this->assertSame(['machine learning', 'image classification'], app(LocalNlpService::class)->analyze('Vision', $text));
        Process::assertRanTimes(function ($process) use ($text) {
            $this->assertIsArray($process->command);
            $this->assertSame(config('local_nlp.script'), $process->command[1]);
            $this->assertSame('--stdin', $process->command[2]);
            $this->assertNotContains($text, $process->command);
            $this->assertSame(['title' => 'Vision', 'text' => $text], json_decode($process->input, true));
            $this->assertSame('1', $process->environment['HF_HUB_OFFLINE']);
            if (PHP_OS_FAMILY === 'Windows') {
                $this->assertSame(getenv('SystemRoot') ?: getenv('windir'), $process->environment['SystemRoot']);
                if (getenv('USERPROFILE')) {
                    $this->assertSame(getenv('USERPROFILE'), $process->environment['USERPROFILE']);
                }
            }
            $this->assertFalse($process->environment['OPENAI_API_KEY']);
            $this->assertSame(120, $process->timeout);

            return true;
        }, 1);
        Http::assertNothingSent();
    }

    public static function invalidOutputs(): array
    {
        return [
            ['not JSON'], ['{}'], ['{"keywords":[]}'], ['{"keywords":[12]}'],
            ['{"keywords":{"label":"vision"}}'], ['{"keywords":[{"phrase":"vision","score":0.9}]}'],
            ['{"keywords":{"0":"vision"}}'],
            ['{"keywords":["vision"],"score":0.9}'], ['{"keywords":["vision"],"rta":90}'],
            ['{"keywords":["vision 90%"]}'], ['{"keywords":["<script>x</script>"]}'],
            [json_encode(['keywords' => array_fill(0, 13, 'vision')])],
        ];
    }

    #[DataProvider('invalidOutputs')]
    public function test_bad_or_scored_output_is_never_accepted(string $output): void
    {
        $this->response($output);
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage(LocalNlpService::UNAVAILABLE);
        app(LocalNlpService::class)->analyze('Title', 'Full proposal');
    }

    public function test_failure_logs_only_safe_category_and_exit_status(): void
    {
        Log::spy();
        $this->response('private document content', 1);
        try {
            app(LocalNlpService::class)->analyze('Private title', 'Private content');
            $this->fail('Failed Python must not succeed.');
        } catch (ValidationException $exception) {
            $this->assertSame(LocalNlpService::UNAVAILABLE, $exception->getMessage());
        }
        Log::shouldHaveReceived('warning')->once()->with('Local proposal analysis unavailable.', ['category' => 'python_failure', 'exit_code' => 1]);
        Http::assertNothingSent();
    }

    public function test_configuration_and_size_limits_fail_without_fallback(): void
    {
        foreach ([['local_nlp.enabled' => false], ['local_nlp.python' => base_path('missing-python')],
            ['local_nlp.max_input_characters' => 1], ['local_nlp.max_output_bytes' => 1]] as $override) {
            $original = config('local_nlp');
            config($override);
            try {
                app(LocalNlpService::class)->analyze('Title', 'Proposal text');
                $this->fail('Invalid configuration/input should fail.');
            } catch (ValidationException $exception) {
                $this->assertSame(LocalNlpService::UNAVAILABLE, $exception->getMessage());
            } finally {
                config(['local_nlp' => $original]);
            }
        }
        Http::assertNothingSent();
    }

    public function test_timeout_has_a_controlled_error(): void
    {
        Process::swap(new Factory);
        Process::fake(function () {
            $original = new \Symfony\Component\Process\Exception\ProcessTimedOutException(
                new \Symfony\Component\Process\Process(['python']), 1
            );
            throw new ProcessTimedOutException($original, Process::result());
        });
        $this->expectExceptionMessage(LocalNlpService::UNAVAILABLE);
        app(LocalNlpService::class)->analyze('Title', 'Proposal');
    }

    public function test_rta_representation_has_exact_fixed_textual_emphasis_and_excludes_noise(): void
    {
        $text = "Abstract\nImage classification detects plant disease.\nUnrelated weather.\nReferences\nImage classification by a cited author.";
        $result = app(ProposalRtaTextBuilder::class)->build('Plant Vision', ['image classification'], $text);
        $this->assertSame("Plant Vision\nPlant Vision\nPlant Vision\nimage classification\nimage classification\nabstract image classification detects plant disease.", $result);
        $this->assertStringNotContainsString('cited author', $result);
        $this->assertStringNotContainsString('unrelated weather', $result);
    }
}
