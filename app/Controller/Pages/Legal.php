<?php

namespace App\Controller\Pages;

use App\Utils\View;

class Legal
{
    public static function getPrivacyPolicy(): string
    {
        return self::renderDocument(
            'Politica de Privacidade',
            'Politica de Privacidade',
            [
                'A Maxx Solutions respeita a privacidade dos usuarios e utiliza os dados fornecidos apenas para operacao, seguranca e melhoria dos servicos contratados.',
                'Podemos tratar dados como nome, e-mail, telefone, identificadores de login social, informacoes de acesso e dados necessarios para atendimento, autenticacao, comunicacao, envio de mensagens, pagamentos e suporte.',
                'Dados recebidos por provedores sociais, como Google ou Facebook, sao usados somente para identificar o usuario, validar o e-mail e permitir acesso seguro a conta.',
                'Nao vendemos dados pessoais. Podemos compartilhar informacoes somente quando necessario para prestadores de infraestrutura, meios de pagamento, provedores de comunicacao, cumprimento legal ou protecao contra fraude.',
                'O usuario pode solicitar correcao, portabilidade, revogacao de consentimento ou exclusao de dados pelos canais de atendimento informados nesta pagina.',
            ]
        );
    }

    public static function getTerms(): string
    {
        return self::renderDocument(
            'Termos de Servico',
            'Termos de Servico',
            [
                'Ao utilizar a plataforma Maxx Solutions, o usuario concorda em fornecer informacoes verdadeiras e manter suas credenciais protegidas.',
                'Os recursos de SMS, WhatsApp, voz, Pix, relatorios e demais modulos devem ser usados conforme a legislacao aplicavel, regras dos provedores integrados e politicas antiabuso.',
                'O usuario e responsavel pelo conteudo enviado por meio da plataforma, pela autorizacao de seus contatos e pela regularidade de suas campanhas.',
                'A Maxx Solutions pode suspender acessos em caso de uso indevido, tentativa de fraude, violacao de seguranca, inadimplencia ou descumprimento destes termos.',
                'Estes termos podem ser atualizados para refletir alteracoes legais, tecnicas ou comerciais. A continuidade de uso indica concordancia com a versao vigente.',
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
                'Canal de atendimento: netoacrj@outlook.com',
            ]
        );
    }

    private static function renderDocument(string $title, string $heading, array $paragraphs): string
    {
        $content = '';
        foreach ($paragraphs as $paragraph) {
            $content .= '<p>' . htmlspecialchars($paragraph, ENT_QUOTES, 'UTF-8') . '</p>';
        }

        return View::render('legal/document', [
            'title' => htmlspecialchars($title, ENT_QUOTES, 'UTF-8'),
            'heading' => htmlspecialchars($heading, ENT_QUOTES, 'UTF-8'),
            'content' => $content,
            'updated_at' => date('d/m/Y'),
            'contact_email' => 'netoacrj@outlook.com',
        ]);
    }
}
