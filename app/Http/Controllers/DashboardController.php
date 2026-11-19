<?php

namespace App\Http\Controllers;

use App\Services\DashboardMetrics;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(DashboardMetrics $metrics): View
    {
        return view('dashboard', [
            'summary' => $metrics->summary(),
            'availability' => $metrics->creditAvailability(),
            'nextInstallment' => $metrics->nextInstallment(),
            'installments' => $metrics->pendingInstallments(),
        ]);
    }
}
