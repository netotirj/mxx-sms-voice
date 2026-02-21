<?php
global $obRouter;

use \App\Http\Response;
use \App\Controller\Pages;

$obRouter->post('/users/session',[
    'middlewares' => [
        //'require-session-login'
    ],
    function($request){
        //return new Response(200,'Home::getHome');
        return new Response(200,Pages\HeartbeatController::updateSession($request));
    }

]);