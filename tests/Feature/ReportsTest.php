<?php

namespace Tests\Feature;

use App\Models\Banco;
use App\Models\LineaPrestamo;
use App\Models\Prestamo;
use App\Models\User;
use App\Reports\DebtPositionReport;
use App\Reports\ReportCatalog;
use App\Services\Lending\LoanScheduleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use SimpleXMLElement;
use Tests\TestCase;
use ZipArchive;

class ReportsTest extends TestCase
{
    use RefreshDatabase;

    protected LineaPrestamo $bctLine;

    protected LineaPrestamo $bncrLine;

    protected LineaPrestamo $bacLine;

    protected Prestamo $bctLoan;

    protected Prestamo $secondBctLoan;

    protected Prestamo $bncrLoan;

    protected Prestamo $bacLoan;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-18');
        $this->actingAs(User::factory()->create());

        $this->bctLine = LineaPrestamo::factory()->create([
            'nombre' => 'BCT DOLARES', 'banco_id' => Banco::factory()->create(['nombre_banco' => 'BCT'])->id, 'monto_maximo' => 65000000,
            'tasa_interes' => 2, 'tasa_spread' => 2, 'moneda' => 'DOLARES', 'fecha_vencmiento' => '2032-03-07',
        ]);
        $this->bncrLine = LineaPrestamo::factory()->create([
            'nombre' => 'BNCR DOLARES', 'banco_id' => Banco::factory()->create(['nombre_banco' => 'BNCR'])->id, 'monto_maximo' => 35000000,
            'tasa_interes' => 1.5, 'tasa_spread' => 1.5, 'moneda' => 'DOLARES', 'fecha_vencmiento' => '2027-03-01',
        ]);
        $this->bacLine = LineaPrestamo::factory()->create([
            'nombre' => 'BAC COLONES', 'banco_id' => Banco::factory()->create(['nombre_banco' => 'BAC'])->id, 'monto_maximo' => 10000000,
            'tasa_interes' => 6, 'tasa_spread' => 2, 'moneda' => 'COLONES', 'fecha_vencmiento' => '2030-01-01',
        ]);

