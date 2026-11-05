<?php

namespace App\Support\Reports;

/** A report is titled sections of rows. The CSV and the PDF are two renderings of the same figures. */
final class Report
{
    /** @param  list<array{title: string, headers: list<string>, rows: list<list<int|float|string>>}>  $sections */
    public function __construct(public readonly string $title, public readonly array $sections) {}

    public function csv(): string
    {
        $stream = fopen('php://temp', 'r+b');
        foreach ($this->sections as $index => $section) {
            if ($index > 0) {
                fputcsv($stream, [], ',', '"', '');
            }
            fputcsv($stream, [$section['title']], ',', '"', '');
            fputcsv($stream, $section['headers'], ',', '"', '');
            foreach ($section['rows'] as $row) {
                fputcsv($stream, $row, ',', '"', '');
            }
        }
        rewind($stream);

        return (string) stream_get_contents($stream);
    }

    public function pdf(): string
    {
        $lines = [];
        foreach ($this->sections as $section) {
            $lines[] = '';
            $lines[] = strtoupper($section['title']);
            $lines[] = implode('  |  ', $section['headers']);
            foreach ($section['rows'] as $row) {
                $lines[] = implode('  |  ', array_map(fn (int|float|string $cell): string => (string) $cell, $row));
            }
        }

        return SimplePdf::render($this->title, $lines);
    }
}
