<?php

namespace App\Console\Commands;

use App\Exceptions\DocumentExtractionException;
use App\Services\DocumentExtractionService;
use Illuminate\Console\Command;
use Throwable;

class ExtractProposalText extends Command
{
    protected $signature = 'proposals:extract-text {path} {type}';

    protected $description = 'Read a local proposal document and return text to the application worker';

    public function handle(DocumentExtractionService $service): int
    {
        try {
            $text = $service->extract($this->argument('path'), $this->argument('type'));
            $this->output->write(json_encode(['text' => $text], JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (DocumentExtractionException $exception) {
            $this->output->write(json_encode(['error' => $exception->getMessage()], JSON_THROW_ON_ERROR));
        } catch (Throwable) {
            $this->output->write(json_encode(['error' => 'Unable to read this document. Please upload a valid text-based PDF or DOCX file.']));
        }

        return self::FAILURE;
    }
}
