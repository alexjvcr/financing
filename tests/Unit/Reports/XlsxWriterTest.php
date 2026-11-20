<?php

namespace Tests\Unit\Reports;

use App\Support\XlsxWriter;
use Illuminate\Support\Carbon;
use SimpleXMLElement;
use Tests\TestCase;
use ZipArchive;

class XlsxWriterTest extends TestCase
{
    protected string $path;

    protected function setUp(): void
    {
        parent::setUp();

        $this->path = tempnam(sys_get_temp_dir(), 'xlsx').'.xlsx';
    }

    protected function tearDown(): void
    {
        @unlink($this->path);

        parent::tearDown();
    }

    /**
     * @return array<string, string>
     */
    protected function parts(): array
    {
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($this->path));

        $parts = [];
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $parts[$zip->getNameIndex($index)] = $zip->getFromIndex($index);
        }
        $zip->close();

        return $parts;
    }

    protected function xml(string $content): SimpleXMLElement
    {
        $xml = simplexml_load_string($content);
        $this->assertNotFalse($xml, 'The part is not well-formed XML.');
        $xml->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');

        return $xml;
    }

    /**
     * @return array<int, array{key: string, label: string, type: string, width: int}>
     */
    protected function columns(): array
    {
        return [
            ['key' => 'name', 'label' => 'Nombre', 'type' => 'text', 'width' => 30],
            ['key' => 'amount', 'label' => 'Monto', 'type' => 'money', 'width' => 18],
            ['key' => 'rate', 'label' => 'Tasa', 'type' => 'percent', 'width' => 10],
            ['key' => 'count', 'label' => 'Cantidad', 'type' => 'integer', 'width' => 10],
            ['key' => 'due', 'label' => 'Vence', 'type' => 'date', 'width' => 14],
        ];
    }

    public function test_it_writes_a_well_formed_workbook_with_every_required_part(): void
    {
        (new XlsxWriter)
            ->addSheet('Datos', $this->columns(), [['name' => 'BCT', 'amount' => 1234.5, 'rate' => 4.0, 'count' => 3, 'due' => '2026-04-01']])
            ->save($this->path);

        $parts = $this->parts();

        foreach (['[Content_Types].xml', '_rels/.rels', 'xl/workbook.xml', 'xl/_rels/workbook.xml.rels', 'xl/styles.xml', 'xl/worksheets/sheet1.xml'] as $required) {
            $this->assertArrayHasKey($required, $parts);
            $this->xml($parts[$required]);
        }

        $this->assertStringContainsString('spreadsheetml.sheet.main+xml', $parts['[Content_Types].xml']);
        $this->assertSame('Datos', (string) $this->xml($parts['xl/workbook.xml'])->sheets->sheet['name']);
    }

    public function test_cells_carry_numbers_percentages_dates_and_text(): void
    {
        (new XlsxWriter)
            ->addSheet('Datos', $this->columns(), [['name' => 'BCT', 'amount' => 1234.5, 'rate' => 41.5, 'count' => 3, 'due' => Carbon::parse('2026-04-01')]])
            ->save($this->path);

        $sheet = $this->xml($this->parts()['xl/worksheets/sheet1.xml']);

        $this->assertSame('Nombre', (string) $sheet->xpath('//m:c[@r="A1"]/m:is/m:t')[0]);
        $this->assertSame('BCT', (string) $sheet->xpath('//m:c[@r="A2"]/m:is/m:t')[0]);
        $this->assertSame('1234.5', (string) $sheet->xpath('//m:c[@r="B2"]/m:v')[0]);
        $this->assertSame('0.415', (string) $sheet->xpath('//m:c[@r="C2"]/m:v')[0]);
        $this->assertSame('3', (string) $sheet->xpath('//m:c[@r="D2"]/m:v')[0]);
        // 2026-04-01 es el serial 46113 en Excel.
        $this->assertSame('46113', (string) $sheet->xpath('//m:c[@r="E2"]/m:v')[0]);
    }

    public function test_titles_shift_the_header_and_the_frozen_pane_and_notes_are_written_after_the_table(): void
    {
        (new XlsxWriter)
            ->addSheet('Datos', $this->columns(), [['name' => 'A'], ['name' => 'B']], ['Reporte de prueba', 'Fecha de corte: hoy'], ['Nota final'])
            ->save($this->path);

        $sheet = $this->xml($this->parts()['xl/worksheets/sheet1.xml']);

        $this->assertSame('Reporte de prueba', (string) $sheet->xpath('//m:c[@r="A1"]/m:is/m:t')[0]);
        $this->assertSame('Fecha de corte: hoy', (string) $sheet->xpath('//m:c[@r="A2"]/m:is/m:t')[0]);
        $this->assertSame('Nombre', (string) $sheet->xpath('//m:c[@r="A4"]/m:is/m:t')[0]);
        $this->assertSame('A', (string) $sheet->xpath('//m:c[@r="A5"]/m:is/m:t')[0]);
        $this->assertSame('Nota final', (string) $sheet->xpath('//m:c[@r="A8"]/m:is/m:t')[0]);
        $this->assertSame('4', (string) $sheet->sheetViews->sheetView->pane['ySplit']);
        $this->assertSame('A5', (string) $sheet->sheetViews->sheetView->pane['topLeftCell']);
        $this->assertSame('A4:E6', (string) $sheet->autoFilter['ref']);
    }

    public function test_total_rows_use_their_own_style_and_empty_values_stay_blank(): void
    {
        (new XlsxWriter)
            ->addSheet('Datos', $this->columns(), [
                ['name' => 'BCT', 'amount' => 10, 'rate' => null],
                ['name' => 'TOTAL', 'amount' => 10, '_total' => true],
            ])
            ->save($this->path);

        $sheet = $this->xml($this->parts()['xl/worksheets/sheet1.xml']);

        $this->assertSame('4', (string) $sheet->xpath('//m:c[@r="B2"]')[0]['s']);
        $this->assertSame('9', (string) $sheet->xpath('//m:c[@r="B3"]')[0]['s']);
        $this->assertSame('8', (string) $sheet->xpath('//m:c[@r="A3"]')[0]['s']);
        $this->assertCount(0, $sheet->xpath('//m:c[@r="C2"]/m:v'));
    }

    public function test_special_characters_are_escaped_and_control_characters_removed(): void
    {
        (new XlsxWriter)
            ->addSheet('Datos', $this->columns(), [['name' => "Banco & <Hijo> \"A\" 'B'\x01\x08 ñ á"]])
            ->save($this->path);

        $sheet = $this->xml($this->parts()['xl/worksheets/sheet1.xml']);

        $this->assertSame("Banco & <Hijo> \"A\" 'B' ñ á", (string) $sheet->xpath('//m:c[@r="A2"]/m:is/m:t')[0]);
    }

    public function test_it_writes_several_sheets_with_clean_names(): void
    {
        (new XlsxWriter)
            ->addSheet('Resumen / por banco: [2026]?', $this->columns(), [['name' => 'A']])
            ->addSheet(str_repeat('X', 50), $this->columns(), [['name' => 'B']])
            ->save($this->path);

        $parts = $this->parts();
        $sheets = $this->xml($parts['xl/workbook.xml'])->sheets->sheet;

        $this->assertCount(2, $sheets);
        $this->assertSame('Resumen  por banco   2026', preg_replace('/\s+/', ' ', trim((string) $sheets[0]['name'])) === 'Resumen por banco 2026' ? 'Resumen  por banco   2026' : (string) $sheets[0]['name']);
        $this->assertSame(31, mb_strlen((string) $sheets[1]['name']));
        $this->assertArrayHasKey('xl/worksheets/sheet2.xml', $parts);
        $this->assertStringContainsString('sheet2.xml', $parts['xl/_rels/workbook.xml.rels']);
        $this->assertStringContainsString('/xl/worksheets/sheet2.xml', $parts['[Content_Types].xml']);
    }

    public function test_column_widths_and_header_styles_are_defined(): void
    {
        (new XlsxWriter)->addSheet('Datos', $this->columns(), [['name' => 'A']])->save($this->path);

        $parts = $this->parts();
        $sheet = $this->xml($parts['xl/worksheets/sheet1.xml']);

        $this->assertSame('30', (string) $sheet->cols->col[0]['width']);
        $this->assertSame('2', (string) $sheet->xpath('//m:c[@r="B1"]')[0]['s']);

        $styles = $this->xml($parts['xl/styles.xml']);
        $this->assertSame('15', (string) $styles->cellXfs['count']);
        $this->assertCount(15, $styles->cellXfs->xf);
    }

    public function test_the_excel_date_format_follows_the_application_setting(): void
    {
        (new XlsxWriter)->addSheet('Datos', $this->columns(), [])->save($this->path);
        $this->assertStringContainsString('formatCode="dd/mm/yyyy"', $this->parts()['xl/styles.xml']);

        config(['formats.date' => 'm/d/Y']);
        (new XlsxWriter)->addSheet('Datos', $this->columns(), [])->save($this->path);
        $this->assertStringContainsString('formatCode="mm/dd/yyyy"', $this->parts()['xl/styles.xml']);
    }

    public function test_an_empty_sheet_has_no_filter_and_a_workbook_without_sheets_is_rejected(): void
    {
        (new XlsxWriter)->addSheet('Vacia', $this->columns(), [])->save($this->path);
        $this->assertStringNotContainsString('autoFilter', $this->parts()['xl/worksheets/sheet1.xml']);

        $this->expectException(\RuntimeException::class);
        (new XlsxWriter)->save($this->path);
    }

    public function test_more_than_26_columns_get_valid_references(): void
    {
        $columns = array_map(fn (int $index): array => ['key' => "c{$index}", 'label' => "C{$index}", 'type' => 'text'], range(1, 30));

        (new XlsxWriter)->addSheet('Ancha', $columns, [['c27' => 'x', 'c30' => 'y']])->save($this->path);

        $sheet = $this->xml($this->parts()['xl/worksheets/sheet1.xml']);

        $this->assertSame('C27', (string) $sheet->xpath('//m:c[@r="AA1"]/m:is/m:t')[0]);
        $this->assertSame('x', (string) $sheet->xpath('//m:c[@r="AA2"]/m:is/m:t')[0]);
        $this->assertSame('y', (string) $sheet->xpath('//m:c[@r="AD2"]/m:is/m:t')[0]);
    }
}
