<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Metric;
use Illuminate\Http\Request;

class MetricController extends Controller
{
    public function index(Request $request)
    {
        return Metric::visibleTo($request->user())
            ->with('monitor:id,name')
            ->when($request->integer('monitor_id'), fn ($q, $id) => $q->where('monitor_id', $id))
            ->latest('recorded_at')
            ->paginate(50);
    }

    public function show(Metric $metric)
    {
        abort_unless($metric->isVisibleTo(auth()->user()), 403);

        return $metric->load('monitor:id,name');
    }
}
