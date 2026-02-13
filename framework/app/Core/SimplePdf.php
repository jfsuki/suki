<?php
// app/Core/SimplePdf.php

namespace App\Core;

final class SimplePdf
{
    public function render(array $lines, string $title = 'Reporte'): string
    {
        $data = [
            'title' => $title,
            'fields' => array_map(fn($line) => ['label' => '', 'value' => $line], $lines),
            'grid' => ['columns' => [], 'rows' => []],
            'totals' => [],
        ];
        return $this->renderReport($data);
    }

    public function renderReport(array $data): string
    {
        $title = (string) ($data['title'] ?? 'Reporte');
        $fields = $data['fields'] ?? [];
        $grid = $data['grid'] ?? ['columns' => [], 'rows' => []];
        $totals = $data['totals'] ?? [];

        $content = $this->buildReportContent($title, $fields, $grid, $totals);
        $objects = [];

        $objects[] = "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj";
        $objects[] = "2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj";
        $objects[] = "3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>\nendobj";
        $objects[] = "4 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>\nendobj";
        $objects[] = "5 0 obj\n<< /Length " . strlen($content) . " >>\nstream\n{$content}\nendstream\nendobj";

        $xref = [];
        $pdf = "%PDF-1.4\n";
        foreach ($objects as $obj) {
            $xref[] = strlen($pdf);
            $pdf .= $obj . "\n";
        }

        $xrefPos = strlen($pdf);
        $pdf .= "xref\n0 " . (count($objects) + 1) . "\n";
        $pdf .= "0000000000 65535 f \n";
        foreach ($xref as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }
        $pdf .= "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\n";
        $pdf .= "startxref\n{$xrefPos}\n%%EOF";

        return $pdf;
    }

    private function buildReportContent(string $title, array $fields, array $grid, array $totals): string
    {
        $commands = [];
        $y = 760;

        $commands[] = $this->textCmd(50, $y, 16, $title);
        $y -= 10;
        $commands[] = $this->lineCmd(50, $y, 560, $y);
        $y -= 20;

        $fields = is_array($fields) ? $fields : [];
        $colWidth = 250;
        $xLeft = 50;
        $xRight = 320;
        $rowHeight = 14;

        $i = 0;
        foreach ($fields as $field) {
            $label = (string) ($field['label'] ?? '');
            $value = (string) ($field['value'] ?? '');
            $text = trim($label !== '' ? "{$label}: {$value}" : $value);
            $x = ($i % 2 === 0) ? $xLeft : $xRight;
            $commands[] = $this->textCmd($x, $y, 10, $text);
            if ($i % 2 === 1) {
                $y -= $rowHeight;
            }
            $i++;
            if ($y < 520) {
                break;
            }
        }
        if ($i % 2 === 1) {
            $y -= $rowHeight;
        }

        $gridColsRaw = $grid['columns'] ?? [];
        $gridRows = $grid['rows'] ?? [];
        $gridCols = [];
        $gridLabels = [];
        if (is_array($gridColsRaw)) {
            foreach ($gridColsRaw as $col) {
                if (is_array($col)) {
                    $gridCols[] = (string) ($col['key'] ?? '');
                    $gridLabels[] = (string) ($col['label'] ?? $col['key'] ?? '');
                } else {
                    $gridCols[] = (string) $col;
                    $gridLabels[] = (string) $col;
                }
            }
        }

        if (count($gridCols) > 0) {
            $y -= 10;
            $commands[] = $this->lineCmd(50, $y, 560, $y);
            $y -= 14;
            $tableWidth = 510;
            $colCount = count($gridCols);
            $colWidth = $colCount > 0 ? ($tableWidth / $colCount) : $tableWidth;
            $x = 50;
            foreach ($gridLabels as $label) {
                $commands[] = $this->textCmd($x, $y, 10, (string) $label);
                $x += $colWidth;
            }
            $y -= 12;
            $commands[] = $this->lineCmd(50, $y + 6, 560, $y + 6);

            foreach ($gridRows as $row) {
                if ($y < 120) {
                    break;
                }
                $x = 50;
                foreach ($gridCols as $colKey) {
                    $val = '';
                    if (is_array($row)) {
                        $val = (string) ($row[$colKey] ?? '');
                    }
                    $commands[] = $this->textCmd($x, $y, 9, $val);
                    $x += $colWidth;
                }
                $y -= 12;
            }
        }

        $totals = is_array($totals) ? $totals : [];
        if (count($totals) > 0) {
            $y -= 8;
            $commands[] = $this->lineCmd(320, $y, 560, $y);
            $y -= 12;
            foreach ($totals as $total) {
                if ($y < 60) {
                    break;
                }
                $label = (string) ($total['label'] ?? '');
                $value = (string) ($total['value'] ?? '');
                $commands[] = $this->textCmd(320, $y, 10, $label);
                $commands[] = $this->textCmd(480, $y, 10, $value);
                $y -= 12;
            }
        }

        return implode("\n", $commands);
    }

    private function textCmd(float $x, float $y, int $size, string $text): string
    {
        $text = $this->escapeText($text);
        return "BT /F1 {$size} Tf {$x} {$y} Td ({$text}) Tj ET";
    }

    private function lineCmd(float $x1, float $y1, float $x2, float $y2): string
    {
        return "{$x1} {$y1} m {$x2} {$y2} l S";
    }

    private function escapeText(string $text): string
    {
        $text = str_replace('\\', '\\\\', $text);
        $text = str_replace('(', '\\(', $text);
        $text = str_replace(')', '\\)', $text);
        return $text;
    }
}
