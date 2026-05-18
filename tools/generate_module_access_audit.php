<?php

require __DIR__ . '/../vendor/autoload.php';

use App\Service\ModuleAccessMap;

$projectRoot = dirname(__DIR__);
$routesDir = $projectRoot . '/routes';
$viewComponentsPath = $projectRoot . '/app/Controller/Pages/ViewComponents.php';
$outputPath = $argv[1] ?? ($projectRoot . '/docs/route-access-plan-audit.md');

$viewComponentsSource = is_file($viewComponentsPath)
    ? (string)file_get_contents($viewComponentsPath)
    : '';

$routes = collectRoutes($routesDir, $viewComponentsSource);
usort($routes, static function (array $left, array $right): int {
    return [$left['route_name'], $left['http_method']] <=> [$right['route_name'], $right['http_method']];
});

$mappedRoutes = array_values(array_filter($routes, static fn (array $route): bool => $route['module_key'] !== ''));
$unmappedRoutes = array_values(array_filter($routes, static fn (array $route): bool => $route['module_key'] === ''));

$markdown = [];
$markdown[] = '# Auditoria de Acesso por Rota x Plano/Módulo';
$markdown[] = '';
$markdown[] = 'Gerado automaticamente a partir de `routes/`, `app/Service/ModuleAccessMap.php` e `app/Controller/Pages/ViewComponents.php`.';
$markdown[] = '';
$markdown[] = '## Resumo';
$markdown[] = '';
$markdown[] = '- Total de rotas catalogadas no código: ' . count($routes);
$markdown[] = '- Rotas com módulo resolvido no mapa oficial: ' . count($mappedRoutes);
$markdown[] = '- Rotas sem módulo resolvido no mapa oficial: ' . count($unmappedRoutes);
$markdown[] = '- Mensagem padrão para ACL de rota: `' . ModuleAccessMap::routePermissionDeniedMessage() . '`';
$markdown[] = '- Mensagem padrão para bloqueio comercial: `O módulo {Módulo} não faz parte do seu plano contratado.`';
$markdown[] = '';
$markdown[] = '## Causa Raiz';
$markdown[] = '';
$markdown[] = '- O bloqueio comercial estava disperso entre `ModuleAccessMap`, `PlanRuntimeService`, `PermissionMiddleware` e o menu hardcoded em `ViewComponents`.';
$markdown[] = '- O menu lateral vinha escondendo itens por plano em vez de deixá-los visíveis e delegar o bloqueio comercial ao backend.';
$markdown[] = '- A rota `/callcenter/reports` é fisicamente filha de `/callcenter`, mas comercialmente pertence ao módulo `Relatórios`; sem uma regra exata no mapa oficial, ela tende a cair no módulo errado.';
$markdown[] = '- As mensagens de bloqueio não carregavam contexto consistente de módulo, o que abria espaço para erro de identificação.';
$markdown[] = '';
$markdown[] = '## Matriz Oficial';
$markdown[] = '';
$markdown[] = '| Método | Rota | Controller::action | Módulo correto | Permissão | Exige plano? | Menu | Comportamento esperado |';
$markdown[] = '| --- | --- | --- | --- | --- | --- | --- | --- |';

foreach ($routes as $route) {
    $menuBehavior = $route['menu_visible']
        ? ($route['requires_plan']
            ? 'visível com ACL; bloqueia no clique sem módulo'
            : 'visível com ACL')
        : 'fora do menu';

    $markdown[] = sprintf(
        '| %s | `%s` | `%s` | %s | `%s` | %s | %s | %s |',
        $route['http_method'],
        escapeCell($route['route_name']),
        escapeCell($route['controller_action']),
        escapeCell($route['module_label'] !== '' ? $route['module_label'] : 'Sem módulo mapeado'),
        escapeCell($route['permission_key']),
        $route['requires_plan'] ? 'sim' : 'não',
        $route['menu_visible'] ? 'sim' : 'não',
        escapeCell($menuBehavior)
    );
}

$markdown[] = '';
$markdown[] = '## Módulos Oficiais';
$markdown[] = '';
$markdown[] = '| Chave | Label | Features | Permissões-base | Menus |';
$markdown[] = '| --- | --- | --- | --- | --- |';

