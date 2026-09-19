<?php

namespace Tests\Support;

use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;

class DocumentFixtures
{
    public static function pdf(array $lines = ['Research proposal using Laravel and MySQL', 'C++ C# .NET Node.js API SQL']): string
    {
        $stream = "BT /F1 12 Tf 14 TL 50 750 Td\n";
        foreach ($lines as $line) {
            $stream .= '('.str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $line).") Tj T*\n";
        }
        $stream .= 'ET';
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
            '<< /Length '.strlen($stream).">>\nstream\n".$stream."\nendstream",
        ];
        $pdf = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objects as $i => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($i + 1)." 0 obj\n".$object."\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 6\n0000000000 65535 f \n";
        foreach (array_slice($offsets, 1) as $offset) {
            $pdf .= sprintf('%010d 00000 n ', $offset)."\n";
        }

        return $pdf."trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n".$xref."\n%%EOF\n";
    }

    public static function docx(bool $empty = false): string
    {
        $word = new PhpWord;
        $word->addTitleStyle(1, ['bold' => true]);
        $section = $word->addSection();
        if (! $empty) {
            $section->addTitle('Research Heading', 1);
            $run = $section->addTextRun();
            $run->addText('Laravel ');
            $run->addText('and MySQL', ['bold' => true]);
            $section->addText('C++ C# .NET Node.js — café');
            $section->addListItem('List item for research');
            $table = $section->addTable();
            $table->addRow();
            $table->addCell()->addText('Technology');
            $table->addCell()->addText('Purpose');
            $table->addRow();
            $table->addCell()->addText('Laravel');
            $table->addCell()->addText('Web application');
        }
        $path = tempnam(sys_get_temp_dir(), 'skillsync-docx-');
        try {
            IOFactory::createWriter($word, 'Word2007')->save($path);

            return file_get_contents($path);
        } finally {
            unlink($path);
        }
    }
}
