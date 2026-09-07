<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class CscsApiContract
{
    public function handle(Request $request, Closure $next)
    {
        // Additive aliases; explicit API field names always take precedence.
        foreach (['pageSize' => 'per_page', 'snapshotHash' => 'snapshot_hash', 'confirmedByMaker' => 'confirmed_by_maker'] as $alias => $field) {
            if ($request->has($alias) && ! $request->has($field)) {
                $request->merge([$field => $request->input($alias)]);
            }
        }

        return $next($request);
    }
}
