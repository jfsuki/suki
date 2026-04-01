<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Smalot\PdfParser\Parser;

$pdfPath = 'C:\Users\PortatilHP2\Desktop\felits\14926779837.pdf';
$parser = new Parser();
$pdf = $parser->parseFile($pdfPath);
$text = $pdf->getText();

echo "--- CABECERA DEL TEXTO EXTRAÍDO (5000 chars) ---\n";
echo substr($text, 0, 5000);
echo "\n--- FIN ---\n";
