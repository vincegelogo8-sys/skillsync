<?php

namespace App\Rules;

use Closure;
use DOMDocument;
use DOMXPath;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;
use ZipArchive;

class ProposalDocument implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile || ! $value->isValid()) {
            $fail('Please choose a valid PDF or DOCX file.');

            return;
        }
        if (mb_strlen($value->getClientOriginalName()) > 255) {
            $fail('The document filename must be no longer than 255 characters.');

            return;
        }

        $extension = strtolower($value->getClientOriginalExtension());
        $mime = $value->getMimeType(); // Server-detected MIME, not the browser header.
        if ($extension === 'pdf' && $mime === 'application/pdf') {
            if (file_get_contents($value->getRealPath(), false, null, 0, 5) === '%PDF-') {
                return;
            }
        }
        if ($extension === 'docx' && in_array($mime, [
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/zip', 'application/x-zip-compressed',
        ], true) && $this->isDocx($value->getRealPath())) {
            return;
        }

        $fail('Upload a PDF or DOCX document whose contents match its extension. DOC files and renamed files are not supported.');
    }

    private function isDocx(string $path): bool
    {
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            return false;
        }

        try {
            if ($zip->locateName('word/document.xml') === false || $zip->locateName('_rels/.rels') === false) {
                return false;
            }
            $stat = $zip->statName('[Content_Types].xml');
            if (! $stat || $stat['size'] > 65536) {
                return false;
            }
            $content = $zip->getFromName('[Content_Types].xml', 65537);
            if (! is_string($content) || stripos($content, '<!DOCTYPE') !== false) {
                return false;
            }

            $previous = libxml_use_internal_errors(true);
            try {
                $document = new DOMDocument;
                if (! $document->loadXML($content, LIBXML_NONET)) {
                    return false;
                }
                $xpath = new DOMXPath($document);
                $xpath->registerNamespace('ct', 'http://schemas.openxmlformats.org/package/2006/content-types');

                return $xpath->query('/ct:Types/ct:Override[@PartName="/word/document.xml" and @ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"]')->length === 1;
            } finally {
                libxml_clear_errors();
                libxml_use_internal_errors($previous);
            }
        } finally {
            $zip->close();
        }
    }
}
