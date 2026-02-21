<?php

namespace App\Http\Middleware;

class Queue
{
private static $map = [];
private static $default = [];
private $middlewares;
private $controler;
private $controllerArgs = [];

public function __construct($middlewares,$controler,$controllerArgs)
{
    $this->middlewares    = array_merge(self::$default,$middlewares);
    $this->controler      = $controler;
    $this->controllerArgs = $controllerArgs;
}
public static function setMap($map)
{
    self::$map = $map;
}
public static function setDefault($default)
    {
        self::$default = $default;
    }
public function next($request)
{
    if (empty($this->middlewares)) return call_user_func_array($this->controler,$this->controllerArgs);
    $middleware = array_shift($this->middlewares);
    if(!isset(self::$map[$middleware]))
    {
        throw new \Exception("Problema ao Processar o Middleware", 500);
    }
    $queue = $this;
    $next = function ($request) use($queue)
    {
      return $queue->next($request);
    };
    return (new self::$map[$middleware])->handle($request,$next);

}
}