<?php

namespace App\Http;

class Request
{
    private $router;
    private $httpMethod;
    private $uri;
    private $queryParams = [];
    private $postVars = [];
    private $headers = [];

    // NOVO: propriedade para armazenar o nome da rota
    private $routeName = '';

    public function __construct($router)
    {
        $this->router      = $router;
        $this->queryParams = $_GET ?? [];
        $this->postVars    = $_POST ?? [];
        $this->headers     = getallheaders();
        $this->httpMethod  = $_SERVER['REQUEST_METHOD'] ?? '';
        $this->setUri();
    }

    private function setUri()
    {
        $this->uri = $_SERVER["REQUEST_URI"] ?? '';
        $xURI = explode('?',$this->uri);
        $this->uri = $xURI[0];
    }

    public function getRouter()
    {
        return $this->router;
    }

    public function getHttpMethod()
    {
        return $this->httpMethod;
    }

    public function getURI()
    {
        return $this->uri;
    }

    public function getHeaders()
    {
        return $this->headers;
    }

    public function getQueryParams()
    {
        return $this->queryParams;
    }

    public function getPostVars()
    {
        return $this->postVars;
    }

    // NOVOS MÉTODOS PARA ROTA
    public function setRouteName(string $name): void
    {
        $this->routeName = $name;
    }

    public function getRouteName(): string
    {
        return $this->routeName;
    }
}
