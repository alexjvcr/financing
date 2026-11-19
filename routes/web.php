<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\BancoController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EmpresaController;
use App\Http\Controllers\LineaPrestamoController;
use App\Http\Controllers\PrestamoController;
use App\Http\Controllers\ReciboController;
use App\Http\Controllers\RevisionController;
use App\Http\Controllers\TipoPagoController;
use App\Http\Controllers\TipoTasaController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])->middleware('throttle:5,1');
});

Route::middleware('auth')->group(function () {
    Route::get('/', DashboardController::class)->name('dashboard');
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');

    Route::resource('empresas', EmpresaController::class)->except('show')->parameters(['empresas' => 'record']);
    Route::resource('bancos', BancoController::class)->except('show')->parameters(['bancos' => 'record']);
    Route::resource('tipos-tasa', TipoTasaController::class)->except('show')->parameters(['tipos-tasa' => 'record']);
    Route::resource('tipos-pago', TipoPagoController::class)->except('show')->parameters(['tipos-pago' => 'record']);
    Route::resource('lineas', LineaPrestamoController::class)->except('show')->parameters(['lineas' => 'record']);

    Route::resource('prestamos', PrestamoController::class);
    Route::get('prestamos/{prestamo}/cotizacion', [PrestamoController::class, 'quote'])->name('prestamos.cotizacion');
    Route::post('prestamos/{prestamo}/recibos', [ReciboController::class, 'store'])->name('prestamos.recibos.store');
    Route::get('recibos', [ReciboController::class, 'index'])->name('recibos.index');
    Route::post('recibos/{recibo}/anular', [ReciboController::class, 'void'])->name('recibos.anular');
    Route::post('prestamos/{prestamo}/revisiones', [RevisionController::class, 'store'])->name('prestamos.revisiones.store');
    Route::post('revisiones/{revision}/anular', [RevisionController::class, 'void'])->name('revisiones.anular');
});
