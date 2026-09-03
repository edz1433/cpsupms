<?php

namespace App\Http\Controllers;

use App\Services\PayrollPeriodWindowService;

class PayrollPeriodController extends Controller
{
    public function index(PayrollPeriodWindowService $periodWindows)
    {
        return view('periods.index', [
            'periods' => $periodWindows->query()->paginate(20),
        ]);
    }
}
