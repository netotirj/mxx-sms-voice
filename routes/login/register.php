<?php
global $obRouter;

use \App\Http\Response;
use \App\Controller\Pages;


$obRouter->get('/register',[
    'middlewares' => [
        'require-session-logout'
    ],
    function($request){
        //echo password_hash('123321',PASSWORD_DEFAULT);
        //exit;
        return new Response(200,Pages\RegisterUsers::getRegister($request));
    }

]);

$obRouter->post('/register',[
    'middlewares' => [
        //'require-session-logout'
    ],
    function($request){
        //echo password_hash('123321',PASSWORD_DEFAULT);
        return new Response(200,Pages\RegisterUsers::setRegister($request));
    }

]);
