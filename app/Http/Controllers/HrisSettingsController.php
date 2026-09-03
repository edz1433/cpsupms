<?php

namespace App\Http\Controllers;

use App\Models\HrisSyncLog;
use App\Services\HrisDatabaseService;
use Illuminate\Http\Request;

class HrisSettingsController extends Controller
{
    public function index(Request $request)
    {
        abort_unless($request->user()->canManageHris(), 403);

        return view('settings.hris', [
            'host' => config('database.connections.hris.host'),
            'port' => config('database.connections.hris.port'),
            'database' => config('database.connections.hris.database'),
            'hasCredentials' => filled(config('database.connections.hris.username')),
            'logs' => HrisSyncLog::query()->latest()->limit(15)->get(),
        ]);
    }

    public function check(Request $request, HrisDatabaseService $hris)
    {
        abort_unless($request->user()->canManageHris(), 403);

        $result = $hris->checkConnection($request->user());

        return back()->with('hris_status', $result['status']);
    }
}
