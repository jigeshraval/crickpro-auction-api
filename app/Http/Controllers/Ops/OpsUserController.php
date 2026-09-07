<?php

namespace App\Http\Controllers\Ops;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Ops user directory for crickpro-admin (X-Ops-Signature gated). Lets an admin
 * find an auction-app user, then manage that user's per-auction subscriptions.
 */
class OpsUserController extends Controller
{
    /** Whitelisted search fields (matches the admin's field picker). */
    private const FIELDS = ['id', 'name', 'email', 'mobile'];

    public function index(Request $request): JsonResponse
    {
        $q = $request->string('q')->value() ?: null;
        $searchBy = $request->string('searchBy')->value() ?: null;

        $users = User::query()
            ->when($q && $searchBy && in_array($searchBy, self::FIELDS, true), function ($query) use ($q, $searchBy) {
                // Exact for id, LIKE for text columns.
                $searchBy === 'id' ? $query->where('id', (int) $q) : $query->where($searchBy, 'like', "%{$q}%");
            })
            ->when($q && ! $searchBy, function ($query) use ($q) {
                $query->where(fn ($w) => $w
                    ->where('name', 'like', "%{$q}%")
                    ->orWhere('email', 'like', "%{$q}%")
                    ->orWhere('mobile', 'like', "%{$q}%"));
            })
            ->latest()
            ->simplePaginate((int) $request->integer('perPage', 25));

        $users->through(fn (User $u) => [
            'id' => $u->id,
            'name' => $u->name,
            'email' => $u->email,
            'mobile' => $u->mobile,
            'profileImage' => $u->profile_image,
            'createdAt' => $u->created_at,
        ]);

        return response()->json([
            'status' => 'success',
            ...paginated($users, 'users', true),
        ]);
    }
}
