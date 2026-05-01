<?php

namespace App\Http;

class Response
{
    private int $httpCode = 200;
    private array $headers = [];
    private string $contentType = 'text/html';
    private mixed $content;

    // Tipos de conteúdo suportados
    private const array SUPPORTED_CONTENT_TYPES = [
        'text/html',
        'application/json',
        'text/event-stream',
        'image/png',
        'image/x-icon'
    ];

    public function __construct(int $httpCode = 200, mixed $content = '', string $contentType = 'text/html')
    {
        $this->httpCode = $httpCode;
        $this->content = $content;
        $this->setContentType($contentType);
    }

    public function setContentType(string $contentType): void
    {
        if (!in_array($contentType, self::SUPPORTED_CONTENT_TYPES)) {
            throw new \InvalidArgumentException("Content-Type '{$contentType}' não é suportado.");
        }
        $this->contentType = $contentType;
        $this->addHeader('Content-Type', $contentType);
    }

    public function addHeader(string $key, string $value): void
    {
        $this->headers[$key] = $value;
    }

    private function sendHeaders(): void
    {
        if (!headers_sent()) {
            http_response_code($this->httpCode);
            foreach ($this->headers as $key => $value) {
                header("{$key}: {$value}");
            }
        } else {
            error_log("Headers já enviados, impossível setar HTTP code {$this->httpCode} ou headers adicionais.");
        }
    }

    public function sendResponse(): void
    {
        $this->sendHeaders();

        switch ($this->contentType) {
            case 'application/json':

                // 🔥 evita double encode automaticamente
                if (is_string($this->content)) {
                    echo $this->content;
                    break;
                }

                $json = json_encode($this->content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

                if ($json === false) {
                    http_response_code(500);
                    echo json_encode(['error' => 'Erro ao gerar JSON: ' . json_last_error_msg()]);
                } else {
                    echo $json;
                }
                break;

            case 'text/event-stream':
                echo $this->content;
                break;

            case 'text/html':
            default:
                // Mantém comportamento antigo: envia conteúdo como está
                echo $this->content;
                break;
        }
    }


    public function __toString(): string
    {
        ob_start();
        $this->sendResponse();
        return ob_get_clean();
    }
}
