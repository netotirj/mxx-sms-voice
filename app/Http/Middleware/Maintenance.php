<?php

namespace App\Http\Middleware;

use App\Utils\View;
use App\Http\Response;

class Maintenance
{
    public function handle($request, $next)
    {
        if (getenv('MAINTENANCE') === 'true') {

            // Renderiza uma view específica de manutenção
            $content = View::render('maintenance/index', [
                'title'   => 'Site em Manutenção',
                'message' => 'Estamos realizando ajustes. Tente novamente mais tarde.'
            ]);

            return new Response(503, $content); // 503 = Service Unavailable
        }

        return $next($request);
    }
}
