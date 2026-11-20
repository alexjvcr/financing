<?php

namespace App\Http\Controllers;

use App\Reports\Report;
use App\Reports\ReportCatalog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ReportController extends Controller
{
    private const XLSX_MIME = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    /**
     * Índice de todos los reportes, disponibles o anunciados.
     */
    public function index(): View
    {
        return view('reportes.index', ['reports' => ReportCatalog::menu()]);
    }

    public function show(Request $request, string $slug): View
    {
        $report = $this->report($slug);
        $filters = $report->filters($request->query());

        return view($report->view(), [
            'report' => $report,
            'filters' => $filters,
            'data' => $report->build($filters),
        ]);
    }

    public function download(Request $request, string $slug): BinaryFileResponse
    {
        $report = $this->report($slug);
        $data = $report->build($report->filters($request->query()));

        // Dentro de storage para que el grupo de aplicaciones de IIS siempre pueda escribirlo.
        $directory = storage_path('app/tmp');
        File::ensureDirectoryExists($directory);
        $path = tempnam($directory, 'rpt');
        rename($path, $path .= '.xlsx');

        $report->workbook($data)->save($path);

        return response()
            ->download($path, $report->fileName($data), ['Content-Type' => self::XLSX_MIME])
            ->deleteFileAfterSend();
    }

    private function report(string $slug): Report
    {
        return ReportCatalog::find($slug) ?? abort(404);
    }
}
