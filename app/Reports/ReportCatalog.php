<?php

namespace App\Reports;

/**
 * Registro de los reportes: los que están listos y los anunciados como "Pronto".
 */
final class ReportCatalog
{
    /**
     * Reportes que están listos.
     *
     * @var array<int, class-string<Report>>
     */
    private const AVAILABLE = [
        DebtPositionReport::class,
    ];

    /**
     * Reportes anunciados en el menú que aún no se han construido.
     *
     * @var array<int, array{slug: string, title: string, description: string, icon: string}>
     */
    private const PLANNED = [
        ['slug' => 'flujo-efectivo', 'title' => 'Flujo de efectivo proyectado (13 semanas)', 'description' => 'Servicio de la deuda semana a semana, disponible de las líneas y necesidad de liquidez.', 'icon' => 'trending-up'],
        ['slug' => 'ciclo-conversion-efectivo', 'title' => 'Ciclo de conversión de efectivo', 'description' => 'Días de inventario, cobro y pago para entender la presión sobre el capital de trabajo.', 'icon' => 'clock'],
        ['slug' => 'estado-cuenta', 'title' => 'Estado de cuenta', 'description' => 'Movimientos, saldo y plan de pagos de un sub préstamo.', 'icon' => 'file-text'],
        ['slug' => 'alertas-vencimiento', 'title' => 'Alertas de vencimiento', 'description' => 'Cuotas por vencer y líneas que vencen.', 'icon' => 'alert'],
    ];

    /**
     * @return array<string, Report>
     */
    public static function available(): array
    {
        $reports = [];

        foreach (self::AVAILABLE as $class) {
            $report = app($class);
            $reports[$report->slug()] = $report;
        }

        return $reports;
    }

    public static function find(string $slug): ?Report
    {
        return self::available()[$slug] ?? null;
    }

    /**
     * Todos los reportes para el menú y la página de reportes, primero los que están listos.
     *
     * @return array<int, array{slug: string, title: string, description: string, icon: string, available: bool}>
     */
    public static function menu(): array
    {
        $items = [];

        foreach (self::available() as $report) {
            $items[] = [
                'slug' => $report->slug(),
                'title' => $report->title(),
                'description' => $report->description(),
                'icon' => $report->icon(),
                'available' => true,
            ];
        }

        foreach (self::PLANNED as $planned) {
            $items[] = $planned + ['available' => false];
        }

        return $items;
    }
}