        $this->bctLoan = Prestamo::factory()->create(['id_lineas_prestamos' => $this->bctLine->id, 'numero_prestamo' => '9090']);
        $this->secondBctLoan = Prestamo::factory()->create(['id_lineas_prestamos' => $this->bctLine->id, 'numero_prestamo' => '4040']);
        $this->bncrLoan = Prestamo::factory()->create([
            'id_lineas_prestamos' => $this->bncrLine->id, 'numero_prestamo' => '5555', 'monto_prestamo' => 1000000, 'plazo_meses' => 12,
            'tasa_interes' => 1.5, 'tasa_spread' => 1.5, 'cuota_calculada' => 0,
        ]);
        $this->bacLoan = Prestamo::factory()->create([
            'id_lineas_prestamos' => $this->bacLine->id, 'numero_prestamo' => 'C-1', 'monto_prestamo' => 9000000, 'plazo_meses' => 24,
            'tasa_interes' => 6, 'tasa_spread' => 2, 'cuota_calculada' => 0,
        ]);
    }

    protected function report(?string $currency = null): array
    {
        return app(DebtPositionReport::class)->build(['currency' => $currency]);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    protected function banks(?string $currency = null): array
    {
        return $this->report($currency)['banks']->keyBy(fn (array $bank): string => $bank['bank'].'|'.$bank['currency'])->all();
    }

    public function test_guests_cannot_reach_the_reports(): void
    {
        auth()->logout();

        $this->get(route('reportes.index'))->assertRedirect(route('login'));
        $this->get(route('reportes.show', 'posicion-deuda'))->assertRedirect(route('login'));
        $this->get(route('reportes.excel', 'posicion-deuda'))->assertRedirect(route('login'));
    }

    public function test_the_reports_page_lists_the_ready_report_and_the_announced_ones(): void
    {
        $this->get(route('reportes.index'))
            ->assertOk()
            ->assertSee('Posición consolidada de deuda')
            ->assertSee(route('reportes.show', 'posicion-deuda'), false)
            ->assertSee(route('reportes.excel', 'posicion-deuda'), false)
            ->assertSee('Flujo de efectivo proyectado (13 semanas)')
            ->assertSee('Ciclo de conversión de efectivo')
            ->assertSee('Pronto');
    }

    public function test_the_menu_has_a_reports_submenu_with_the_active_report_highlighted(): void
    {
        $content = $this->get(route('reportes.show', 'posicion-deuda'))->assertOk()->getContent();

        $this->assertStringContainsString('href="'.route('reportes.index').'"', $content);
        $this->assertMatchesRegularExpression('/class="nav-link nav-sub is-active">\s*<svg.*?<\/svg>\s*Posición consolidada de deuda/s', $content);
        $this->assertMatchesRegularExpression('/class="nav-link nav-sub is-disabled"[^>]*>\s*<svg.*?<\/svg>\s*Flujo de efectivo proyectado/s', $content);
    }

    public function test_the_catalog_puts_ready_reports_first_and_only_ready_ones_can_be_found(): void
    {
        $menu = ReportCatalog::menu();

        $this->assertSame('posicion-deuda', $menu[0]['slug']);
        $this->assertTrue($menu[0]['available']);
        $this->assertSame(['flujo-efectivo', 'ciclo-conversion-efectivo', 'estado-cuenta', 'alertas-vencimiento'], array_column(array_slice($menu, 1), 'slug'));
        $this->assertNotContains(true, array_column(array_slice($menu, 1), 'available'));

        $this->assertNotNull(ReportCatalog::find('posicion-deuda'));
        $this->assertNull(ReportCatalog::find('flujo-efectivo'));
        $this->assertNull(ReportCatalog::find('no-existe'));
    }

    public function test_unknown_and_announced_reports_return_not_found(): void
    {
        foreach (['no-existe', 'flujo-efectivo'] as $slug) {
            $this->get(route('reportes.show', $slug))->assertNotFound();
            $this->get(route('reportes.excel', $slug))->assertNotFound();
        }
    }

    public function test_the_position_is_grouped_by_bank_with_debt_available_and_utilization(): void
    {
        $banks = $this->banks();

        $bct = $banks['BCT|DOLARES'];
        $this->assertSame(12000000.0, $bct['balance']);
        $this->assertSame(65000000.0, $bct['limit']);
        $this->assertSame(53000000.0, $bct['available']);
        $this->assertSame(18.46, $bct['utilization']);
        $this->assertSame(1, $bct['lines_count']);
        $this->assertSame(2, $bct['loans_count']);
        $this->assertEqualsWithDelta(4.0, $bct['wavg_rate'], 0.0001);

        $this->assertSame(1000000.0, $banks['BNCR|DOLARES']['balance']);
        $this->assertEqualsWithDelta(3.0, $banks['BNCR|DOLARES']['wavg_rate'], 0.0001);
    }

    public function test_the_concentration_is_the_share_of_the_debt_of_each_currency(): void
    {
        $banks = $this->banks();

        $this->assertEqualsWithDelta(92.31, $banks['BCT|DOLARES']['concentration'], 0.01);
        $this->assertEqualsWithDelta(7.69, $banks['BNCR|DOLARES']['concentration'], 0.01);
        $this->assertSame(100.0, $banks['BAC|COLONES']['concentration']);
    }

    public function test_currencies_are_never_added_together(): void
    {
        $totals = $this->report()['totals']->keyBy('currency');

        $this->assertSame(['COLONES', 'DOLARES'], $totals->keys()->sort()->values()->all());
        $this->assertSame(13000000.0, $totals['DOLARES']['balance']);
        $this->assertSame(100000000.0, $totals['DOLARES']['limit']);
        $this->assertSame(87000000.0, $totals['DOLARES']['available']);
        $this->assertSame(9000000.0, $totals['COLONES']['balance']);
        $this->assertSame(2, $totals['DOLARES']['banks_count']);
        $this->assertEqualsWithDelta((12000000 * 4 + 1000000 * 3) / 13000000, $totals['DOLARES']['wavg_rate'], 0.0001);
    }

    public function test_the_currency_filter_limits_the_report_and_ignores_unknown_currencies(): void
    {
        $colones = $this->report('COLONES');

        $this->assertSame(['BAC'], $colones['banks']->pluck('bank')->all());
        $this->assertSame(['C-1'], $colones['loans']->pluck('number')->all());

        $this->assertSame('COLONES', app(DebtPositionReport::class)->filters(['moneda' => 'colones'])['currency']);
        $this->assertNull(app(DebtPositionReport::class)->filters(['moneda' => 'EUROS'])['currency']);
        $this->assertNull(app(DebtPositionReport::class)->filters([])['currency']);
    }

    public function test_the_debt_service_windows_add_principal_and_interest_of_the_open_installments(): void
    {
        $today = today();
        $loan = $this->report()['loans']->firstWhere('number', '9090');
        $open = app(LoanScheduleService::class)->scheduleFor($this->bctLoan, $today)->openInstallments();

        foreach ([3, 6, 12] as $months) {
            $cutoff = $today->copy()->addMonthsNoOverflow($months);
            $expected = $open->filter(fn ($row) => $row->date->lte($cutoff))->sum(fn ($row) => $row->total());
            $this->assertEqualsWithDelta($expected, $loan["service_{$months}"], 0.01);
        }

        // Las cuotas vencidas cuentan en la primera ventana: 8 vencidas más las que vencen hasta febrero.
        $this->assertGreaterThan($loan['service_3'], $loan['service_12']);
        $this->assertSame(8, $loan['overdue_count']);
        $this->assertEqualsWithDelta(round($loan['balance'] * 4 / 100, 2), $loan['annual_interest'], 0.01);
        $this->assertSame('2026-04-01', $loan['next_due']->toDateString());
    }

    public function test_inactive_lines_and_settled_loans_are_left_out(): void
    {
        LineaPrestamo::factory()->inactive()->create(['nombre' => 'LINEA CERRADA', 'moneda' => 'DOLARES']);
        Prestamo::factory()->settled()->create(['id_lineas_prestamos' => $this->bctLine->id, 'numero_prestamo' => '0001']);

        $report = $this->report();

        $this->assertNotContains('LINEA CERRADA', $report['lines']->pluck('line')->all());
        $this->assertNotContains('0001', $report['loans']->pluck('number')->all());
        $this->assertSame(12000000.0, $this->banks()['BCT|DOLARES']['balance']);
    }

    public function test_a_line_without_a_bank_is_reported_under_its_own_name(): void
    {
        LineaPrestamo::factory()->create(['nombre' => 'LINEA SIN BANCO', 'monto_maximo' => 1000, 'moneda' => 'DOLARES']);

        $this->assertArrayHasKey('LINEA SIN BANCO|DOLARES', $this->banks());
    }

    public function test_the_guidance_suggests_what_to_pay_first_and_which_credit_to_use_first(): void
    {
        $guidance = $this->report()['guidance'];

        $payFirst = array_column($guidance['pay_first'], 'number');
        $this->assertSame('C-1', $payFirst[0]);
        $this->assertSame(8.0, $guidance['pay_first'][0]['rate']);
        $this->assertEqualsCanonicalizing(['9090', '4040'], array_slice($payFirst, 1, 2));
        $this->assertSame('5555', $payFirst[3]);

        $useFirst = array_column($guidance['use_first'], 'line');
        $this->assertSame('BNCR DOLARES', $useFirst[0]);
        $this->assertSame(3.0, $guidance['use_first'][0]['line_rate']);
    }

    public function test_the_guidance_flags_overdue_installments_expiring_lines_high_utilization_and_concentration(): void
    {
        $attention = collect($this->report()['guidance']['attention']);
        $texts = $attention->pluck('text')->implode(' | ');

        $this->assertSame('danger', $attention->first()['severity']);
        $this->assertStringContainsString('8 cuota(s) vencida(s)', $texts);
        $this->assertStringContainsString('La línea vence el 01/03/2027', $texts);
        $this->assertStringContainsString('Utilización de 90.0 %', $texts);
        $this->assertStringContainsString('Concentra el 92.3 % de la deuda en DOLARES', $texts);
        $this->assertStringContainsString('Vence el 01/03/2027 con saldo de 1,000,000.00 DOLARES', $texts);

        $severities = $attention->pluck('severity')->all();
        $sorted = $severities;
        usort($sorted, fn (string $a, string $b): int => ['danger' => 0, 'warning' => 1, 'info' => 2][$a] <=> ['danger' => 0, 'warning' => 1, 'info' => 2][$b]);
        $this->assertSame($sorted, $severities);
    }

    public function test_an_expired_line_is_an_urgent_alert(): void
    {
        $this->bncrLine->update(['fecha_vencmiento' => '2026-10-01']);

        $texts = collect($this->report()['guidance']['attention'])->where('severity', 'danger')->pluck('text')->implode(' | ');

        $this->assertStringContainsString('La línea venció el 01/10/2026', $texts);
    }

    public function test_the_report_page_shows_totals_tabs_and_the_guidance(): void
    {
        $this->get(route('reportes.show', 'posicion-deuda'))
            ->assertOk()
            ->assertSee('Posición consolidada de deuda')
            ->assertSee('Total en DOLARES')
            ->assertSee('Total en COLONES')
            ->assertSee('13,000,000.00')
            ->assertSee('Resumen por banco (3)')
            ->assertSee('Detalle por línea (3)')
            ->assertSee('Sub préstamos (4)')
            ->assertSee('Guía de decisión')
            ->assertSee('Pagar primero (mayor costo)')
            ->assertSee('Usar primero (cupo más barato)')
            ->assertSee('18/11/2026')
            ->assertSee(route('reportes.excel', ['slug' => 'posicion-deuda']), false);
    }

    public function test_the_report_page_applies_the_currency_filter_and_keeps_it_in_the_excel_link(): void
    {
        $this->get(route('reportes.show', ['slug' => 'posicion-deuda', 'moneda' => 'COLONES']))
            ->assertOk()
            ->assertSee('Total en COLONES')
            ->assertDontSee('Total en DOLARES')
            ->assertSee('moneda=COLONES', false);
    }

    public function test_the_report_page_explains_when_there_are_no_lines(): void
    {
        LineaPrestamo::query()->update(['estado' => LineaPrestamo::STATUS_INACTIVE]);

        $this->get(route('reportes.show', 'posicion-deuda'))->assertOk()->assertSee('No hay líneas de crédito activas para esta moneda.');
    }

    /**
     * @return array{zip: ZipArchive, sheets: array<int, SimpleXMLElement>, names: array<int, string>}
     */
    protected function openDownload(string $url): array
    {
        $response = $this->get($url)->assertOk();
        $path = $response->baseResponse->getFile()->getPathname();

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path));

        $workbook = simplexml_load_string($zip->getFromName('xl/workbook.xml'));
        $names = [];
        $sheets = [];
        foreach ($workbook->sheets->sheet as $index => $sheet) {
            $names[] = (string) $sheet['name'];
            $xml = simplexml_load_string($zip->getFromName('xl/worksheets/sheet'.(count($names)).'.xml'));
            $xml->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
            $sheets[] = $xml;
        }

        return ['zip' => $zip, 'sheets' => $sheets, 'names' => $names];
    }

    public function test_the_excel_download_is_a_workbook_with_the_expected_name_and_content_type(): void
    {
        $response = $this->get(route('reportes.excel', 'posicion-deuda'))->assertOk();

        $this->assertSame('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('posicion-deuda-20261118.xlsx', $response->headers->get('Content-Disposition'));

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($response->baseResponse->getFile()->getPathname()));
        $this->assertNotFalse($zip->getFromName('xl/workbook.xml'));
    }

    public function test_the_excel_has_a_sheet_for_each_view_with_the_report_data(): void
    {
        ['sheets' => $sheets, 'names' => $names] = $this->openDownload(route('reportes.excel', 'posicion-deuda'));

        $this->assertSame(['Resumen por banco', 'Detalle por línea', 'Sub préstamos', 'Guía de decisión'], $names);

        $summary = $sheets[0];
        $this->assertSame('Posición consolidada de deuda', (string) $summary->xpath('//m:c[@r="A1"]/m:is/m:t')[0]);
        $this->assertSame('Fecha de corte: 18/11/2026', (string) $summary->xpath('//m:c[@r="A2"]/m:is/m:t')[0]);

        $cells = collect($summary->xpath('//m:c/m:is/m:t'))->map(fn ($node) => (string) $node);
        $this->assertTrue($cells->contains('BCT'));
        $this->assertTrue($cells->contains('BNCR'));
        $this->assertTrue($cells->contains('TOTAL DOLARES'));
        $this->assertTrue($cells->contains('TOTAL COLONES'));

        $balances = collect($summary->xpath('//m:c[starts-with(@r, "E")]/m:v'))->map(fn ($node) => (float) $node);
        $this->assertTrue($balances->contains(12000000.0));
        $this->assertTrue($balances->contains(13000000.0));

        $loans = collect($sheets[2]->xpath('//m:c/m:is/m:t'))->map(fn ($node) => (string) $node);
        foreach (['9090', '4040', '5555', 'C-1'] as $number) {
            $this->assertTrue($loans->contains($number), "Sub préstamo {$number} missing in the Excel.");
        }

        $guidance = collect($sheets[3]->xpath('//m:c/m:is/m:t'))->map(fn ($node) => (string) $node)->implode(' | ');
        $this->assertStringContainsString('Pagar primero (mayor costo)', $guidance);
        $this->assertStringContainsString('Usar primero (cupo más barato)', $guidance);
        $this->assertStringContainsString('Atención (Urgente)', $guidance);
    }

    public function test_the_excel_stores_dates_as_dates_and_percentages_as_fractions(): void
    {
        ['sheets' => $sheets] = $this->openDownload(route('reportes.excel', 'posicion-deuda'));

        $lines = $sheets[1];
        $bct = collect($lines->xpath('//m:row'))->first(fn ($row) => isset($row->c[1]->is->t) && (string) $row->c[1]->is->t === 'BCT DOLARES');

        $this->assertNotNull($bct);
        // La utilización 18.46 % se guarda como 0.1846 y el vencimiento 2032-03-07 como serial de Excel.
        $this->assertEqualsWithDelta(0.1846, (float) $bct->c[6]->v, 0.0001);
        $this->assertSame('48280', (string) $bct->c[17]->v);
    }

    public function test_the_excel_respects_the_currency_filter_and_names_the_file_after_it(): void
    {
        $response = $this->get(route('reportes.excel', ['slug' => 'posicion-deuda', 'moneda' => 'COLONES']))->assertOk();

        $this->assertStringContainsString('posicion-deuda-colones-20261118.xlsx', $response->headers->get('Content-Disposition'));

        $zip = new ZipArchive;
        $zip->open($response->baseResponse->getFile()->getPathname());
        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');

        $this->assertStringContainsString('BAC', $sheet);
        $this->assertStringNotContainsString('BNCR', $sheet);
        $this->assertStringContainsString('Moneda: COLONES', $sheet);
    }
}
