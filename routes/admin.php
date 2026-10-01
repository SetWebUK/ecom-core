<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Back-office routes  (prefix: /admin, name prefix: admin.)
|--------------------------------------------------------------------------
| Loaded by Pine\Commerce\CommerceServiceProvider with the 'web' middleware group (prefix commerce.admin.path). Guest routes
| (login / password reset) live here; everything else sits behind the
| 'admin' middleware (staff only) and is split per area in routes/admin/*.php.
*/

require __DIR__.'/admin/auth.php';

Route::middleware('admin')->group(function () {
    foreach (['core', 'sales', 'catalogue', 'content'] as $area) {
        if (is_file($file = __DIR__."/admin/{$area}.php")) {
            require $file;
        }
    }
});
