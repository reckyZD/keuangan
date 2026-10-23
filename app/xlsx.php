<?php
/**
 * Penulis file .xlsx minimal (tanpa library) memakai ZipArchive bawaan PHP.
 *
 * $sheets = [ ['name'=>'Rekap Kas', 'widths'=>[6,12,...], 'freeze'=>6 (baris yang dibekukan, opsional),
 *              'rows'=>[ [sel, sel, ...], [], ... ] ], ... ]
 * Satu sel: null (dilewati) | teks | angka | [nilai, gaya] ; [null, gaya] = sel kosong bergaya.
 */
const XS_TITLE = 1, XS_HEAD = 2, XS_TEXT = 3, XS_DATE = 4, XS_NUM = 5, XS_TOTLBL = 6, XS_TOTNUM = 7, XS_BOLD = 8, XS_TIME = 9, XS_DEC = 10, XS_TOTDEC = 11;

function xl_col(int $i): string {            // 0 -> A, 25 -> Z, 26 -> AA
    $s = '';
    for ($i++; $i > 0; $i = intdiv($i - 1, 26)) $s = chr(65 + ($i - 1) % 26) . $s;
    return $s;
}
function xl_esc(string $s): string {
    $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $s);
    return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}
/** 'YYYY-MM-DD' -> nomor seri tanggal Excel. */
function xl_date(string $ymd): int {
    return (int)(strtotime($ymd . ' 00:00:00 UTC') / 86400) + 25569;
}

function xl_sheet_xml(array $sh): string {
    $x = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
       . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
       . '<sheetPr><pageSetUpPr fitToPage="1"/></sheetPr>'
       . '<sheetViews><sheetView workbookViewId="0">';
    if (!empty($sh['freeze'])) {
        $n = (int)$sh['freeze'];
        $x .= '<pane ySplit="' . $n . '" topLeftCell="A' . ($n + 1) . '" activePane="bottomLeft" state="frozen"/><selection pane="bottomLeft"/>';
    }
    $x .= '</sheetView></sheetViews><sheetFormatPr defaultRowHeight="15"/>';
    if (!empty($sh['widths'])) {
        $x .= '<cols>';
        foreach ($sh['widths'] as $i => $w) {
            $x .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . $w . '" customWidth="1"/>';
        }
        $x .= '</cols>';
    }
    $x .= '<sheetData>';
    foreach ($sh['rows'] as $ri => $cells) {
        $r = $ri + 1;
        $x .= '<row r="' . $r . '">';
        foreach ($cells as $ci => $cell) {
            if ($cell === null) continue;
            [$v, $s] = is_array($cell) ? [$cell[0], (int)($cell[1] ?? 0)] : [$cell, 0];
            $ref = xl_col($ci) . $r;
            $sa  = $s ? ' s="' . $s . '"' : '';
            if ($v === null || $v === '') {
                $x .= '<c r="' . $ref . '"' . $sa . '/>';
            } elseif (is_int($v) || is_float($v)) {
                $x .= '<c r="' . $ref . '"' . $sa . '><v>' . $v . '</v></c>';
            } else {
                $x .= '<c r="' . $ref . '"' . $sa . ' t="inlineStr"><is><t xml:space="preserve">' . xl_esc((string)$v) . '</t></is></c>';
            }
        }
        $x .= '</row>';
    }
    $x .= '</sheetData>'
        . '<pageMargins left="0.5" right="0.5" top="0.6" bottom="0.6" header="0.3" footer="0.3"/>'
        . '<pageSetup paperSize="9" orientation="landscape" fitToWidth="1" fitToHeight="0"/>'
        . '</worksheet>';
    return $x;
}

