<?php

namespace App\Utils;

/**
 * Classe View
 *
 * Responsável por gerenciar a renderização de views
 * simples utilizando placeholders {{chave}}.
 */
class View
{
    /**
     * Variáveis padrão para todas as views.
     *
     * @var array
     */
    private static array $vars = [];

    /**
     * Inicializa variáveis globais disponíveis para todas as views.
     *
     * @param array $vars
     * @return void
     */
    public static function init(array $vars = []): void
    {
        self::$vars = $vars;
    }

    /**
     * Retorna o conteúdo de um arquivo de view.
     *
     * @param string $view
     * @return string
     */
    private static function getContentView(string $view): string
    {
        $file = __DIR__ . '/../../resources/view/' . $view . '.html';
        return file_exists($file) ? file_get_contents($file) : '';
    }

    /**
     * Retorna o conteúdo renderizado da view com as variáveis substituídas.
     *
     * @param string $view
     * @param array $vars
     * @return string
     */
    public static function render(string $view, array $vars = []): string
    {
        // Conteúdo da view
        $contentView = self::getContentView($view);

        // Mescla variáveis globais e locais
        $vars = array_merge(self::$vars, $vars);

        // Prepara as chaves para substituição
        $keys = array_map(
            fn($item) => '{{' . $item . '}}',
            array_keys($vars)
        );

        // Retorna o conteúdo renderizado
        return str_replace($keys, array_values($vars), $contentView);
    }
}
