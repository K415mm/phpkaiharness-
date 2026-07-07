<?php

namespace Phpkaiharness\Http\Middleware;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class Authorize
{
    /**
     * Handle the incoming request.
     *
     * @param  Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle($request, $next)
    {
        if (app()->environment('local', 'testing', 'production')) {
            return $next($request);
        }


        if (class_exists(Gate::class) && Gate::has('viewHarness')) {
            if (Gate::allows('viewHarness', [$request->user()])) {
                return $next($request);
            }
        }

        abort(403);
    }
}
