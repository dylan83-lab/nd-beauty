<?php
declare(strict_types=1);

/** Ekspor laporan ke Excel (.xlsx) dan PDF tanpa pustaka tambahan (cocok untuk shared hosting). */
final class Export
{
    private static function table(array $rep): array
    {
        $rows = [array_merge([$rep['title'] . ' — ' . tgl_id($rep['from'], false) . ' s/d ' . tgl_id($rep['to'], false)], array_fill(0, max(0, count($rep['cols']) - 1), ''))];
        foreach (($rep['summary'] ?? []) as $k => $v) $rows[] = array_merge([$k, (string)$v], array_fill(0, max(0, count($rep['cols']) - 2), ''));
        $rows[] = array_fill(0, count($rep['cols']), '');
        $rows[] = $rep['cols'];
        $hdr = count($rows) - 1;
        foreach ($rep['rows'] as $r) $rows[] = $r;
        if (!empty($rep['total'])) $rows[] = $rep['total'];
        return [$rows, $hdr];
    }

    private static function col(int $i): string { $s = ''; for ($i++; $i > 0; $i = intdiv($i - 1, 26)) $s = chr(65 + ($i - 1) % 26) . $s; return $s; }

    public static function xlsx(array $rep): void
    {
        [$rows, $hdr] = self::table($rep);
        $x = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
        foreach ($rows as $ri => $r) {
            $x .= '<row r="' . ($ri + 1) . '">';
            foreach (array_values($r) as $ci => $v) {
                $ref = self::col($ci) . ($ri + 1);
                $style = $ri === $hdr ? ' s="1"' : ($ri === 0 ? ' s="2"' : '');
                if ((is_int($v) || is_float($v))) $x .= '<c r="' . $ref . '"' . $style . '><v>' . $v . '</v></c>';
                else $x .= '<c r="' . $ref . '" t="inlineStr"' . $style . '><is><t xml:space="preserve">' . htmlspecialchars((string)$v, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</t></is></c>';
            }
            $x .= '</row>';
        }
        $x .= '</sheetData></worksheet>';
        $tmp = tempnam(sys_get_temp_dir(), 'ndx');
        $z = new ZipArchive();
        $z->open($tmp, ZipArchive::OVERWRITE);
        $z->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>');
        $z->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $z->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Laporan" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $z->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
        $z->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts><fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FFF7D6DF"/></patternFill></fill></fills><borders count="1"><border/></borders><cellStyleXfs count="1"><xf/></cellStyleXfs><cellXfs count="3"><xf/><xf fontId="1" fillId="2" applyFont="1" applyFill="1"/><xf fontId="1" applyFont="1"/></cellXfs></styleSheet>');
        $z->addFromString('xl/worksheets/sheet1.xml', $x);
        $z->close();
        $name = 'laporan-' . $rep['type'] . '-' . $rep['from'] . '_' . $rep['to'] . '.xlsx';
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $name . '"');
        header('Content-Length: ' . filesize($tmp));
        readfile($tmp);
        unlink($tmp);
        exit;
    }

    private static function enc(string $s): string
    {
        $s = str_replace(['•', '–', '—', '·'], ['-', '-', '-', '-'], $s);
        $c = @iconv('UTF-8', 'windows-1252//TRANSLIT//IGNORE', $s);
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $c === false ? $s : $c);
    }

    public static function pdf(array $rep): void
    {
        [$rows, $hdr] = self::table($rep);
        $W = 842; $H = 595; $m = 28; $fs = 7.5; $lh = 11;
        $ncol = count($rep['cols']);
        // Lebar kolom proporsional terhadap isi.
        $w = array_fill(0, $ncol, 6);
        foreach (array_slice($rows, $hdr) as $r) foreach (array_values($r) as $i => $v) $w[$i] = max($w[$i], min(38, mb_strlen((string)(is_int($v) ? number_format($v, 0, ',', '.') : $v))));
        $sum = array_sum($w);
        $avail = $W - 2 * $m;
        $cw = array_map(fn($x) => $x / $sum * $avail, $w);
        $pages = []; $cur = ''; $y = $H - $m;
        $new = function () use (&$cur, &$y, $H, $m) { $cur = ''; $y = $H - $m; };
        $flush = function () use (&$pages, &$cur) { $pages[] = $cur; };
        $new();
        foreach ($rows as $ri => $r) {
            if ($y < $m + $lh) { $flush(); $new(); }
            $x = $m;
            $bold = ($ri === $hdr || $ri === 0);
            if ($ri === $hdr) $cur .= sprintf("0.97 0.84 0.87 rg %.1f %.1f %.1f %.1f re f 0 g\n", $m, $y - 3, $avail, $lh);
            foreach (array_values($r) as $i => $v) {
                $txt = is_int($v) ? number_format($v, 0, ',', '.') : (is_float($v) ? rtrim(rtrim(number_format($v, 2, ',', '.'), '0'), ',') : (string)$v);
                $maxc = max(3, (int)floor($cw[$i] / ($fs * 0.5)));
                if (mb_strlen($txt) > $maxc) $txt = mb_substr($txt, 0, $maxc - 1) . '.';
                $num = is_int($v) || is_float($v);
                $tx = $num ? $x + $cw[$i] - 3 - mb_strlen($txt) * $fs * 0.5 : $x + 2;
                if ($txt !== '' && !($ri === 0 && $i > 0)) $cur .= sprintf("BT /%s %.1f Tf %.1f %.1f Td (%s) Tj ET\n", $bold ? 'F2' : 'F1', $fs, $tx, $y, self::enc($txt));
                $x += $cw[$i];
            }
            $y -= $lh;
        }
        $flush();
        $objs = [];
        $objs[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $kids = []; $n = 5;
        foreach ($pages as $i => $content) {
            $content .= sprintf("BT /F1 7 Tf %.1f %.1f Td (Halaman %d/%d - ND Japanese Head Spa) Tj ET\n", $m, 14, $i + 1, count($pages));
            $objs[$n] = '<< /Length ' . strlen($content) . " >>\nstream\n" . $content . "endstream";
            $objs[$n + 1] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ' . $W . ' ' . $H . '] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents ' . $n . ' 0 R >>';
            $kids[] = ($n + 1) . ' 0 R';
            $n += 2;
        }
        $objs[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . count($kids) . ' >>';
        $objs[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objs[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
        ksort($objs);
        $out = "%PDF-1.4\n"; $off = [];
        foreach ($objs as $i => $o) { $off[$i] = strlen($out); $out .= $i . " 0 obj\n" . $o . "\nendobj\n"; }
        $xref = strlen($out);
        $max = max(array_keys($objs));
        $out .= "xref\n0 " . ($max + 1) . "\n0000000000 65535 f \n";
        for ($i = 1; $i <= $max; $i++) $out .= isset($off[$i]) ? sprintf("%010d 00000 n \n", $off[$i]) : "0000000000 65535 f \n";
        $out .= "trailer\n<< /Size " . ($max + 1) . " /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF";
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="laporan-' . $rep['type'] . '-' . $rep['from'] . '_' . $rep['to'] . '.pdf"');
        header('Content-Length: ' . strlen($out));
        echo $out;
        exit;
    }
}
