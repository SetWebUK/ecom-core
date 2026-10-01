<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Pine\Commerce\Commerce;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    // Store middleware + exception rules come from pine/commerce. Client middleware goes AFTER the call, e.g.
    //   fn (Middleware $m) => tap(Commerce::middleware($m), fn ($m) => $m->append(\App\Http\Middleware\Example::class))
    ->withMiddleware(fn (Middleware $middleware) => Commerce::middleware($middleware))
    ->withExceptions(fn (Exceptions $exceptions) => Commerce::exceptions($exceptions))
    ->create();