function xl_styles_xml(): string {
    $b = '<border><left style="thin"><color rgb="FFD3D6CE"/></left><right style="thin"><color rgb="FFD3D6CE"/></right>'
       . '<top style="thin"><color rgb="FFD3D6CE"/></top><bottom style="thin"><color rgb="FFD3D6CE"/></bottom><diagonal/></border>';
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
     . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
     . '<numFmts count="2"><numFmt numFmtId="164" formatCode="dd/mm/yyyy"/><numFmt numFmtId="165" formatCode="0.00"/></numFmts>'
     . '<fonts count="4">'
     .   '<font><sz val="11"/><name val="Calibri"/></font>'
     .   '<font><b/><sz val="11"/><name val="Calibri"/></font>'
     .   '<font><b/><sz val="15"/><name val="Calibri"/></font>'
     .   '<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>'
     . '</fonts>'
     . '<fills count="4">'
     .   '<fill><patternFill patternType="none"/></fill>'
     .   '<fill><patternFill patternType="gray125"/></fill>'
     .   '<fill><patternFill patternType="solid"><fgColor rgb="FF1F2A44"/><bgColor indexed="64"/></patternFill></fill>'
     .   '<fill><patternFill patternType="solid"><fgColor rgb="FFFBF1DA"/><bgColor indexed="64"/></patternFill></fill>'
     . '</fills>'
     . '<borders count="2"><border><left/><right/><top/><bottom/><diagonal/></border>' . $b . '</borders>'
     . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
     . '<cellXfs count="12">'
     .   '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
     .   '<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
     .   '<xf numFmtId="0" fontId="3" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
     .   '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1"><alignment vertical="top" wrapText="1"/></xf>'
     .   '<xf numFmtId="164" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="top"/></xf>'
     .   '<xf numFmtId="3" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1" applyAlignment="1"><alignment vertical="top"/></xf>'
     .   '<xf numFmtId="0" fontId="1" fillId="3" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1"/>'
     .   '<xf numFmtId="3" fontId="1" fillId="3" borderId="1" xfId="0" applyNumberFormat="1" applyFont="1" applyFill="1" applyBorder="1"/>'
     .   '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
     .   '<xf numFmtId="20" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="top"/></xf>'
     .   '<xf numFmtId="165" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="top"/></xf>'
     .   '<xf numFmtId="165" fontId="1" fillId="3" borderId="1" xfId="0" applyNumberFormat="1" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center"/></xf>'
     . '</cellXfs>'
     . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
     . '</styleSheet>';
}

/** Tulis workbook ke $path. Return true jika berhasil. */
function xlsx_save(array $sheets, string $path): bool {
    if (!class_exists('ZipArchive')) return false;
    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) return false;
    $hdr = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
    $n   = count($sheets);

    $ct = $hdr . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>';
    for ($i = 1; $i <= $n; $i++) {
        $ct .= '<Override PartName="/xl/worksheets/sheet' . $i . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
    }
    $ct .= '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>';

    $rels = $hdr . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
          . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>';

    $wb = $hdr . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
        . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>';
    $wbrels = $hdr . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
    foreach ($sheets as $i => $sh) {
        $k = $i + 1;
        $nm = mb_substr(preg_replace('/[\[\]\:\*\?\/\\\\]/', ' ', (string)$sh['name']), 0, 31);
        $wb .= '<sheet name="' . xl_esc($nm) . '" sheetId="' . $k . '" r:id="rId' . $k . '"/>';
        $wbrels .= '<Relationship Id="rId' . $k . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $k . '.xml"/>';
        $zip->addFromString('xl/worksheets/sheet' . $k . '.xml', xl_sheet_xml($sh));
    }
    $wb .= '</sheets></workbook>';
    $wbrels .= '<Relationship Id="rId' . ($n + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>';

    $zip->addFromString('[Content_Types].xml', $ct);
    $zip->addFromString('_rels/.rels', $rels);
    $zip->addFromString('xl/workbook.xml', $wb);
    $zip->addFromString('xl/_rels/workbook.xml.rels', $wbrels);
    $zip->addFromString('xl/styles.xml', xl_styles_xml());
    return $zip->close();
}
