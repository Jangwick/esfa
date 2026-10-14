<?php

namespace App\Http\Controllers;

use Symfony\Component\HttpFoundation\Response;

class OpenApiController extends Controller
{
    public function __invoke(): Response
    {
        // docs/openapi.yaml is canonical; the container (built from backend/ only) serves the synchronized copy.
        $path = collect([base_path('../docs/openapi.yaml'), resource_path('openapi.yaml')])->first(fn (string $file) => is_file($file));
        abort_unless($path, 404);

        return response()->file($path, ['Content-Type' => 'application/yaml', 'X-Content-Type-Options' => 'nosniff']);
    }
}
