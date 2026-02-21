<?php

namespace App\Controller\Pages;
use App\Utils\View;
class Page
{
    /*
     * Metodo que traz o header da pagina
     * @return string
     */
    private static function getHeader(): string
    {
        return View::render('pages/header');
    }
    /*
    * Metodo que traz o footer da pagina
    * @return string
    */
    private static function getFooter(): string
    {
        return View::render('pages/footer');
    }

    /*
     * Metodo responsavel por retornar os dados da pagina como css menu etc
     * @return string
     */
    public static function getPage($title,$content): string
    {
        return View::render('pages/login/page-login',[
           'title' => $title,
           'header' => self::getHeader(),
           'content' => $content,
           'footer' => self::getFooter()
        ]);
    }
    private static function getMenu($currentModule): string
    {
        return View::render('pages/menu/dashboard',[

        ]);
    }
    public static function getPanel($title,$content,$currentModule): string
    {
        $contentPanel = View::render('pages/panel',[
            'menu' => self::getMenu($currentModule),
            'content' => $content
        ]);
        return self::getPage($title,$contentPanel);
    }
}