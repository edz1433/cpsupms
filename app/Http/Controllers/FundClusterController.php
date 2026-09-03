<?php

namespace App\Http\Controllers;

use App\Models\FundCluster;

class FundClusterController extends Controller
{
    public function index()
    {
        return view('fund-clusters.index', [
            'fundClusters' => FundCluster::query()
                ->with('campus')
                ->orderBy('sort_order')
                ->orderBy('name')
                ->paginate(25),
        ]);
    }
}
