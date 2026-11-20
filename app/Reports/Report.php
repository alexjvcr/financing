<?php

namespace App\Reports;

use App\Support\XlsxWriter;
use Illuminate\Support\Carbon;

/**
 * Un reporte del menú Reportes: construye sus datos a partir de un conjunto de filtros
 * y sabe presentarlos como un libro de Excel.
 *
 * Para agregar un reporte: extienda esta clase, regístrelo en ReportCatalog y agregue una
 * vista llamada "reportes.{slug}".
 */
abstract class Report
{
    /**
     * Identificador usado en la URL, por ejemplo "posicion-deuda".
     */
    abstract public function slug(): string;

    abstract public function title(): string;

    abstract public function description(): string;

    public function icon(): string
    {
        return 'file-text';
    }

    /**
     * Filtros que acepta el reporte, leídos de la cadena de consulta.
     *
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public function filters(array $query): array
    {
        return [];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    abstract public function build(array $filters, ?Carbon $today = null): array;

    /**
     * Libro de Excel con los datos que devuelve build().
     *
     * @param  array<string, mixed>  $data
     */
    abstract public function workbook(array $data): XlsxWriter;

    /**
     * Nombre del archivo descargado.
     *
     * @param  array<string, mixed>  $data
     */
    public function fileName(array $data): string
    {
        return $this->slug().'-'.now()->format('Ymd').'.xlsx';
    }

    public function view(): string
    {
        return 'reportes.'.$this->slug();
    }
}
