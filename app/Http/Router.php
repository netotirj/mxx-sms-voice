<?php

namespace App\Http;

use Closure;
use Exception;
use ReflectionFunction;
use App\Http\Middleware\Queue as MiddlewareQueue;

class Router
{
    /**
     * URL completa do projeto (raiz)
     */
    private string $url = '';

    /**
     * Prefixo de todas as rotas
     */
    private string $prefix = '';

    /**
     * Índice de rotas
     */
    private array $routes = [];

    /**
     * Instância de Request
     */
    private Request $request;

    public function __construct(string $url)
    {
        $this->url = rtrim($url, '/');
        $this->request = new Request($this);
        $this->setPrefix();
    }

    /**
     * Define o prefixo da rota a partir da URL raiz
     */
    private function setPrefix(): void
    {
        $parseUrl = parse_url($this->url);
        $this->prefix = $parseUrl['path'] ?? '';
    }

    /**
     * Adiciona uma rota ao índice de rotas
     */
    private function addRoute(string $method, string $route, array $params = []): void
    {
        foreach ($params as $key => $value) {
            if ($value instanceof Closure) {
                $params['controller'] = $value;
                unset($params[$key]);
            }
        }
        $params['name'] = $params['name'] ?? ''; // nome da rota
        $params['middlewares'] = $params['middlewares'] ?? [];
        $params['variables'] = [];

        $patternVariable = '/\{([a-zA-Z0-9_]+)\}/';
        if (preg_match_all($patternVariable, $route, $matches)) {
            $route = preg_replace($patternVariable, '([^\/]+)', $route);
            $params['variables'] = $matches[1];
        }

        $route = '/' . trim($route, '/');
        $patternRoute = '#^' . $route . '$#';

        $this->routes[$patternRoute][$method] = $params;
    }

    public function get(string $route, array $params = []): void
    {
        $this->addRoute('GET', $route, $params);
    }

    public function post(string $route, array $params = []): void
    {
        $this->addRoute('POST', $route, $params);
    }

    public function options(string $route, array $params = []): void
    {
        $this->addRoute('OPTIONS', $route, $params);
    }

    public function put(string $route, array $params = []): void
    {
        $this->addRoute('PUT', $route, $params);
    }

    public function delete(string $route, array $params = []): void
    {
        $this->addRoute('DELETE', $route, $params);
    }

    public function patch(string $route, array $params = []): void
    {
        $this->addRoute('PATCH', $route, $params);
    }

    private function normalizeUriPath(string $uri): string
    {
        $normalized = '/' . trim($uri, '/');

        return $normalized === '//' ? '/' : $normalized;
    }

    private function stripPrefixFromUri(string $uri, string $prefix): string
    {
        $uri = $this->normalizeUriPath($uri);
        $prefix = $this->normalizeUriPath($prefix);

        if ($prefix === '/') {
            return $uri;
        }

        if ($uri === $prefix) {
            return '/';
        }

        if (str_starts_with($uri, $prefix . '/')) {
            return $this->normalizeUriPath(substr($uri, strlen($prefix)));
        }

        return $uri;
    }

    /**
     * Retorna todas as URIs candidatas considerando prefixo configurado e
     * diretório real do front controller. Isso evita 404 quando o deploy usa
     * subpasta como /painel, mas o servidor entrega REQUEST_URI/SCRIPT_NAME
     * de forma diferente do ambiente local.
     */
    private function getCandidateUris(): array
    {
        $rawUri = $this->normalizeUriPath($this->request->getURI());
        $candidates = [$rawUri];

        $prefixes = array_filter([
            $this->prefix,
            dirname($_SERVER['SCRIPT_NAME'] ?? ''),
            dirname($_SERVER['PHP_SELF'] ?? ''),
        ], static fn ($value) => is_string($value) && trim($value) !== '' && trim($value) !== '.');

        foreach (array_unique($prefixes) as $prefix) {
            $stripped = $this->stripPrefixFromUri($rawUri, $prefix);
            if (!in_array($stripped, $candidates, true)) {
                $candidates[] = $stripped;
            }
        }

        return $candidates;
    }

    /**
     * Retorna a rota correspondente à URI atual
     *
     * @throws Exception se não encontrar ou se o método não for permitido
     */
    /*private function getRoute(): array
    {
        $uri = $this->getUri();
        $httpMethod = $this->request->getHttpMethod();

        foreach ($this->routes as $patternRoute => $methods) {
            if (preg_match($patternRoute, $uri, $matches)) {
                if (isset($methods[$httpMethod])) {
                    array_shift($matches);
                    $keys = $methods[$httpMethod]['variables'];
                    $methods[$httpMethod]['variables'] = array_combine($keys, $matches);
                    $methods[$httpMethod]['variables']['request'] = $this->request;
                    return $methods[$httpMethod];
                }
                throw new Exception("Método não permitido", 405);
            }
        }

        throw new Exception("URL não encontrada", 404);
    }*/

    private function getRoute(): array
    {
        $httpMethod = $this->request->getHttpMethod();
        $candidateUris = $this->getCandidateUris();

        $routes = $this->routes;

        uksort($routes, function ($a, $b) {
            $aVars = substr_count($a, '([^\/]+)');
            $bVars = substr_count($b, '([^\/]+)');

            // 1) menos variáveis primeiro
            if ($aVars !== $bVars) return $aVars <=> $bVars;

            // 2) pattern mais longo primeiro (mais específico)
            return strlen($b) <=> strlen($a);
        });

        foreach ($candidateUris as $uri) {
            foreach ($routes as $patternRoute => $methods) {
                if (preg_match($patternRoute, $uri, $matches)) {
                    if (isset($methods[$httpMethod])) {
                        array_shift($matches);
                        $keys = $methods[$httpMethod]['variables'];
                        $methods[$httpMethod]['variables'] = array_combine($keys, $matches);
                        $methods[$httpMethod]['variables']['request'] = $this->request;
                        return $methods[$httpMethod];
                    }

                    throw new Exception("Método não permitido", 405);
                }
            }
        }

        throw new Exception("URL não encontrada", 404);
    }

    /**
     * Executa o roteamento da aplicação
     */
    public function run(): Response
    {
        try {
            $route = $this->getRoute();

            if (!isset($route['controller'])) {
                throw new Exception("A URL não pode ser processada", 500);
            }

            $reflection = new ReflectionFunction($route['controller']);
            $args = [];

            foreach ($reflection->getParameters() as $parameter) {
                $name = $parameter->getName();
                $args[$name] = $route['variables'][$name] ?? '';
            }

            // Setar o nome da rota no Request para os middlewares
            $this->request->setRouteName($route['name'] ?? '');
            return (new MiddlewareQueue(
                $route['middlewares'],
                $route['controller'],
                $args
            ))->next($this->request);
        } catch (Exception $e) {
            return new Response($e->getCode(), $e->getMessage());
        }
    }

    /**
     * Redireciona para uma rota
     */
    public function redirect(string $route, int $statusCode = 302): void
    {
        $url = $this->url . '/' . ltrim($route, '/');
        header('Location: ' . $url, true, $statusCode);
        exit;
    }

    /**
     * Retorna a URL completa atual
     */
    public function getCurrentUrl(): string
    {
        $candidateUris = $this->getCandidateUris();

        return $this->url . ($candidateUris[0] ?? '/');
    }

}
