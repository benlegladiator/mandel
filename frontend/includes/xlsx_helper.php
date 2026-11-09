<?php
/**
 * Mandel ERP - Native Excel (.xlsx) & CSV Export Helper
 * Generates genuine Microsoft OpenXML (.xlsx) files with zero external dependencies.
 * Falls back safely to UTF-8 BOM CSV if ZipArchive is unavailable.
 */
class MandelExcelExporter {

    public static function export($filename, $sheetName, array $headers, array $rows) {
        if (!preg_match('/\.(xlsx|csv)$/i', $filename)) {
            $filename .= '.xlsx';
        }

        // Si ZipArchive est disponible, générer un vrai fichier .xlsx natif
        if (class_exists('ZipArchive')) {
            $tmp = self::buildXlsx($sheetName, $headers, $rows);
            if ($tmp && file_exists($tmp)) {
                if (ob_get_level()) {
                    ob_end_clean();
                }
                header('Content-Description: File Transfer');
                header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
                header('Content-Disposition: attachment; filename="' . $filename . '"');
                header('Content-Transfer-Encoding: binary');
                header('Expires: 0');
                header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
                header('Pragma: public');
                header('Content-Length: ' . filesize($tmp));
                readfile($tmp);
                @unlink($tmp);
                exit;
            }
        }

        // Fallback sécurisé en CSV UTF-8 avec BOM et point-virgule (Excel français)
        $csvFilename = preg_replace('/\.xlsx$/i', '.csv', $filename);
        if (ob_get_level()) {
            ob_end_clean();
        }
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $csvFilename . '"');
        echo "\xEF\xBB\xBF"; // UTF-8 BOM

        $out = fopen('php://output', 'w');
        fputcsv($out, $headers, ';');
        foreach ($rows as $r) {
            fputcsv($out, array_values($r), ';');
        }
        fclose($out);
        exit;
    }

    private static function buildXlsx($sheetName, array $headers, array $rows) {
        $zip = new ZipArchive();
        $tempFile = tempnam(sys_get_temp_dir(), 'mdl_xlsx_');
        if ($zip->open($tempFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return false;
        }

        // 1. [Content_Types].xml
        $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
            '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">' .
            '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>' .
            '<Default Extension="xml" ContentType="application/xml"/>' .
            '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>' .
            '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>' .
            '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>' .
            '</Types>';
        $zip->addFromString('[Content_Types].xml', $contentTypes);

        // 2. _rels/.rels
        $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
            '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' .
            '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>' .
            '</Relationships>';
        $zip->addFromString('_rels/.rels', $rels);

        // 3. xl/_rels/workbook.xml.rels
        $wbRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
            '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' .
            '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>' .
            '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>' .
            '</Relationships>';
        $zip->addFromString('xl/_rels/workbook.xml.rels', $wbRels);

        // 4. xl/workbook.xml
        $safeSheetName = htmlspecialchars(substr($sheetName, 0, 31), ENT_XML1, 'UTF-8');
        $workbook = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
            '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">' .
            '<sheets><sheet name="' . $safeSheetName . '" sheetId="1" r:id="rId1"/></sheets>' .
            '</workbook>';
        $zip->addFromString('xl/workbook.xml', $workbook);

        // 5. xl/styles.xml (Styles modernes : en-tête bleu nuit/ardoise, texte clair, format numérique)
        $styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
            '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">' .
            '<numFmts count="2">' .
            '<numFmt numFmtId="164" formatCode="#,##0"/>' .
            '<numFmt numFmtId="165" formatCode="#,##0.00"/>' .
            '</numFmts>' .
            '<fonts count="2">' .
            '<font><sz val="10.5"/><name val="Segoe UI"/></font>' .
            '<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Segoe UI"/></font>' .
            '</fonts>' .
            '<fills count="3">' .
            '<fill><patternFill patternType="none"/></fill>' .
            '<fill><patternFill patternType="gray125"/></fill>' .
            '<fill><patternFill patternType="solid"><fgColor rgb="FF0F172A"/></patternFill></fill>' . // Dark slate
            '</fills>' .
            '<borders count="2">' .
            '<border><left/><right/><top/><bottom/></border>' .
            '<border>' .
            '<left style="thin"><color rgb="FFE2E8F0"/></left>' .
            '<right style="thin"><color rgb="FFE2E8F0"/></right>' .
            '<top style="thin"><color rgb="FFE2E8F0"/></top>' .
            '<bottom style="thin"><color rgb="FFE2E8F0"/></bottom>' .
            '</border>' .
            '</borders>' .
            '<cellXfs count="4">' .
            '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0"/>' . // Normal text [0]
            '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>' . // Header [1]
            '<xf numFmtId="164" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyAlignment="1"><alignment horizontal="right"/></xf>' . // Integer [2]
            '<xf numFmtId="165" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyAlignment="1"><alignment horizontal="right"/></xf>' . // Float [3]
            '</cellXfs>' .
            '</styleSheet>';
        $zip->addFromString('xl/styles.xml', $styles);

        // 6. xl/worksheets/sheet1.xml
        $sheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
            '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">' .
            '<sheetViews><sheetView tabSelected="1" workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>' .
            '<sheetData>';

        $rowIdx = 1;
        // Ligne d'en-tête (figée en haut)
        $sheetXml .= '<row r="' . $rowIdx . '" ht="26" customHeight="1">';
        $colIdx = 0;
        foreach ($headers as $h) {
            $colLetter = self::colLetter($colIdx);
            $sheetXml .= '<c r="' . $colLetter . $rowIdx . '" s="1" t="inlineStr"><is><t>' . htmlspecialchars($h, ENT_XML1, 'UTF-8') . '</t></is></c>';
            $colIdx++;
        }
        $sheetXml .= '</row>';

        // Données
        foreach ($rows as $row) {
            $rowIdx++;
            $sheetXml .= '<row r="' . $rowIdx . '" ht="20" customHeight="1">';
            $colIdx = 0;
            foreach ($row as $val) {
                $colLetter = self::colLetter($colIdx);
                if (is_int($val) || (is_numeric($val) && strpos((string)$val, '.') === false && !preg_match('/^0\d+/', (string)$val))) {
                    $sheetXml .= '<c r="' . $colLetter . $rowIdx . '" s="2"><v>' . intval($val) . '</v></c>';
                } elseif (is_float($val) || (is_numeric($val) && strpos((string)$val, '.') !== false)) {
                    $sheetXml .= '<c r="' . $colLetter . $rowIdx . '" s="3"><v>' . floatval($val) . '</v></c>';
                } else {
                    $strVal = (string)$val;
                    $sheetXml .= '<c r="' . $colLetter . $rowIdx . '" s="0" t="inlineStr"><is><t>' . htmlspecialchars($strVal, ENT_XML1, 'UTF-8') . '</t></is></c>';
                }
                $colIdx++;
            }
            $sheetXml .= '</row>';
        }

        $sheetXml .= '</sheetData></worksheet>';
        $zip->addFromString('xl/worksheets/sheet1.xml', $sheetXml);
        $zip->close();

        return $tempFile;
    }

    private static function colLetter($index) {
        $letters = '';
        while ($index >= 0) {
            $letters = chr($index % 26 + 65) . $letters;
            $index = intdiv($index, 26) - 1;
        }
        return $letters;
    }
}
