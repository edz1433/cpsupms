<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\FundClusterController;
use App\Http\Controllers\HrisSettingsController;
use App\Http\Controllers\PayrollBatchController;
use App\Http\Controllers\PayrollPeriodController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "web" middleware group. Make something great!
|
*/

Route::middleware('guest')->group(function () {
    Route::get('/', [LoginController::class, 'create'])->name('login');
    Route::get('/login', [LoginController::class, 'create']);
    Route::post('/login', [LoginController::class, 'store'])->name('login.store');
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');
    Route::redirect('/home', '/dashboard');
    Route::get('/dashboard', DashboardController::class)->name('dashboard');

    Route::get('/employees', [EmployeeController::class, 'index'])->name('employees.index');
    Route::put('/employees/{employee}', [EmployeeController::class, 'update'])->name('employees.update');
    Route::post('/employees/sync-hris', [EmployeeController::class, 'syncFromHris'])->name('employees.sync-hris');
    Route::get('/fund-clusters', [FundClusterController::class, 'index'])->name('fund-clusters.index');
    Route::get('/periods', [PayrollPeriodController::class, 'index'])->name('periods.index');

    Route::get('/payroll', [PayrollBatchController::class, 'index'])->name('payroll.index');
    Route::get('/payroll/create', [PayrollBatchController::class, 'create'])->name('payroll.create');
    Route::post('/payroll', [PayrollBatchController::class, 'store'])->name('payroll.store');
    Route::get('/payroll/{payroll}', [PayrollBatchController::class, 'show'])->name('payroll.show');
    Route::delete('/payroll/{payroll}', [PayrollBatchController::class, 'destroy'])->name('payroll.destroy');
    Route::post('/payroll/{payroll}/submit', [PayrollBatchController::class, 'submit'])->name('payroll.submit');
    Route::post('/payroll/{payroll}/refresh-attendance', [PayrollBatchController::class, 'refreshAttendance'])->name('payroll.refresh-attendance');
    Route::post('/payroll/{payroll}/return', [PayrollBatchController::class, 'return'])->name('payroll.return');
    Route::post('/payroll/{payroll}/approve', [PayrollBatchController::class, 'approve'])->name('payroll.approve');
    Route::post('/payroll/{payroll}/attendance-review/resolve', [PayrollBatchController::class, 'bulkResolveAttendanceReviews'])->name('payroll.attendance-review.bulk-resolve');
    Route::post('/payroll/{payroll}/lines/{line}/resolve-attendance', [PayrollBatchController::class, 'resolveAttendanceReview'])->name('payroll.lines.resolve-attendance');
    Route::post('/payroll/{payroll}/print', [PayrollBatchController::class, 'print'])->name('payroll.print');
    Route::put('/payroll/{payroll}/signatories', [PayrollBatchController::class, 'updateSignatories'])->name('payroll.signatories.update');
    Route::get('/payroll/{payroll}/printable', [PayrollBatchController::class, 'printable'])->name('payroll.printable');
    Route::get('/payroll/{payroll}/export.xlsx', [PayrollBatchController::class, 'export'])->name('payroll.export');

    Route::get('/settings/hris', [HrisSettingsController::class, 'index'])->name('settings.hris');
    Route::post('/settings/hris/check', [HrisSettingsController::class, 'check'])->name('settings.hris.check');
});
