<?php

namespace App\Http\Controllers;

abstract class Controller
{
    protected function requirePermission(string $permission): void
    {
        abort_unless(request()->user()?->can($permission), 403, 'This action requires '.$permission.'.');
    }
}
