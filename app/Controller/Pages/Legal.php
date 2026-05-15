<?php

namespace App\Controller\Pages;

use App\Utils\View;

class Legal
{
    private static function siteUrl(string $path = ''): string
    {
        $configured = trim((string)getenv('APP_SITE_URL'));
        if ($configured !== '') {
            return rtrim($configured, '/') . $path;
        }

        $systemUrl = trim((string)(defined('URL') ? URL : (getenv('URL') ?: '')));
        $scheme = (string)(parse_url($systemUrl, PHP_URL_SCHEME) ?: 'https');
        $host = (string)(parse_url($systemUrl, PHP_URL_HOST) ?: '');

        return $host !== '' ? $scheme . '://' . $host . $path : $path;
    }

    private static function supportEmail(): string
    {
        $supportEmail = trim((string)getenv('SUPPORT_EMAIL'));
        if (filter_var($supportEmail, FILTER_VALIDATE_EMAIL)) {
            return $supportEmail;
        }

        $mailFrom = trim((string)getenv('MAIL_FROM'));
        if (filter_var($mailFrom, FILTER_VALIDATE_EMAIL)) {
            return $mailFrom;
        }

        return 'sac@maxxsolutions.com.br';
    }

    public static function getPrivacyPolicy(): string
    {
        return self::renderDocument(
            'Politica de Privacidade',
            'Politica de Privacidade',
            [
                [
                    'title' => 'Dados que podemos tratar',
                    'paragraphs' => [
                        'A Maxx Solutions trata dados pessoais necessarios para operacao do site institucional e da plataforma, incluindo nome, e-mail, telefone, identificadores de usuario, dados de clientes cadastrados pelo contratante, informacoes de suporte, registros tecnicos e informacoes relacionadas a pagamentos.',
                        'No site institucional tambem podemos registrar o endereco IP, informacoes do navegador, data e hora de acesso, alem dos dados enviados em formularios de teste, contato ou demonstracao.',
                    ],
                ],
                [
                    'title' => 'Finalidades do tratamento',
                    'paragraphs' => [
                        'Usamos esses dados para autenticar usuarios, prestar suporte, enviar testes solicitados, operar servicos de voz, SMS e WhatsApp, prevenir fraude, manter trilhas de auditoria, cumprir obrigacoes legais e melhorar a experiencia da plataforma.',
                        'Quando houver integracoes com meios de pagamento ou provedores de comunicacao, os dados estritamente necessarios podem ser compartilhados para processar cobrancas, validar transacoes ou entregar o servico contratado.',
                    ],
                ],
                [
                    'title' => 'IP, logs e seguranca',
                    'paragraphs' => [
                        'O endereco IP pode ser tratado para seguranca, controle de abuso, prevencao a fraude, validacoes de captcha, auditoria tecnica e investigacao de incidentes.',
                        'Em telas operacionais comuns, o IP deve aparecer mascarado. O acesso ao IP completo fica restrito a perfis administrativos autorizados quando houver necessidade operacional ou de seguranca.',
                    ],
                ],
                [
                    'title' => 'Cookies e analytics',
                    'paragraphs' => [
                        'O sistema principal utiliza cookies essenciais de sessao e autenticacao para manter o login e a seguranca da conta. Esses cookies sao necessarios para o funcionamento do painel.',
                        'O site institucional pode utilizar cookies analiticos somente apos o aceite do usuario. O Google Analytics deve ser configurado sem envio de nome, e-mail, telefone, CPF, CNPJ, dados de pagamento ou outros identificadores sensiveis.',
                    ],
                ],
                [
                    'title' => 'Compartilhamento e direitos',
                    'paragraphs' => [
                        'Nao vendemos dados pessoais. O compartilhamento ocorre apenas quando necessario para infraestrutura, suporte, comunicacao, pagamentos, cumprimento legal ou defesa de direitos.',
                        'O titular pode solicitar acesso, correcao, atualizacao, portabilidade, anonimização, revogacao de consentimento ou exclusao de dados pelos canais oficiais de atendimento, observadas as obrigacoes legais de retencao.',
                    ],
                ],
            ]
        );
    }

