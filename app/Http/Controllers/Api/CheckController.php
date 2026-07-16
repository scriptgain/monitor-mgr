<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Check;
use Illuminate\Http\Request;

class CheckController extends Controller
{
    public function index(Request $request)
    {
        return Check::visibleTo($request->user())
            ->with('monitor:id,name')
            ->when($request->integer('monitor_id'), fn ($q, $id) => $q->where('monitor_id', $id))
            ->latest('checked_at')
            ->paginate(50);
    }

    public function show(Check $check)
    {
        abort_unless($check->isVisibleTo(auth()->user()), 403);

        return $check->load('monitor:id,name');
    }
}
