<?php
/**
 * Minimal .xlsx (Excel) writer with no dependencies: no Composer, no ZipArchive extension.
 *
 * XlsxWriter::build([
 *     'Sheet name' => [ ['Header A', 'Header B'], ['text', 123], ... ],   // first row = header row
 * ]) returns the .xlsx file contents as a string.
 *
 * Text is always written as text (never as a formula), so a cell such as "=1+1" is shown
 * literally. PHP ints/floats are written as numbers; everything else as text.
 */
class XlsxWriter
{
    private const MAX_CELL_CHARS = 32000;   // Excel's limit is 32,767 per cell

    public static function build(array $sheets): string
    {
        $names = [];
        $sheetXml = [];
        foreach ($sheets as $name => $rows) {
            $names[] = self::sheetName((string)$name, $names);
            $sheetXml[] = self::sheetXml(array_values($rows));
        }

        $n = count($names);
        $ns = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
        $rel = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

        $types = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
        for ($i = 1; $i <= $n; $i++) {
            $types .= '<Override PartName="/xl/worksheets/sheet' . $i . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }
        $types .= '</Types>';

        $rootRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="' . $rel . '/officeDocument" Target="xl/workbook.xml"/></Relationships>';

        $workbook = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="' . $ns . '" xmlns:r="' . $rel . '"><sheets>';
        $wbRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
        foreach ($names as $i => $name) {
            $id = $i + 1;
            $workbook .= '<sheet name="' . self::esc($name) . '" sheetId="' . $id . '" r:id="rId' . $id . '"/>';
            $wbRels .= '<Relationship Id="rId' . $id . '" Type="' . $rel . '/worksheet" Target="worksheets/sheet' . $id . '.xml"/>';
        }
        $workbook .= '</sheets></workbook>';
        $wbRels .= '<Relationship Id="rId' . ($n + 1) . '" Type="' . $rel . '/styles" Target="styles.xml"/></Relationships>';

        // cellXfs: 0 = normal, 1 = bold header with fill, 2 = body text (wrap, top-aligned)
        $styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="' . $ns . '">'
            . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font></fonts>'
            . '<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FF0A3520"/><bgColor indexed="64"/></patternFill></fill></fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="3">'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1" applyAlignment="1"><alignment vertical="center" wrapText="1"/></xf>'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0" applyAlignment="1"><alignment vertical="top" wrapText="1"/></xf>'
            . '</cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>';

        $files = [
            '[Content_Types].xml' => $types,
            '_rels/.rels' => $rootRels,
            'xl/workbook.xml' => $workbook,
            'xl/_rels/workbook.xml.rels' => $wbRels,
            'xl/styles.xml' => $styles,
        ];
        foreach ($sheetXml as $i => $xml) {
            $files['xl/worksheets/sheet' . ($i + 1) . '.xml'] = $xml;
        }
        return self::zip($files);
    }

    // ---------- sheet ----------

    private static function sheetXml(array $rows): string
    {
        $colCount = 1;
        foreach ($rows as $r) { $colCount = max($colCount, count($r)); }
        $rowCount = max(1, count($rows));

        // column widths from the header and the first 500 rows
        $widths = array_fill(0, $colCount, 10);
        foreach (array_slice($rows, 0, 500) as $r) {
            foreach (array_values($r) as $c => $v) {
                $longest = 0;
                foreach (explode("\n", is_scalar($v) ? (string)$v : '') as $line) { $longest = max($longest, strlen($line)); }
                $widths[$c] = max($widths[$c], min(60, $longest + 2));
            }
        }

        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
            . '<cols>';
        foreach ($widths as $c => $w) {
            $xml .= '<col min="' . ($c + 1) . '" max="' . ($c + 1) . '" width="' . $w . '" customWidth="1"/>';
        }
        $xml .= '</cols><sheetData>';

        foreach ($rows as $ri => $row) {
            $r = $ri + 1;
            $xml .= '<row r="' . $r . '">';
            foreach (array_values($row) as $ci => $v) {
                if ($v === null || $v === '') { continue; }
                $ref = self::col($ci) . $r;
                if ($ri === 0) {
                    $xml .= '<c r="' . $ref . '" s="1" t="inlineStr"><is><t xml:space="preserve">' . self::esc((string)$v) . '</t></is></c>';
                } elseif (is_int($v) || is_float($v)) {
                    $xml .= '<c r="' . $ref . '"><v>' . $v . '</v></c>';
                } else {
                    $xml .= '<c r="' . $ref . '" s="2" t="inlineStr"><is><t xml:space="preserve">' . self::esc((string)$v) . '</t></is></c>';
                }
            }
            $xml .= '</row>';
        }
        $xml .= '</sheetData><autoFilter ref="A1:' . self::col($colCount - 1) . $rowCount . '"/></worksheet>';
        return $xml;
    }

    private static function col(int $i): string
    {
        $s = '';
        for ($i++; $i > 0; $i = intdiv($i - 1, 26)) { $s = chr(65 + ($i - 1) % 26) . $s; }
        return $s;
    }

    private static function sheetName(string $name, array $taken): string
    {
        $name = trim(preg_replace('/[\[\]\:\*\?\/\\\\]/', ' ', $name));
        $name = $name === '' ? 'Sheet' : substr($name, 0, 31);
        $base = $name; $n = 2;
        while (in_array($name, $taken, true)) { $name = substr($base, 0, 28) . ' ' . $n++; }
        return $name;
    }

    /** XML-safe text: removes characters XML forbids, caps the length, escapes markup. */
    private static function esc(string $s): string
    {
        if (strlen($s) > self::MAX_CELL_CHARS) {
            $s = function_exists('mb_substr') ? mb_substr($s, 0, self::MAX_CELL_CHARS, 'UTF-8') : substr($s, 0, self::MAX_CELL_CHARS);
        }
        $clean = @preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $s);
        if ($clean === null) {                       // not valid UTF-8: keep plain ASCII only
            $clean = preg_replace('/[^\x09\x0A\x0D\x20-\x7E]/', '?', $s);
        }
        return htmlspecialchars($clean, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    // ---------- zip (pure PHP) ----------

    private static function zip(array $files): string
    {
        $t = getdate();
        $dosTime = ($t['hours'] << 11) | ($t['minutes'] << 5) | intdiv($t['seconds'], 2);
        $dosDate = (($t['year'] - 1980) << 9) | ($t['mon'] << 5) | $t['mday'];
        $compress = function_exists('gzdeflate');

        $out = '';
        $central = '';
        $count = 0;
        foreach ($files as $name => $data) {
            $crc = crc32($data);
            $size = strlen($data);
            $packed = $data;
            $method = 0;
            if ($compress && $size > 0) {
                $d = gzdeflate($data, 6);
                if ($d !== false && strlen($d) < $size) { $packed = $d; $method = 8; }
            }
            $csize = strlen($packed);
            $offset = strlen($out);
            $nameLen = strlen($name);

            $out .= pack('VvvvvvVVVvv', 0x04034b50, 20, 0x0800, $method, $dosTime, $dosDate, $crc, $csize, $size, $nameLen, 0)
                . $name . $packed;
            $central .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0x0800, $method, $dosTime, $dosDate, $crc, $csize, $size,
                $nameLen, 0, 0, 0, 0, 0, $offset) . $name;
            $count++;
        }
        return $out . $central . pack('VvvvvVVv', 0x06054b50, 0, 0, $count, $count, strlen($central), strlen($out), 0);
    }
}
