<?php

namespace App\Controller\Pages;


use App\Http\Response;
use App\Session\User as SessionUser;
use App\Utils\View;

class WhatsApp extends ViewComponents
{
    public static function getComponentsWhatsApp(): Response|string
    {
        $obUser = SessionUser::getLogged();
        if (!$obUser) {
            return new Response(401, [
                'status' => 401,
                'message' => 'Usuário não autenticado.'
            ], 'application/json');
        }

        $content = View::render('/whatsapp/index', []);
        return parent::getComponentsUsers('Maxx Solutions - SMS | VOZ', $content);
    }

}