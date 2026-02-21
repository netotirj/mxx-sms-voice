<?php

namespace WilliamCosta\DotEnv;

class Environment{

  /**
   * Método responsável por carregar as variáveis de ambiente do projeto
   * @param  string $dir Caminho absoluto da pasta onde encontra-se o arquivo .env
   */
  public static function load($dir){
    //VERIFICA SE O ARQUIVO .ENV EXISTE
    if(!file_exists($dir.'/.env')){
      return false;
    }

    //DEFINE AS VARIÁVEIS DE AMBIENTE
    $lines = file($dir.'/.env');
      foreach($lines as $line){
          $line = trim($line);
          if($line === '' || str_starts_with($line, '#')) continue;

          putenv($line);

          [$key, $value] = explode('=', $line, 2);
          $_ENV[$key] = $value;
          $_SERVER[$key] = $value;
      }

  }

}