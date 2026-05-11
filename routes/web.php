<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ProductionController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\OvertimeController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\MonitoringTransferRak\TransferRakController;
use App\Http\Controllers\pilihmenu\PilihMenuController;
use App\Http\Controllers\PenerimaanController;
use App\Http\Controllers\MonitoringTransferRak\KaryawanTransfer;
use App\Http\Controllers\MonitoringTransferRak\LaporanTransferRakController;


Route::prefix('transfer-rak/karyawan')->group(function () {
    Route::get('/', [KaryawanTransfer::class, 'index'])->name('karyawan.index');
    Route::post('/store', [KaryawanTransfer::class, 'store'])->name('karyawan.store');
    Route::post('/update/{id}', [KaryawanTransfer::class, 'update'])->name('karyawan.update');
    Route::delete('/delete/{id}', [KaryawanTransfer::class, 'destroy'])->name('karyawan.delete');
});
// Authentication routes
Route::get('/login', [AuthController::class, 'showLoginForm'])->name('login');
Route::post('/login', [AuthController::class, 'login']);
Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

// Protected routes (all authenticated users: admin + leader)
Route::middleware(['auth'])->group(function () {
    Route::get('/', function () {
        return redirect('/dashboard');
    });
    Route::get('/api/trend-data', [ProductionController::class, 'trendData'])->name('api.trend-data');
    Route::get('/api/trend-7days', [ProductionController::class, 'trendData7Days'])->name('api.trend-7days');
    Route::get('/api/plant-group', [ProductionController::class, 'plantGroupData'])->name('api.plant-group');

    // Production input routes
    Route::get('/input/{plant?}', [ProductionController::class, 'inputForm'])->name('input.form');
    Route::post('/input/{plant}', [ProductionController::class, 'storeInput'])->name('input.store');
    Route::get('/production/{plant}/edit/{id}', [ProductionController::class, 'editInput'])->name('input.edit');
    Route::put('/production/{plant}/update/{id}', [ProductionController::class, 'updateInput'])->name('input.update');
    Route::delete('/production/{plant}/delete/{id}', [ProductionController::class, 'deleteInput'])->name('input.delete');

    // Overtime management routes
    Route::get('/overtime', [OvertimeController::class, 'index'])->name('overtime.index');
    Route::get('/overtime/create', [OvertimeController::class, 'create'])->name('overtime.create');
    Route::post('/overtime', [OvertimeController::class, 'store'])->name('overtime.store');
    Route::put('/overtime/{overtime}', [OvertimeController::class, 'update'])->name('overtime.update');
    Route::delete('/overtime/{overtime}', [OvertimeController::class, 'destroy'])
        ->name('overtime.delete');
    // === ADMIN ONLY (leader tidak bisa akses) ===
    Route::middleware(['admin.only'])->group(function () {
        // Employee management routes
        Route::get('/employees', [EmployeeController::class, 'index'])->name('employees.index');
        Route::get('/employees/create', [EmployeeController::class, 'create'])->name('employees.create');
        Route::post('/employees', [EmployeeController::class, 'store'])->name('employees.store');
        Route::get('/employees/{employee}/edit', [EmployeeController::class, 'edit'])->name('employees.edit');
        Route::put('/employees/{employee}', [EmployeeController::class, 'update'])->name('employees.update');
        Route::delete('/employees/{employee}', [EmployeeController::class, 'destroy'])->name('employees.destroy');
        Route::patch('/employees/{employee}/toggle-status', [EmployeeController::class, 'toggleStatus'])->name('employees.toggle-status');

        // Report routes
        Route::get('/reports', [ReportController::class, 'index'])->name('reports.index');
        Route::get('/reports/export/excel', [ReportController::class, 'exportExcel'])->name('reports.export.excel');

        // Filter and export routes
        Route::get('/filter/shift/{shift}', [ProductionController::class, 'filterByShift'])->name('filter.shift');
        Route::get('/export/pdf', [ProductionController::class, 'exportPDF'])->name('export.pdf');
        Route::get('/export/excel', [ProductionController::class, 'exportExcel'])->name('export.excel');
        Route::get('/export/harian', [ProductionController::class, 'exportPDF'])->name('export.harian');

        // User management routes
        Route::get('/users', [UserController::class, 'index'])->name('users.index');
        Route::post('/users', [UserController::class, 'store'])->name('users.store');
        Route::put('/users/{user}', [UserController::class, 'update'])->name('users.update');
        Route::delete('/users/{user}', [UserController::class, 'destroy'])->name('users.destroy');
    });

    //MONITORING TRANSFER RAK
    Route::get('/dashboard', [PilihMenuController::class, 'index'])
        ->name('dashboard')
        ->middleware('auth');
    Route::post('/transfer-rak/start', [TransferRakController::class, 'start'])
        ->name('transfer.start');
    Route::post('/transfer-rak/scan', [TransferRakController::class, 'scan']);
    Route::post('/transfer-rak/finish', [TransferRakController::class, 'finish']);
    Route::get('/transfer-rak/monitoring', function () {
        return view('MonitoringTransferRak.monitoring');
    })->name('transfer.monitoring')->middleware('auth');

    //PILIH MENU SETELAH LOGIN
    Route::get('/transfer-rak', [TransferRakController::class, 'index'])
        ->name('transfer.index')
        ->middleware('auth');
    Route::get('/transfer-rak', [TransferRakController::class, 'index'])
        ->name('transfer.index');
    Route::get('/transfer-rak/drivers', [TransferRakController::class, 'getDrivers'])
        ->name('transfer.drivers');
    Route::post('/transfer-rak/start', [TransferRakController::class, 'start'])
        ->name('transfer.start');
    Route::post('/transfer-rak/scan', [TransferRakController::class, 'scan'])
        ->name('transfer.scan');
    Route::post('/transfer-rak/finish', [TransferRakController::class, 'finish'])
        ->name('transfer.finish');
    Route::post('/transfer-rak/scan-mobil-penerima', [TransferRakController::class, 'scanMobilPenerima'])
        ->name('transfer.scanMobilPenerima');
    Route::post('/transfer-rak/scan-terima', [TransferRakController::class, 'scanTerima'])
        ->name('transfer.scanTerima');
    Route::post('/transfer-rak/terima', [TransferRakController::class, 'terima'])
        ->name('transfer.terima');
    Route::post('/transfer-rak/cancel', [TransferRakController::class, 'cancel'])
        ->name('transfer.cancel');
    Route::post('/transfer-rak/start-kosong', [TransferRakController::class, 'startKosong'])
        ->name('transfer.startKosong');
    Route::get('/transfer-rak/dashboard', [TransferRakController::class, 'dashboard'])
        ->name('transfer.dashboard');
    Route::get('/transfer-rak/dashboard/data', [TransferRakController::class, 'dashboardData'])
        ->name('transfer.dashboard.data');

    // Laporan Transfer Rak
    Route::get('/transfer-rak/laporan', [LaporanTransferRakController::class, 'index'])
        ->name('transfer.laporan');
    Route::get('/transfer-rak/laporan/data', [LaporanTransferRakController::class, 'getData'])
        ->name('transfer.laporan.data');
    Route::get('/transfer-rak/laporan/detail/{id}', [LaporanTransferRakController::class, 'getDetail'])
        ->name('transfer.laporan.detail');
    Route::prefix('transfer-rak/karyawan')->group(function () {
        Route::get('/', [KaryawanTransfer::class, 'index'])->name('karyawan.index');
        Route::post('/store', [KaryawanTransfer::class, 'store'])->name('karyawan.store');
        Route::match(['post', 'put'], '/update/{id}', [KaryawanTransfer::class, 'update'])->name('karyawan.update');
        Route::match(['post', 'delete'], '/delete/{id}', [KaryawanTransfer::class, 'destroy'])->name('karyawan.delete');

        // Route Baru buat Supir
        Route::post('/supir/store', [KaryawanTransfer::class, 'storeSupir'])->name('supir.store');
        Route::match(['post', 'put'], '/supir/update/{id}', [KaryawanTransfer::class, 'updateSupir'])->name('supir.update');
        Route::match(['post', 'delete'], '/supir/delete/{id}', [KaryawanTransfer::class, 'destroySupir'])->name('supir.delete');
    });

    // PILIH MENU SETELAH LOGIN
    Route::get('/menu', [PilihMenuController::class, 'index'])
        ->name('pilihmenu.index');

    // DASHBOARD PENERIMAAN PRODUKSI (dashboard_auth)
    Route::get('/dashboard', [ProductionController::class, 'dashboard'])
        ->name('dashboard');

    Route::get('/penerimaan-produksi', [ProductionController::class, 'inputForm'])
        ->name('penerimaan.index');

    Route::post('/pilih-menu/set-session', [PilihMenuController::class, 'pilih'])
        ->name('pilihmenu.set');
});
