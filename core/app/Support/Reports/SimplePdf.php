<?php

namespace App\Support\Reports;

/**
 * Writes a plain text PDF: Helvetica lines on A4 pages. A usage or operations report is a table of figures, so
 * this needs no layout engine and no dependency, and the file opens in any viewer.
 */
final class SimplePdf
{
    private const LINES_PER_PAGE = 60;

    /** @param  list<string>  $lines */
    public static function render(string $title, array $lines): string
    {
        $pages = array_chunk($lines === [] ? [''] : $lines, self::LINES_PER_PAGE);
        $objects = [];
        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $kids = [];
        foreach ($pages as $index => $pageLines) {
            $page = 4 + $index * 2;
            $kids[] = "{$page} 0 R";
            $stream = "BT /F1 9 Tf 40 800 Td 11 TL\n";
            if ($index === 0) {
                $stream .= '/F1 12 Tf ('.self::escape($title).") Tj T* T* /F1 9 Tf\n";
            }
            foreach ($pageLines as $line) {
                $stream .= '('.self::escape($line).") Tj T*\n";
            }
            $stream .= 'ET';
            $objects[$page] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 3 0 R >> >> /Contents '.($page + 1).' 0 R >>';
            $objects[$page + 1] = '<< /Length '.strlen($stream)." >>\nstream\n{$stream}\nendstream";
        }
        $objects[2] = '<< /Type /Pages /Kids ['.implode(' ', $kids).'] /Count '.count($pages).' >>';
        $objects[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        ksort($objects);

        $output = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $number => $body) {
            $offsets[$number] = strlen($output);
            $output .= "{$number} 0 obj\n{$body}\nendobj\n";
        }
        $xref = strlen($output);
        $output .= "xref\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $output .= sprintf("%010d 00000 n \n", $offset);
        }
        $output .= "trailer\n<< /Size ".(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF\n";

        return $output;
    }

    private static function escape(string $text): string
    {
        $text = mb_convert_encoding($text, 'Windows-1252', 'UTF-8');

        return str_replace(['\\', '(', ')', "\r", "\n"], ['\\\\', '\\(', '\\)', ' ', ' '], $text);
    }
}
