<?php

namespace Tests\Feature;

use App\Models\LineaPrestamo;
use App\Models\Prestamo;
use App\Models\Recibo;
use App\Models\RevisionPrestamo;
use App\Models\User;
use App\Support\DateFormat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class DisplayDateFormatTest extends TestCase
{
    use RefreshDatabase;

    protected Prestamo $loan;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-18');
        $this->actingAs(User::factory()->create());

        $line = LineaPrestamo::factory()->create(['monto_maximo' => 65000000]);
        $this->loan = Prestamo::factory()->create([
            'id_lineas_prestamos' => $line->id,
            'cuota_calculada' => 73290.81,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function receiptPayload(string $date): array
    {
        return [
            '_dates' => ['fecha_pago'],
            'tipo' => 'CUOTA_COMPLETA',
            'fecha_pago' => $date,
            'numero_cuota' => 1,
            'monto_principal' => 53290.81,
            'monto_interes' => 20000,
        ];
    }

    public function test_date_fields_are_shown_as_dd_mm_yyyy_with_a_text_mask(): void
    {
        $this->get(route('prestamos.edit', $this->loan))
            ->assertOk()
            ->assertSee('value="01/03/2026"', false)
            ->assertSee('placeholder="dd/mm/aaaa"', false)
            ->assertSee('name="_dates[]" value="formalizacion"', false)
            ->assertDontSee('value="2026-03-01"', false);
    }

    public function test_credit_line_form_uses_the_same_date_field(): void
    {
        $this->get(route('lineas.create'))
            ->assertOk()
            ->assertSee('data-date-input', false)
            ->assertSee('name="_dates[]" value="fecha_vencmiento"', false);
    }

    public function test_detail_forms_default_to_today_in_dd_mm_yyyy(): void
    {
        $this->get(route('prestamos.show', $this->loan))
            ->assertOk()
            ->assertSee('id="fecha_pago"', false)
            ->assertSee('value="18/11/2026"', false)
            ->assertSee('name="_dates[]" value="fecha_efectiva"', false);
    }

    public function test_dd_mm_yyyy_input_is_stored_as_an_iso_date(): void
    {
        $this->post(route('prestamos.recibos.store', $this->loan), $this->receiptPayload('01/04/2026'))->assertSessionHasNoErrors();

        $this->assertSame('2026-04-01', Recibo::firstOrFail()->fecha_pago->toDateString());
    }

    public function test_days_and_months_without_a_leading_zero_are_accepted(): void
    {
        $this->post(route('prestamos.recibos.store', $this->loan), $this->receiptPayload('1/4/2026'))->assertSessionHasNoErrors();

        $this->assertSame('2026-04-01', Recibo::firstOrFail()->fecha_pago->toDateString());
    }

    public function test_impossible_dates_and_mm_dd_yyyy_are_rejected_instead_of_misread(): void
    {
        foreach (['31/02/2026', '12/13/2026', '00/04/2026'] as $date) {
            $this->post(route('prestamos.recibos.store', $this->loan), $this->receiptPayload($date))->assertSessionHasErrors('fecha_pago');
        }

        $this->assertSame(0, Recibo::count());
    }

    public function test_revisions_accept_dd_mm_yyyy(): void
    {
        $this->post(route('prestamos.revisiones.store', $this->loan), [
            '_dates' => ['fecha_efectiva'], 'fecha_efectiva' => '01/07/2026', 'tasa_interes' => 1,
        ])->assertSessionHasNoErrors();

        $this->assertSame('2026-07-01', RevisionPrestamo::firstOrFail()->fecha_efectiva->toDateString());
    }

    public function test_new_loans_accept_dd_mm_yyyy_for_the_formalization(): void
    {
        $this->post(route('prestamos.store'), [
            '_dates' => ['formalizacion'],
            'numero_prestamo' => '7070',
            'id_lineas_prestamos' => $this->loan->id_lineas_prestamos,
            'monto_prestamo' => 1000000,
            'formalizacion' => '15/05/2026',
            'plazo_meses' => 12,
            'tasa_interes' => 2,
            'tasa_spread' => 2,
        ])->assertSessionHasNoErrors();

        $loan = Prestamo::where('numero_prestamo', '7070')->firstOrFail();
        $this->assertSame('2026-05-15', $loan->formalizacion->toDateString());
        $this->assertSame('2026-06-15', $loan->proximo_pago->toDateString());
    }

    public function test_a_failed_form_shows_the_date_again_as_dd_mm_yyyy(): void
    {
        $this->from(route('prestamos.create'))->post(route('prestamos.store'), [
            '_dates' => ['formalizacion'],
            'numero_prestamo' => '',
            'id_lineas_prestamos' => $this->loan->id_lineas_prestamos,
            'monto_prestamo' => 1000,
            'formalizacion' => '15/05/2026',
            'plazo_meses' => 12,
            'tasa_interes' => 2,
            'tasa_spread' => 2,
        ])->assertSessionHasErrors('numero_prestamo');

        $this->followingRedirects()->get(route('prestamos.create'))->assertSee('value="15/05/2026"', false);
    }

    public function test_iso_dates_without_the_marker_keep_working(): void
    {
        $payload = $this->receiptPayload('2026-04-01');
        unset($payload['_dates']);

        $this->post(route('prestamos.recibos.store', $this->loan), $payload)->assertSessionHasNoErrors();

        $this->assertSame('2026-04-01', Recibo::firstOrFail()->fecha_pago->toDateString());
    }

    public function test_conversion_helpers(): void
    {
        $this->assertSame('2026-04-01', DateFormat::toIso('1/4/2026'));
        $this->assertSame('2024-02-29', DateFormat::toIso('29/02/2024'));
        $this->assertSame(DateFormat::INVALID, DateFormat::toIso('29/02/2025'));

        $this->assertSame('01/04/2026', DateFormat::toDisplay('2026-04-01'));
        $this->assertSame('01/04/2026', DateFormat::toDisplay('2026-04-01 00:00:00'));
        $this->assertSame('01/04/2026', DateFormat::toDisplay(Carbon::parse('2026-04-01')));
        $this->assertSame('01/04/2026', DateFormat::toDisplay('01/04/2026'));
        $this->assertSame('', DateFormat::toDisplay(null));
        $this->assertSame('', DateFormat::toDisplay(DateFormat::INVALID));
        $this->assertSame('', DateFormat::format(null));
        $this->assertSame('18/11/2026 14:30', DateFormat::formatDateTime(Carbon::parse('2026-11-18 14:30')));
        $this->assertSame('dd/mm/aaaa', DateFormat::placeholder());
    }

    public function test_the_default_format_is_dd_mm_yyyy(): void
    {
        $this->assertSame('d/m/Y', config('formats.date'));
    }

    public function test_month_first_format_is_used_everywhere_when_configured(): void
    {
        config(['formats.date' => 'm/d/Y']);

        $this->assertSame('mm/dd/aaaa', DateFormat::placeholder());
        $this->assertSame('2026-04-01', DateFormat::toIso('04/01/2026'));
        $this->assertSame('2026-01-13', DateFormat::toIso('01/13/2026'));
        $this->assertSame(DateFormat::INVALID, DateFormat::toIso('13/01/2026'));
        $this->assertSame('03/01/2026', DateFormat::toDisplay('2026-03-01'));

        $this->get(route('prestamos.edit', $this->loan))
            ->assertOk()
            ->assertSee('value="03/01/2026"', false)
            ->assertSee('placeholder="mm/dd/aaaa"', false);

        $this->get(route('prestamos.index'))->assertOk()->assertSee('03/01/2034');

        $this->post(route('prestamos.recibos.store', $this->loan), $this->receiptPayload('04/01/2026'))->assertSessionHasNoErrors();
        $this->assertSame('2026-04-01', Recibo::firstOrFail()->fecha_pago->toDateString());
    }

    public function test_year_first_formats_work_with_dashes(): void
    {
        config(['formats.date' => 'Y-m-d']);

        $this->assertSame('aaaa-mm-dd', DateFormat::placeholder());
        $this->assertSame('2026-04-01', DateFormat::toIso('2026-4-1'));
        $this->assertSame('2026-03-01', DateFormat::toDisplay(Carbon::parse('2026-03-01')));

        config(['formats.date' => 'd-m-Y']);

        $this->assertSame('dd-mm-aaaa', DateFormat::placeholder());
        $this->assertSame('2026-04-01', DateFormat::toIso('01-04-2026'));
        $this->assertSame('01-03-2026', DateFormat::toDisplay('2026-03-01'));

        $this->post(route('prestamos.recibos.store', $this->loan), $this->receiptPayload('01-04-2026'))->assertSessionHasNoErrors();
        $this->assertSame('2026-04-01', Recibo::firstOrFail()->fecha_pago->toDateString());
    }

    public function test_an_unsupported_setting_falls_back_to_dd_mm_yyyy(): void
    {
        config(['formats.date' => 'F j, Y']);

        $this->assertSame('d/m/Y', DateFormat::pattern());
        $this->assertSame('01/04/2026', DateFormat::toDisplay('2026-04-01'));
    }

    public function test_the_browser_receives_the_configured_format(): void
    {
        config(['formats.date' => 'm/d/Y']);

        $this->get(route('prestamos.show', $this->loan))
            ->assertOk()
            ->assertSee('"order":["m","d","Y"]', false)
            ->assertSee('"separator":"\/"', false)
            ->assertSee('pattern="\d{1,2}/\d{1,2}/\d{4}"', false);
    }

    public function test_validation_messages_use_the_configured_format(): void
    {
        config(['formats.date' => 'm/d/Y']);

        $this->post(route('prestamos.recibos.store', $this->loan), $this->receiptPayload('04/01/2026'));
        $this->post(route('prestamos.recibos.store', $this->loan), [
            '_dates' => ['fecha_pago'], 'tipo' => 'CUOTA_COMPLETA', 'fecha_pago' => '02/20/2026', 'numero_cuota' => 2,
            'monto_principal' => 1, 'monto_interes' => 1,
        ])->assertSessionHasErrors(['fecha_pago' => 'La fecha del recibo debe ser posterior a la formalización del sub préstamo (03/01/2026).']);
    }
}
