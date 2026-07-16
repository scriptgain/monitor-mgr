<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AlertContact;
use Illuminate\Http\Request;

class AlertContactController extends Controller
{
    public function index(Request $request)
    {
        return AlertContact::visibleTo($request->user())
            ->latest()
            ->paginate(50);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $data['user_id'] = $this->resolveOwner($request);

        return response()->json(AlertContact::create($data), 201);
    }

    public function show(AlertContact $alertContact)
    {
        abort_unless($alertContact->isVisibleTo(auth()->user()), 403);

        return $alertContact;
    }

    public function update(Request $request, AlertContact $alertContact)
    {
        abort_unless($alertContact->isVisibleTo($request->user()), 403);

        $data = $this->validated($request, updating: true);

        if ($request->user()->isAdmin() && $request->filled('user_id')) {
            $data['user_id'] = $request->validate([
                'user_id' => ['integer', 'exists:users,id'],
            ])['user_id'];
        } else {
            unset($data['user_id']);
        }

        $alertContact->update($data);

        return $alertContact;
    }

    public function destroy(AlertContact $alertContact)
    {
        abort_unless($alertContact->isVisibleTo(auth()->user()), 403);

        $alertContact->delete();

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

    private function validated(Request $request, bool $updating = false): array
    {
        $req = $updating ? 'sometimes' : 'required';

        $data = $request->validate([
            'name' => [$req, 'string', 'max:160'],
            'type' => [$req, 'in:' . implode(',', array_keys(AlertContact::TYPES))],
            'target' => [$req, 'string', 'max:255'],
            'is_enabled' => ['nullable', 'boolean'],
        ]);

        if ($request->has('is_enabled')) {
            $data['is_enabled'] = $request->boolean('is_enabled');
        }

        return $data;
    }
}
