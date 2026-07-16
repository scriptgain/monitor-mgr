<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\StatusPage;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class StatusPageController extends Controller
{
    public function index(Request $request)
    {
        return StatusPage::visibleTo($request->user())
            ->withCount('monitors')
            ->latest()
            ->paginate(50);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $data['user_id'] = $this->resolveOwner($request);
        $monitorIds = $data['monitor_ids'] ?? [];
        unset($data['monitor_ids']);
        $data['slug'] = $data['slug'] ?: Str::slug($data['name']);

        $statusPage = StatusPage::create($data);
        $statusPage->monitors()->sync($monitorIds);

        return response()->json($statusPage, 201);
    }

    public function show(StatusPage $statusPage)
    {
        abort_unless($statusPage->isVisibleTo(auth()->user()), 403);

        return $statusPage->load('monitors:id,name');
    }

    public function update(Request $request, StatusPage $statusPage)
    {
        abort_unless($statusPage->isVisibleTo($request->user()), 403);

        $data = $this->validated($request, $statusPage->id, updating: true);

        if ($request->user()->isAdmin() && $request->filled('user_id')) {
            $data['user_id'] = $request->validate([
                'user_id' => ['integer', 'exists:users,id'],
            ])['user_id'];
        } else {
            unset($data['user_id']);
        }

        $hasMonitorIds = $request->has('monitor_ids');
        $monitorIds = $data['monitor_ids'] ?? [];
        unset($data['monitor_ids']);

        if (isset($data['slug'])) {
            $data['slug'] = $data['slug'] ?: Str::slug($data['name'] ?? $statusPage->name);
        }

        $statusPage->update($data);

        if ($hasMonitorIds) {
            $statusPage->monitors()->sync($monitorIds);
        }

        return $statusPage;
    }

    public function destroy(StatusPage $statusPage)
    {
        abort_unless($statusPage->isVisibleTo(auth()->user()), 403);

        $statusPage->delete();

        return response()->noContent();
    }

    /** Admins may assign an explicit owner; everyone else owns what they create. */
    private function resolveOwner(Request $request): int
    {
        if ($request->user()->isAdmin() && $request->filled('user_id')) {
            return (int) $request->validate([
                'user_id' => ['integer', 'exists:users,id'],
            ])['user_id'];
        }

        return $request->user()->id;
    }

    private function validated(Request $request, ?int $ignoreId = null, bool $updating = false): array
    {
        $req = $updating ? 'sometimes' : 'required';

        $data = $request->validate([
            'name' => [$req, 'string', 'max:160'],
            'slug' => ['nullable', 'string', 'max:160', 'alpha_dash', 'unique:status_pages,slug' . ($ignoreId ? ",{$ignoreId}" : '')],
            'is_public' => ['nullable', 'boolean'],
            'description' => ['nullable', 'string', 'max:2000'],
            'monitor_ids' => ['nullable', 'array'],
            'monitor_ids.*' => ['integer', 'exists:monitors,id'],
        ]);

        if ($request->has('is_public')) {
            $data['is_public'] = $request->boolean('is_public');
        }

        return $data;
    }
}
