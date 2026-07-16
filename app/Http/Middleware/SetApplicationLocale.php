<?php

namespace App\Http\Middleware;

use App\Services\Support\ApplicationLocaleService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetApplicationLocale
{
    public function __construct(
        private readonly ApplicationLocaleService $localeService
    ) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $this->localeService->apply();

        return $next($request);
    }
}