    public static function getTerms(): string
    {
        return self::renderDocument(
            'Termos de Servico',
            'Termos de Servico',
            [
                [
                    'title' => 'Uso da plataforma',
                    'paragraphs' => [
                        'Ao utilizar a plataforma Maxx Solutions, o usuario concorda em fornecer informacoes verdadeiras, manter suas credenciais protegidas e usar os servicos apenas para finalidades licitas e autorizadas.',
                        'Os modulos de voz, SMS, WhatsApp, Pix, relatorios e demais recursos devem respeitar a legislacao aplicavel, as regras dos provedores integrados e as politicas antiabuso.',
                    ],
                ],
                [
                    'title' => 'Responsabilidades do cliente',
                    'paragraphs' => [
                        'O cliente e responsavel pelos dados que cadastra no sistema, pela regularidade das bases utilizadas em campanhas e pela obtencao das autorizacoes necessarias de seus contatos.',
                        'Tambem e responsabilidade do cliente revisar perfis de acesso, permissoes internas e procedimentos de seguranca relacionados aos usuarios da conta.',
                    ],
                ],
                [
                    'title' => 'Suspensao e atualizacoes',
                    'paragraphs' => [
                        'A Maxx Solutions pode suspender acessos em caso de uso indevido, tentativa de fraude, incidente de seguranca, inadimplencia ou descumprimento contratual.',
                        'Estes termos podem ser atualizados para refletir alteracoes legais, tecnicas ou operacionais. A continuidade de uso apos a publicacao da versao vigente representa concordancia com os termos atualizados.',
                    ],
                ],
            ]
        );
    }

    public static function getCookiePolicy(): string
    {
        return self::renderDocument(
            'Politica de Cookies',
            'Politica de Cookies',
            [
                [
                    'title' => 'Cookies essenciais',
                    'paragraphs' => [
                        'O sistema principal utiliza cookies essenciais para sessao, autenticacao, seguranca e manutencao do login, incluindo recursos como sessao PHP e lembranca de acesso quando o usuario opta por permanecer conectado.',
                        'Esses cookies sao necessarios para o funcionamento do painel e nao podem ser desativados sem comprometer o uso da plataforma.',
                    ],
                ],
                [
                    'title' => 'Cookies analiticos',
                    'paragraphs' => [
                        'O site institucional pode utilizar Google Analytics apenas apos o aceite do usuario para medir acessos e comportamento agregado de navegacao.',
                        'Quando habilitado, o Analytics deve operar com minimizacao de dados, sem envio de nome, e-mail, telefone, CPF, CNPJ, dados de pagamento ou identificadores sensiveis em URLs e eventos.',
                    ],
                ],
                [
                    'title' => 'Preferencias e controle',
                    'paragraphs' => [
                        'O visitante pode aceitar, recusar ou configurar cookies nao essenciais por meio do banner exibido no site institucional.',
                        'A qualquer momento, as preferencias podem ser revisadas pelo link de configuracao de cookies disponivel no rodape do site.',
                    ],
                ],
            ]
        );
    }

    public static function getDataDeletion(): string
    {
        return self::renderDocument(
            'Exclusao de Dados',
            'Instrucao para Exclusao de Dados',
            [
                'Para solicitar exclusao de dados vinculados a sua conta, envie uma mensagem para o suporte informando o e-mail cadastrado e o provedor utilizado no login social, quando aplicavel.',
                'A solicitacao sera analisada para validar a titularidade da conta e verificar obrigacoes legais, financeiras, antifraude ou regulatórias que possam exigir retencao temporaria.',
                'Quando a exclusao for confirmada, dados pessoais nao essenciais serao removidos ou anonimizados dos sistemas ativos dentro de prazo razoavel.',
                'Registros tecnicos, fiscais, transacionais ou de seguranca poderao ser mantidos pelo periodo exigido por lei ou necessario para defesa de direitos.',
                'Canal de atendimento: ' . self::supportEmail(),
            ]
        );
    }

    private static function renderDocument(string $title, string $heading, array $sections): string
    {
        $content = '';
        foreach ($sections as $section) {
            if (is_string($section)) {
                $content .= '<p>' . htmlspecialchars($section, ENT_QUOTES, 'UTF-8') . '</p>';
                continue;
            }

            $content .= '<section class="section">';
            if (!empty($section['title'])) {
                $content .= '<h2>' . htmlspecialchars((string)$section['title'], ENT_QUOTES, 'UTF-8') . '</h2>';
            }
            foreach (($section['paragraphs'] ?? []) as $paragraph) {
                $content .= '<p>' . htmlspecialchars((string)$paragraph, ENT_QUOTES, 'UTF-8') . '</p>';
            }
            $content .= '</section>';
        }

        return View::render('legal/document', [
            'title' => htmlspecialchars($title, ENT_QUOTES, 'UTF-8'),
            'heading' => htmlspecialchars($heading, ENT_QUOTES, 'UTF-8'),
            'content' => $content,
            'document_links' => self::documentLinks(),
            'updated_at' => date('d/m/Y'),
            'contact_email' => self::supportEmail(),
        ]);
    }

    private static function documentLinks(): string
    {
        $links = [
            'Privacidade' => trim((string)(getenv('APP_PRIVACY_URL') ?: self::siteUrl('/politica-de-privacidade'))),
            'Cookies' => trim((string)(getenv('APP_COOKIES_URL') ?: self::siteUrl('/politica-de-cookies'))),
            'Termos' => trim((string)(getenv('APP_TERMS_URL') ?: self::siteUrl('/termos-de-uso'))),
        ];

        $html = '';
        foreach ($links as $label => $url) {
            $html .= '<a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</a>';
        }

        return $html;
    }
}
