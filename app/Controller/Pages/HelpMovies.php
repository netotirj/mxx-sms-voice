<?php

namespace App\Controller\Pages;

use App\Http\Response;
use App\Session\User as SessionUser;
use App\Utils\View;

class HelpMovies extends ViewComponents
{
    public static function getComponentsMovies():Response|string
    {
        $obUser = SessionUser::getLogged();
        if (!$obUser) {
            return new Response(401, [
                'status' => 401,
                'message' => 'Usuário não autenticado.'
            ], 'application/json');
        }

        $content = View::render('/movies/index', []);
        return parent::getComponentsUsers('Maxx Solutions - SMS | Vídeos', $content);
    }
}