foreach (ModuleAccessMap::matrixRows() as $module) {
    $markdown[] = sprintf(
        '| `%s` | %s | `%s` | `%s` | `%s` |',
        escapeCell((string)$module['module_key']),
        escapeCell((string)$module['label']),
        escapeCell(implode(', ', (array)$module['features'])),
        escapeCell(implode(', ', (array)$module['permissions'])),
        escapeCell(implode(', ', (array)$module['menus']))
    );
}

$markdown[] = '';
$markdown[] = '## Rotas Sem Módulo no Mapa Oficial';
$markdown[] = '';

if ($unmappedRoutes === []) {
    $markdown[] = '- Nenhuma rota do código ficou sem mapeamento pelo `ModuleAccessMap`.';
} else {
    foreach ($unmappedRoutes as $route) {
        $markdown[] = '- `' . $route['http_method'] . ' ' . $route['route_name'] . '` -> `' . $route['controller_action'] . '`';
    }
}

$markdown[] = '';
$markdown[] = '## Limitações desta execução';
$markdown[] = '';
$markdown[] = '- Esta execução auditou o código-fonte local.';
$markdown[] = '- A auditoria de `sys_routes`, `sys_role_permissions`, `sys_role_template_permissions` e `mxx_plans.modules_json` no banco não pôde ser concluída porque a conexão MySQL local recusou conexão neste ambiente.';
$markdown[] = '- Os itens de menu foram inferidos a partir das referências de rota em `ViewComponents.php`.';
$markdown[] = '';

$content = implode(PHP_EOL, $markdown) . PHP_EOL;
file_put_contents($outputPath, $content);

echo $outputPath . PHP_EOL;

function collectRoutes(string $routesDir, string $viewComponentsSource): array
{
    $rows = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($routesDir));

    foreach ($iterator as $fileInfo) {
        if (!$fileInfo->isFile() || strtolower($fileInfo->getExtension()) !== 'php') {
            continue;
        }

        $source = (string)file_get_contents($fileInfo->getPathname());
        if ($source === '') {
            continue;
        }

        preg_match_all(
            '/\$obRouter->(?P<method>get|post|patch|put|delete|options)\(\'(?P<path>[^\']+)\',\s*\[(?P<body>.*?)\]\);/si',
            $source,
            $matches,
            PREG_SET_ORDER
        );

        foreach ($matches as $match) {
            $body = (string)($match['body'] ?? '');
            $path = normalizeRouteName((string)($match['path'] ?? ''));
            $routeName = $path;

            if (preg_match("/'name'\\s*=>\\s*'(?P<name>[^']*)'/i", $body, $nameMatch)) {
                $candidate = trim((string)($nameMatch['name'] ?? ''));
                if ($candidate !== '') {
                    $routeName = normalizeRouteName($candidate);
                }
            }

            $controllerAction = 'closure';
            if (preg_match('/Pages\\\\(?P<controller>[A-Za-z0-9_]+)::(?P<action>[A-Za-z0-9_]+)/', $body, $controllerMatch)) {
                $controllerAction = $controllerMatch['controller'] . '::' . $controllerMatch['action'];
            }

            $context = ModuleAccessMap::routeContext($routeName);
            $menuVisible = routeIsReferencedInMenu($viewComponentsSource, $routeName);

            $rows[] = [
                'http_method' => strtoupper((string)($match['method'] ?? 'GET')),
                'route_path' => $path,
                'route_name' => $routeName,
                'controller_action' => $controllerAction,
                'module_key' => (string)($context['module_key'] ?? ''),
                'module_label' => (string)($context['module_label'] ?? ''),
                'permission_key' => $routeName,
                'requires_plan' => !empty($context['requires_plan']),
                'menu_visible' => $menuVisible,
            ];
        }
    }

    return $rows;
}

function routeIsReferencedInMenu(string $source, string $routeName): bool
{
    if ($source === '' || $routeName === '') {
        return false;
    }

    return str_contains($source, "'" . $routeName . "'")
        || str_contains($source, '"' . $routeName . '"');
}

function normalizeRouteName(string $routeName): string
{
    $routeName = '/' . trim($routeName, '/');
    return preg_replace('#/+#', '/', $routeName) ?: '/';
}

function escapeCell(string $value): string
{
    return str_replace('|', '\\|', $value);
}
