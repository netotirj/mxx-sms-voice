<?php

namespace App\Controller\Pages;

class Logger
{
    // Caminho fixo no /var/logs
    private static string $logFile = '/var/log/sms.log';

    /**
     * Permite alterar o caminho do log se precisar
     */
    public static function setLogFile(string $filePath): void
    {
        self::$logFile = $filePath;
    }

    /**
     * Escreve mensagem no log
     */
    public static function log(string $message, string $level = 'INFO'): void
    {
        $date = date('Y-m-d H:i:s');
        $formattedMessage = "[{$date}] [{$level}] {$message}" . PHP_EOL;

        $dir = dirname(self::$logFile);

        // Garante que o diretório existe
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        // Salva a mensagem no arquivo
        file_put_contents(self::$logFile, $formattedMessage, FILE_APPEND | LOCK_EX);
    }

    // Atalhos
    public static function info(string $message): void
    {
        self::log($message, 'INFO');
    }

    public static function warning(string $message): void
    {
        self::log($message, 'WARNING');
    }

    public static function error(string $message): void
    {
        self::log($message, 'ERROR');
    }
}
