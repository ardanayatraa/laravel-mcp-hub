<?php

namespace McpHub\Laravel\Exports;

use Dompdf\Dompdf;
use Dompdf\Options;

/** Fixed templates only: values cannot introduce formulas, HTML, paths or network requests. */
final class ReportWriter
{
    public function render(string $format, array $columns, array $rows, string $coverage, string $asOf): string
    {
        $table = [array_merge(['Report coverage', $coverage, 'As of UTC', $asOf], array_fill(0, max(0, count($columns) - 4), '')),
            array_keys($columns)];
        foreach ($rows as $row) {
            $table[] = array_values($row);
        }
        if ($format === 'csv') {
            $stream = fopen('php://temp', 'w+');
            try {
                fwrite($stream, "\xEF\xBB\xBF");
                foreach ($table as $row) {
                    $encoded = array_map(function ($value) {
                        if (is_string($value) && preg_match('/^[\p{Z}\p{C}\s]*[=+@\x{FF1D}\x{FF0B}\x{FF0D}\x{FF20}-]|^[\t\r\n]/u', $value)) {
                            $value = "'".$value;
                        }
                        $text = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
                        return '"'.str_replace('"', '""', $text).'"';
                    }, $row);
                    fwrite($stream, implode(',', $encoded)."\r\n");
                }
                rewind($stream);
                return stream_get_contents($stream);
            } finally {
                fclose($stream);
            }
        }
        if ($format === 'xlsx') {
            return $this->xlsx($table);
        }
        if ($format !== 'pdf') {
            throw new ExportException('Export format is unavailable.', 422);
        }
        $escape = function ($text) { return htmlspecialchars((string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); };
        $html = '<html><head><meta charset="utf-8"><style>@page{margin:28pt}body{font-family:DejaVu Sans;font-size:9pt;color:#172b4d}h1{font-size:18pt}table{width:100%;border-collapse:collapse;table-layout:fixed}thead{display:table-header-group}th,td{border:1px solid #d7dfe8;padding:5pt;word-wrap:break-word;vertical-align:top}th{background:#edf2f8;text-align:left}tr{page-break-inside:avoid}</style></head><body><h1>Resource report</h1><p>Coverage: '.
            $escape($coverage).' | As of UTC: '.$escape($asOf).'</p><table><thead><tr>';
        foreach (array_keys($columns) as $field) {
            $html .= '<th>'.$escape($field).'</th>';
        }
        $html .= '</tr></thead><tbody>';
        foreach ($rows as $row) {
            $html .= '<tr>';
            foreach ($row as $value) {
                $html .= '<td>'.$escape(is_bool($value) ? ($value ? 'true' : 'false') : $value).'</td>';
            }
            $html .= '</tr>';
        }
        $html .= '</tbody></table></body></html>';
        if (strlen($html) > 1048576) {
            throw new ExportException('PDF report is too large.', 413);
        }
        $directory = app(ExportFiles::class)->directory();
        $options = new Options(['isRemoteEnabled' => false, 'isPhpEnabled' => false, 'isJavascriptEnabled' => false,
            'allowedProtocols' => [], 'chroot' => [$directory], 'tempDir' => $directory,
            'fontCache' => $directory, 'defaultFont' => 'DejaVu Sans']);
        $pdf = new Dompdf($options);
        $pdf->setPaper('A4', count($columns) > 5 ? 'landscape' : 'portrait');
        $pdf->loadHtml($html, 'UTF-8');
        $pdf->render();
        $pdf->getCanvas()->page_text(28, count($columns) > 5 ? 570 : 814, 'Page {PAGE_NUM} / {PAGE_COUNT}', null, 8);
        return $pdf->output();
    }

    private function xlsx(array $table): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetViews><sheetView workbookViewId="0"><pane ySplit="2" topLeftCell="A3" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews><cols><col min="1" max="10" width="24" customWidth="1"/></cols><sheetData>';
        foreach ($table as $index => $row) {
            $lines = 1;
            foreach ($row as $value) {
                $height = 0;
                foreach (explode("\n", (string) $value) as $line) {
                    $height += max(1, (int) ceil(mb_strwidth($line, 'UTF-8') / 24));
                }
                $lines = max($lines, $height);
            }
            $xml .= '<row r="'.($index + 1).'" ht="'.min(409, 8 + $lines * 15).'" customHeight="1">';
            foreach ($row as $column => $value) {
                $ref = chr(65 + $column).($index + 1);
                $style = ' s="'.($index < 2 ? 1 : 0).'"';
                if (is_bool($value)) {
                    $xml .= '<c r="'.$ref.'"'.$style.' t="b"><v>'.($value ? '1' : '0').'</v></c>';
                } elseif ((is_int($value) && abs($value) <= 999999999999999) || is_float($value)) {
                    $xml .= '<c r="'.$ref.'"'.$style.'><v>'.json_encode($value).'</v></c>';
                } else {
                    $xml .= '<c r="'.$ref.'"'.$style.' t="inlineStr"><is><t xml:space="preserve">'.
                        htmlspecialchars((string) $value, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</t></is></c>';
                }
            }
            $xml .= '</row>';
        }
        $xml .= '</sheetData></worksheet>';
        return $this->zip([
            '[Content_Types].xml' => '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>',
            '_rels/.rels' => '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>',
            'xl/workbook.xml' => '<?xml version="1.0"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Report" sheetId="1" r:id="rId1"/></sheets></workbook>',
            'xl/_rels/workbook.xml.rels' => '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>',
            'xl/styles.xml' => '<?xml version="1.0"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><color rgb="FF172B4D"/><name val="Calibri"/></font></fonts><fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FFEDF2F8"/><bgColor indexed="64"/></patternFill></fill></fills><borders count="1"><border/></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0" applyAlignment="1"><alignment vertical="top" wrapText="1"/></xf><xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyAlignment="1"><alignment vertical="top" wrapText="1"/></xf></cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>',
            'xl/worksheets/sheet1.xml' => $xml,
        ]);
    }

    /** Bounded ZIP STORE archive; works on the minimum PHP runtime without ext-zip. */
    private function zip(array $files): string
    {
        $data = '';
        $directory = '';
        foreach ($files as $name => $bytes) {
            $size = strlen($bytes);
            $crc = crc32($bytes);
            $offset = strlen($data);
            $data .= pack('VvvvvvVVVvv', 0x04034b50, 20, 0, 0, 0, 33, $crc, $size, $size, strlen($name), 0).$name.$bytes;
            $directory .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0, 0, 0, 33, $crc, $size, $size,
                strlen($name), 0, 0, 0, 0, 0, $offset).$name;
        }
        return $data.$directory.pack('VvvvvVVv', 0x06054b50, 0, 0, count($files), count($files), strlen($directory), strlen($data), 0);
    }
}
