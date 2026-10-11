<?php
// A valid four-page PDF, no customer data and no generator dependency.
function ct_pdf_fixture(int $padding = 0): string {
    $objects = [
        '<< /Type /Catalog /Pages 2 0 R >>',
        '<< /Type /Pages /Kids [4 0 R 6 0 R 8 0 R 10 0 R] /Count 4 >>',
        '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
    ];
    for ($page = 1; $page <= 4; $page++) {
        $streamId = count($objects) + 2;
        $objects[] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 600 800] /Resources << /Font << /F1 3 0 R >> >> /Contents ' . $streamId . ' 0 R >>';
        $stream = "0.9 0.95 1 rg 30 30 540 740 re f\n0 0 0 rg BT /F1 32 Tf 60 690 Td (Book fixture, page $page) Tj ET\n";
        $objects[] = '<< /Length ' . strlen($stream) . ">>\nstream\n" . $stream . 'endstream';
    }
    // An unreferenced object makes ranged-read behavior observable without images.
    if ($padding) $objects[] = '<< /Length ' . $padding . ">>\nstream\n" . str_repeat(' ', $padding) . "\nendstream";
    $pdf = "%PDF-1.4\n";
    $offsets = [0];
    foreach ($objects as $i => $object) {
        $offsets[] = strlen($pdf);
        $pdf .= ($i + 1) . " 0 obj\n" . $object . "\nendobj\n";
    }
    $xref = strlen($pdf);
    $pdf .= 'xref' . "\n0 " . count($offsets) . "\n0000000000 65535 f \n";
    foreach (array_slice($offsets, 1) as $offset) $pdf .= sprintf("%010d 00000 n \n", $offset);
    return $pdf . 'trailer << /Size ' . count($offsets) . " /Root 1 0 R >>\nstartxref\n$xref\n%%EOF\n";
}
