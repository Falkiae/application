<?php
/**
 * Simple XLSX generator using ZipArchive (no external dependencies)
 * Creates multi-sheet Excel files compatible with Excel/LibreOffice/Google Sheets
 */
class XlsxWriter {
    private array $sheets = [];
    private array $sharedStrings = [];
    private array $sharedStringIndex = [];

    public function addSheet(string $name, array $headers, array $rows): void {
        $this->sheets[] = [
            'name' => $name,
            'headers' => $headers,
            'rows' => $rows,
        ];
    }

    private function getSharedString(string $value): int {
        if (isset($this->sharedStringIndex[$value])) {
            return $this->sharedStringIndex[$value];
        }
        $idx = count($this->sharedStrings);
        $this->sharedStrings[] = $value;
        $this->sharedStringIndex[$value] = $idx;
        return $idx;
    }

    private function colLetter(int $n): string {
        $letter = '';
        do {
            $letter = chr(65 + ($n % 26)) . $letter;
            $n = intdiv($n, 26) - 1;
        } while ($n >= 0);
        return $letter;
    }

    private function buildWorksheetXml(array $headers, array $rows): string {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
        $xml .= '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';

        // Column widths
        $colCount = count($headers);
        $xml .= '<cols>';
        for ($i = 0; $i < $colCount; $i++) {
            $xml .= '<col min="' . ($i+1) . '" max="' . ($i+1) . '" width="18" customWidth="1"/>';
        }
        $xml .= '</cols>';

        $xml .= '<sheetData>';

        // Header row (row 1) - style 1 = bold blue header
        $xml .= '<row r="1">';
        foreach ($headers as $ci => $header) {
            $colRef = $this->colLetter($ci) . '1';
            $strIdx = $this->getSharedString((string)$header);
            $xml .= '<c r="' . $colRef . '" t="s" s="1"><v>' . $strIdx . '</v></c>';
        }
        $xml .= '</row>';

        // Data rows
        foreach ($rows as $ri => $row) {
            $rowNum = $ri + 2;
            $xml .= '<row r="' . $rowNum . '">';
            $values = array_values($row);
            foreach ($values as $ci => $cell) {
                $colRef = $this->colLetter($ci) . $rowNum;
                if ($cell === null || $cell === '') {
                    $xml .= '<c r="' . $colRef . '"/>';
                } elseif (is_numeric($cell)) {
                    $xml .= '<c r="' . $colRef . '" t="n"><v>' . $cell . '</v></c>';
                } else {
                    $strIdx = $this->getSharedString((string)$cell);
                    $xml .= '<c r="' . $colRef . '" t="s"><v>' . $strIdx . '</v></c>';
                }
            }
            $xml .= '</row>';
        }

        $xml .= '</sheetData>';
        $xml .= '<autoFilter ref="A1:' . $this->colLetter($colCount - 1) . '1"/>';
        $xml .= '</worksheet>';
        return $xml;
    }

    private function buildSharedStringsXml(): string {
        $count = count($this->sharedStrings);
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
        $xml .= '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="' . $count . '" uniqueCount="' . $count . '">';
        foreach ($this->sharedStrings as $s) {
            $xml .= '<si><t xml:space="preserve">' . htmlspecialchars((string)$s, ENT_XML1, 'UTF-8') . '</t></si>';
        }
        $xml .= '</sst>';
        return $xml;
    }

    private function buildStylesXml(): string {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
  <fonts count="2">
    <font><sz val="11"/><name val="Calibri"/></font>
    <font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>
  </fonts>
  <fills count="3">
    <fill><patternFill patternType="none"/></fill>
    <fill><patternFill patternType="gray125"/></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FF596FF3"/></patternFill></fill>
  </fills>
  <borders count="1">
    <border><left/><right/><top/><bottom/><diagonal/></border>
  </borders>
  <cellStyleXfs count="1">
    <xf numFmtId="0" fontId="0" fillId="0" borderId="0"/>
  </cellStyleXfs>
  <cellXfs count="2">
    <xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>
    <xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/>
  </cellXfs>
</styleSheet>';
    }

    private function buildWorkbookXml(): string {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
        $xml .= '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">';
        $xml .= '<sheets>';
        foreach ($this->sheets as $i => $sheet) {
            $sheetId = $i + 1;
            $xml .= '<sheet name="' . htmlspecialchars($sheet['name'], ENT_XML1) . '" sheetId="' . $sheetId . '" r:id="rId' . $sheetId . '"/>';
        }
        $xml .= '</sheets>';
        $xml .= '</workbook>';
        return $xml;
    }

    private function buildWorkbookRels(): string {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
        $xml .= '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
        foreach ($this->sheets as $i => $sheet) {
            $sheetId = $i + 1;
            $xml .= '<Relationship Id="rId' . $sheetId . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $sheetId . '.xml"/>';
        }
        $sheetCount = count($this->sheets);
        $xml .= '<Relationship Id="rId' . ($sheetCount+1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';
        $xml .= '<Relationship Id="rId' . ($sheetCount+2) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>';
        $xml .= '</Relationships>';
        return $xml;
    }

    private function buildContentTypes(): string {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
        $xml .= '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">';
        $xml .= '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>';
        $xml .= '<Default Extension="xml" ContentType="application/xml"/>';
        $xml .= '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>';
        foreach ($this->sheets as $i => $sheet) {
            $sheetId = $i + 1;
            $xml .= '<Override PartName="/xl/worksheets/sheet' . $sheetId . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }
        $xml .= '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
        $xml .= '<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>';
        $xml .= '</Types>';
        return $xml;
    }

    private function buildRootRels(): string {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>
</Relationships>';
    }

    public function generate(): string {
        // Reset shared strings for fresh generation
        $this->sharedStrings = [];
        $this->sharedStringIndex = [];

        // Pre-build all worksheets to populate shared strings
        $worksheetXmls = [];
        foreach ($this->sheets as $sheet) {
            $worksheetXmls[] = $this->buildWorksheetXml($sheet['headers'], $sheet['rows']);
        }

        // Build ZIP in memory
        $tmpFile = tempnam(sys_get_temp_dir(), 'xlsx_');
        $zip = new ZipArchive();
        if ($zip->open($tmpFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Impossible de créer le fichier XLSX');
        }

        $zip->addFromString('[Content_Types].xml', $this->buildContentTypes());
        $zip->addFromString('_rels/.rels', $this->buildRootRels());
        $zip->addFromString('xl/workbook.xml', $this->buildWorkbookXml());
        $zip->addFromString('xl/_rels/workbook.xml.rels', $this->buildWorkbookRels());
        $zip->addFromString('xl/styles.xml', $this->buildStylesXml());

        foreach ($worksheetXmls as $i => $wsXml) {
            $zip->addFromString('xl/worksheets/sheet' . ($i+1) . '.xml', $wsXml);
        }

        $zip->addFromString('xl/sharedStrings.xml', $this->buildSharedStringsXml());
        $zip->close();

        $content = file_get_contents($tmpFile);
        unlink($tmpFile);
        return $content;
    }

    public function download(string $filename): void {
        $content = $this->generate();
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . addslashes($filename) . '"');
        header('Content-Length: ' . strlen($content));
        header('Cache-Control: no-cache, must-revalidate');
        echo $content;
        exit;
    }
}
