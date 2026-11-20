<?php

namespace App\Support;

use DateTimeInterface;
use RuntimeException;
use ZipArchive;

/**
 * Escritor mínimo de libros .xlsx (Office Open XML) construido sobre la extensión zip,
 * para poder descargar reportes a Excel sin instalar una librería de hojas de cálculo.
 *
 * Cada hoja tiene líneas de título opcionales, una fila de encabezado con las etiquetas
 * de columna, las filas de datos, encabezados congelados y filtros. El tipo de columna
 * define el formato numérico: texto, moneda, porcentaje (valor en unidades de porcentaje,
 * p. ej. 41.5), entero, decimal y fecha.
 */
final class XlsxWriter
{
    private const STYLE_TITLE = 1;

    private const STYLE_HEADER = 2;

    private const STYLE_NOTE = 12;

    private const STYLE_BOLD = 14;

    /**
     * Índice de estilo de celda de cada tipo de columna: [normal, total].
     *
     * @var array<string, array{0: int, 1: int}>
     */
    private const TYPE_STYLES = [
        'text' => [3, 8],
        'money' => [4, 9],
        'percent' => [5, 10],
        'date' => [6, 13],
        'integer' => [7, 11],
        'decimal' => [4, 9],
    ];

    /**
     * @var array<int, array{name: string, columns: array<int, array<string, mixed>>, rows: array<int, array<string, mixed>>, titles: array<int, string>, notes: array<int, string>}>
     */
    private array $sheets = [];

    /**
     * @param  array<int, array{key: string, label: string, type?: string, width?: int|float}>  $columns
     * @param  iterable<array<string, mixed>>  $rows  Una fila con '_total' => true se dibuja como fila de total.
     * @param  array<int, string>  $titles  Líneas escritas encima de la tabla.
     * @param  array<int, string>  $notes  Líneas escritas debajo de la tabla.
     */
    public function addSheet(string $name, array $columns, iterable $rows, array $titles = [], array $notes = []): self
    {
        $this->sheets[] = [
            'name' => $this->sheetName($name),
            'columns' => array_values($columns),
            'rows' => is_array($rows) ? array_values($rows) : iterator_to_array($rows, false),
            'titles' => $titles,
            'notes' => $notes,
        ];

        return $this;
    }

    /**
     * Escribe el libro en un archivo.
     */
    public function save(string $path): void
    {
        if ($this->sheets === []) {
            throw new RuntimeException('A workbook needs at least one sheet.');
        }

        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("Could not create the workbook at {$path}.");
        }

        $zip->addFromString('[Content_Types].xml', $this->contentTypes());
        $zip->addFromString('_rels/.rels', $this->rootRelationships());
        $zip->addFromString('xl/workbook.xml', $this->workbook());
        $zip->addFromString('xl/_rels/workbook.xml.rels', $this->workbookRelationships());
        $zip->addFromString('xl/styles.xml', $this->styles());

        foreach ($this->sheets as $index => $sheet) {
            $zip->addFromString('xl/worksheets/sheet'.($index + 1).'.xml', $this->worksheet($sheet));
        }

