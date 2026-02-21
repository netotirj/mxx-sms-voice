<?php

namespace App\Http\Middleware;
use \App\Session\User as SessionLogin;
class RequireSessionLogin
{
    public function handle($request,$next)
    {
        if(!SessionLogin::isLogged())
        {
            $request->getRouter()->redirect('/login');
        }
        return $next($request);
    }
}