        $zip->close();
    }

    private function sheetName(string $name): string
    {
        $clean = trim(str_replace(['\\', '/', '*', '?', ':', '[', ']'], ' ', $name));

        return mb_substr($clean === '' ? 'Hoja' : $clean, 0, 31);
    }

    private function xml(string $value): string
    {
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $value) ?? '';

        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private function contentTypes(): string
    {
        $sheets = '';
        foreach ($this->sheets as $index => $sheet) {
            $sheets .= '<Override PartName="/xl/worksheets/sheet'.($index + 1).'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .$sheets
            .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            .'</Types>';
    }

    private function rootRelationships(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            .'</Relationships>';
    }

    private function workbook(): string
    {
        $sheets = '';
        foreach ($this->sheets as $index => $sheet) {
            $number = $index + 1;
            $sheets .= '<sheet name="'.$this->xml($sheet['name'])."\" sheetId=\"{$number}\" r:id=\"rId{$number}\"/>";
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheets>'.$sheets.'</sheets></workbook>';
    }

    private function workbookRelationships(): string
    {
        $relationships = '';
        foreach ($this->sheets as $index => $sheet) {
            $number = $index + 1;
            $relationships .= "<Relationship Id=\"rId{$number}\" Type=\"http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet\" Target=\"worksheets/sheet{$number}.xml\"/>";
        }

        $stylesId = count($this->sheets) + 1;

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .$relationships
            ."<Relationship Id=\"rId{$stylesId}\" Type=\"http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles\" Target=\"styles.xml\"/>"
            .'</Relationships>';
    }

    /**
     * Formatos de celda (el índice en los comentarios coincide con las constantes STYLE_* y TYPE_STYLES).
     */
    private function styles(): string
    {
        $dateFormat = $this->xml($this->excelDateFormat());
        $border = '<border><left style="thin"><color rgb="FF8EA8C8"/></left><right style="thin"><color rgb="FF8EA8C8"/></right><top style="thin"><color rgb="FF8EA8C8"/></top><bottom style="thin"><color rgb="FF8EA8C8"/></bottom><diagonal/></border>';

        $xf = fn (int $numFmt, int $font, int $fill, int $border, string $alignment = ''): string => sprintf(
            '<xf numFmtId="%d" fontId="%d" fillId="%d" borderId="%d" xfId="0"%s%s%s%s%s>%s</xf>',
            $numFmt, $font, $fill, $border,
            $numFmt > 0 ? ' applyNumberFormat="1"' : '',
            $font > 0 ? ' applyFont="1"' : '',
            $fill > 0 ? ' applyFill="1"' : '',
            $border > 0 ? ' applyBorder="1"' : '',
            $alignment !== '' ? ' applyAlignment="1"' : '',
            $alignment,
        );

        $cellXfs = [
            $xf(0, 0, 0, 0),                                                                                        // 0 predeterminado
            $xf(0, 2, 0, 0),                                                                                        // 1 título
            $xf(0, 3, 2, 1, '<alignment horizontal="center" vertical="center" wrapText="1"/>'),                     // 2 encabezado
            $xf(0, 0, 0, 1),                                                                                        // 3 texto
            $xf(4, 0, 0, 1),                                                                                        // 4 moneda
            $xf(10, 0, 0, 1),                                                                                       // 5 porcentaje
            $xf(164, 0, 0, 1, '<alignment horizontal="center"/>'),                                                  // 6 fecha
            $xf(3, 0, 0, 1),                                                                                        // 7 entero
            $xf(0, 1, 3, 1),                                                                                        // 8 total texto
            $xf(4, 1, 3, 1),                                                                                        // 9 total moneda
            $xf(10, 1, 3, 1),                                                                                       // 10 total porcentaje
            $xf(3, 1, 3, 1),                                                                                        // 11 total entero
            $xf(0, 4, 0, 0),                                                                                        // 12 nota
            $xf(164, 1, 3, 1, '<alignment horizontal="center"/>'),                                                  // 13 total fecha
            $xf(0, 1, 0, 0),                                                                                        // 14 negrita
        ];

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<numFmts count="1"><numFmt numFmtId="164" formatCode="'.$dateFormat.'"/></numFmts>'
            .'<fonts count="5">'
            .'<font><sz val="11"/><name val="Calibri"/></font>'
            .'<font><b/><sz val="11"/><name val="Calibri"/></font>'
            .'<font><b/><sz val="14"/><color rgb="FF1C3F73"/><name val="Calibri"/></font>'
            .'<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>'
            .'<font><i/><sz val="10"/><color rgb="FF64748B"/><name val="Calibri"/></font>'
            .'</fonts>'
            .'<fills count="4">'
            .'<fill><patternFill patternType="none"/></fill>'
            .'<fill><patternFill patternType="gray125"/></fill>'
            .'<fill><patternFill patternType="solid"><fgColor rgb="FF1C3F73"/><bgColor indexed="64"/></patternFill></fill>'
            .'<fill><patternFill patternType="solid"><fgColor rgb="FFD9E4F2"/><bgColor indexed="64"/></patternFill></fill>'
            .'</fills>'
            .'<borders count="2"><border><left/><right/><top/><bottom/><diagonal/></border>'.$border.'</borders>'
            .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            .'<cellXfs count="'.count($cellXfs).'">'.implode('', $cellXfs).'</cellXfs>'
            .'<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            .'</styleSheet>';
    }

    /**
     * Formato numérico de Excel que coincide con el DATE_FORMAT de la aplicación.
     */
    private function excelDateFormat(): string
    {
        return strtr(DateFormat::pattern(), ['d' => 'dd', 'm' => 'mm', 'Y' => 'yyyy']);
    }

    /**
     * @param  array{name: string, columns: array<int, array<string, mixed>>, rows: array<int, array<string, mixed>>, titles: array<int, string>, notes: array<int, string>}  $sheet
     */
    private function worksheet(array $sheet): string
    {
        $columns = $sheet['columns'];
        $columnCount = max(count($columns), 1);
        $rowNumber = 0;
        $rows = '';

        foreach ($sheet['titles'] as $index => $title) {
            $rowNumber++;
            $rows .= '<row r="'.$rowNumber.'">'.$this->textCell($this->reference(0, $rowNumber), $title, $index === 0 ? self::STYLE_TITLE : self::STYLE_BOLD).'</row>';
        }

        if ($sheet['titles'] !== []) {
            $rowNumber++;
        }

        $headerRow = $rowNumber + 1;
        $rowNumber = $headerRow;
        $headerCells = '';
        foreach ($columns as $index => $column) {
            $headerCells .= $this->textCell($this->reference($index, $headerRow), (string) $column['label'], self::STYLE_HEADER);
        }
        $rows .= '<row r="'.$headerRow.'" ht="32" customHeight="1">'.$headerCells.'</row>';

        foreach ($sheet['rows'] as $data) {
            $rowNumber++;
            $isTotal = (bool) ($data['_total'] ?? false);
            $cells = '';

            foreach ($columns as $index => $column) {
                $cells .= $this->cell($this->reference($index, $rowNumber), $data[$column['key']] ?? null, $column['type'] ?? 'text', $isTotal);
            }

            $rows .= '<row r="'.$rowNumber.'">'.$cells.'</row>';
        }

        $lastDataRow = $rowNumber;

        if ($sheet['notes'] !== []) {
            $rowNumber++;
            foreach ($sheet['notes'] as $note) {
                $rowNumber++;
                $rows .= '<row r="'.$rowNumber.'">'.$this->textCell($this->reference(0, $rowNumber), $note, self::STYLE_NOTE).'</row>';
            }
        }

        $widths = '';
        foreach ($columns as $index => $column) {
            $number = $index + 1;
            $widths .= '<col min="'.$number.'" max="'.$number.'" width="'.(float) ($column['width'] ?? 16).'" customWidth="1"/>';
        }

        $lastColumn = $this->columnLetters($columnCount - 1);
        $filter = $sheet['rows'] !== [] ? '<autoFilter ref="A'.$headerRow.':'.$lastColumn.$lastDataRow.'"/>' : '';

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<dimension ref="A1:'.$lastColumn.max($rowNumber, 1).'"/>'
            .'<sheetViews><sheetView workbookViewId="0" showGridLines="0">'
            .'<pane ySplit="'.$headerRow.'" topLeftCell="A'.($headerRow + 1).'" activePane="bottomLeft" state="frozen"/>'
            .'</sheetView></sheetViews>'
            .'<sheetFormatPr defaultRowHeight="15"/>'
            .'<cols>'.$widths.'</cols>'
            .'<sheetData>'.$rows.'</sheetData>'
            .$filter
            .'</worksheet>';
    }

    private function cell(string $reference, mixed $value, string $type, bool $isTotal): string
    {
        $style = (self::TYPE_STYLES[$type] ?? self::TYPE_STYLES['text'])[$isTotal ? 1 : 0];

        if ($value === null || $value === '') {
            return '<c r="'.$reference.'" s="'.$style.'"/>';
        }

        if ($type === 'date') {
            $serial = $this->dateSerial($value);

            return $serial === null
                ? $this->textCell($reference, (string) $value, $style)
                : '<c r="'.$reference.'" s="'.$style.'"><v>'.$serial.'</v></c>';
        }

        if ($type === 'text' || ! is_numeric($value)) {
            return $this->textCell($reference, (string) $value, $style);
        }

        $number = (float) $value;
        $number = $type === 'percent' ? $number / 100 : $number;

        return '<c r="'.$reference.'" s="'.$style.'"><v>'.$this->number($number).'</v></c>';
    }

    private function textCell(string $reference, string $text, int $style): string
    {
        return '<c r="'.$reference.'" s="'.$style.'" t="inlineStr"><is><t xml:space="preserve">'.$this->xml($text).'</t></is></c>';
    }

    private function number(float $value): string
    {
        return rtrim(rtrim(sprintf('%.10F', $value), '0'), '.') ?: '0';
    }

    /**
     * Número de serie de Excel de una fecha (días desde 1899-12-30).
     */
    private function dateSerial(mixed $value): ?int
    {
        if ($value instanceof DateTimeInterface) {
            $year = (int) $value->format('Y');
            $month = (int) $value->format('n');
            $day = (int) $value->format('j');
        } elseif (is_string($value) && preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $value, $parts)) {
            [$year, $month, $day] = [(int) $parts[1], (int) $parts[2], (int) $parts[3]];
        } else {
            return null;
        }

        return intdiv(gmmktime(0, 0, 0, $month, $day, $year), 86400) + 25569;
    }

    private function reference(int $columnIndex, int $row): string
    {
        return $this->columnLetters($columnIndex).$row;
    }

    private function columnLetters(int $index): string
    {
        $letters = '';
        for ($number = $index + 1; $number > 0; $number = intdiv($number - 1, 26)) {
            $letters = chr(65 + ($number - 1) % 26).$letters;
        }

        return $letters;
    }
}
