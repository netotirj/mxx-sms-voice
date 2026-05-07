<?php
$baseUrl = 'http://localhost/sms';
?><!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="base-url" content="<?= htmlspecialchars($baseUrl, ENT_QUOTES, 'UTF-8') ?>">
    <title>WhatsApp Visual Review</title>
    <link rel="stylesheet" href="<?= htmlspecialchars($baseUrl, ENT_QUOTES, 'UTF-8') ?>/resources/assets/css/nucleo-icons.css">
    <link rel="stylesheet" href="<?= htmlspecialchars($baseUrl, ENT_QUOTES, 'UTF-8') ?>/resources/assets/css/nucleo-svg.css">
    <link rel="stylesheet" href="<?= htmlspecialchars($baseUrl, ENT_QUOTES, 'UTF-8') ?>/resources/assets/css/argon-dashboard-tailwind.css?v=1.0.2">
    <script src="https://kit.fontawesome.com/ed8de5f6c8.js" crossorigin="anonymous"></script>
</head>
<body class="min-h-screen bg-slate-100 text-slate-700">
<script>
    window.notyf = {
        success() {},
        error() {},
        open() {}
    };
    window.Swal = {
        async fire(config) {
            return { isConfirmed: true, isDismissed: false, value: config?.inputValue || '' };
        }
    };
    window.EventSource = undefined;

    const reviewState = new URLSearchParams(window.location.search).get('state') || 'central';
    const mediaImage = 'https://images.unsplash.com/photo-1516321318423-f06f85e504b3?auto=format&fit=crop&w=900&q=80';
    const mediaAudio = 'https://www.soundhelix.com/examples/mp3/SoundHelix-Song-1.mp3';
    const mediaDoc = 'https://www.w3.org/WAI/ER/tests/xhtml/testfiles/resources/pdf/dummy.pdf';

    const mockData = {
        accounts: [{
            id: 11,
            number_id: 901,
            label: 'Suporte Premium',
            display_phone_number: '+55 21 99888-7766',
            display_name: 'Maxx Suporte',
            display_name_meta: 'Maxx Suporte Oficial',
            display_name_status: 'approved',
            number_status: 'active',
            status: 'active',
            photo_url: '',
            quality_rating: 'high'
        }],
        numberRequests: [{
            id: 501,
            phone_number: '5521999990000',
            internal_label: 'Financeiro',
            display_name_meta: 'Maxx Financeiro',
            display_name_status: 'approved',
            support_ticket_id: 344,
            requested_by_name: 'Neto',
            status: 'approved',
            whatsapp_number_id: 901
        }],
        numbers: [{
            id: 901,
            internal_label: 'Suporte Premium',
            account_label: 'Suporte Premium',
            phone_number: '55219988887766',
            display_name_meta: 'Maxx Suporte Oficial',
            display_name_status: 'approved',
            status: 'pending_verification',
            origin: 'Cloud API',
            meta_id: 'pn_901'
        }],
        health: [{
            id: 901,
            label: 'Suporte Premium',
            display_phone_number: '55219988887766',
            quality_rating: 'medium',
            verification_status: 'verified',
            display_name_status: 'approved',
            current_limit: '1000',
            status: 'active',
            last_sync_at: '2026-05-06 09:00:00'
        }],
        queues: [{
            id: 1,
            account_id: 11,
            account_label: 'Suporte Premium',
            name: 'Financeiro',
            description: 'Cobrança, renegociação e segunda via.',
            color: '#0ea5e9',
            priority: 5,
            status: 'active',
            waiting_count: 3,
            active_count: 2,
            business_hours: '{"mon":{"start":"08:00","end":"18:00"}}'
        }, {
            id: 2,
            account_id: 11,
            account_label: 'Suporte Premium',
            name: 'Suporte Técnico',
            description: 'Fila de atendimento técnico em tempo real.',
            color: '#10b981',
            priority: 10,
            status: 'active',
            waiting_count: 1,
            active_count: 4,
            business_hours: '{"mon":{"start":"08:00","end":"18:00"}}'
        }],
        users: [{
            id: 71,
            name: 'Ana Costa',
            avatar_url: '',
            online: 1
        }, {
            id: 72,
            name: 'Bruno Lima',
            avatar_url: '',
            online: 0
        }],
        templates: [{
            id: 801,
            account_id: 11,
            name: 'boleto_vencimento',
            language: 'pt_BR',
            category: 'UTILITY',
            status: 'approved',
            body: 'Olá {{1}}, seu boleto vence hoje. Acesse {{2}}.',
            variable_map: '{"1":{"key":"nome_cliente","label":"Nome do cliente","description":"Nome do contato","type":"text","source":"contact_or_sheet"},"2":{"key":"link_boleto","label":"Link do boleto","description":"URL enviada pela campanha","type":"url","source":"campaign_json"}}'
        }, {
            id: 802,
            account_id: 11,
            name: 'reativacao_plano',
            language: 'pt_BR',
            category: 'MARKETING',
            status: 'pending',
            body: 'Oi {{1}}, reative seu plano por {{2}} até hoje.',
            variable_map: '{"1":{"key":"nome_cliente","label":"Nome do cliente","description":"Nome do contato","type":"text","source":"contact_or_sheet"},"2":{"key":"valor_plano","label":"Valor do plano","description":"Valor do plano","type":"currency","source":"contact_or_sheet"}}'
        }],
        campaigns: [{
            id: 101,
            account_id: 11,
            name: 'Cobrança Maio',
            message_type: 'template',
            template_name: 'boleto_vencimento',
            template_language: 'pt_BR',
            status: 'queued',
            total_recipients: 154,
            queued_count: 120,
            sent_count: 20,
            failed_count: 14,
            created_at: '2026-05-06 08:30:00'
        }],
        templateLibrary: [{
            id: 'lib_1',
            source: 'system_approved',
            source_label: 'Sistema aprovado',
            name: 'cobranca_amigavel',
            suggested_name: 'cobranca_amigavel',
            language: 'pt_BR',
            category: 'UTILITY',
            body: 'Olá {{1}}, segue seu link de pagamento: {{2}}.',
            variable_map: {
                1: { key: 'nome_cliente' },
                2: { key: 'link_pagamento' }
            }
        }],
        conversations: [{
            id: 301,
            account_id: 11,
            contact_name: 'Marina Souza',
            contact_phone: '5521991112233',
            unread_count: 2,
            marketing_opt_out: 0,
            last_direction: 'inbound',
            last_message: 'Consegue me mandar a segunda via?',
            last_message_at: '2026-05-06 09:18:00',
            last_message_status: 'received',
            queue_id: 1,
            queue_name: 'Financeiro',
            queue_color: '#0ea5e9',
            queue_status: 'waiting',
            queue_waiting_seconds: 860,
            assigned_user_id: null,
            assigned_user_name: '',
            service_window_open: 1
        }, {
            id: 302,
            account_id: 11,
            contact_name: 'Carlos Mendes',
            contact_phone: '5521987654321',
            unread_count: 0,
            marketing_opt_out: 0,
            last_direction: 'outbound',
            last_message: 'Encaminhei a imagem do comprovante.',
            last_message_at: '2026-05-06 09:05:00',
            last_message_status: 'read',
            queue_id: 2,
            queue_name: 'Suporte Técnico',
            queue_color: '#10b981',
            queue_status: 'active',
            queue_waiting_seconds: 0,
            assigned_user_id: 71,
            assigned_user_name: 'Ana Costa',
            assigned_user_avatar_url: '',
            assigned_user_online: 1,
            service_window_open: 1
        }, {
            id: 303,
            account_id: 11,
            contact_name: 'Patrícia Rocha',
            contact_phone: '5521970011122',
            unread_count: 0,
            marketing_opt_out: 1,
            last_direction: 'outbound',
            last_message: 'Template enviado para reabertura.',
            last_message_at: '2026-05-05 18:40:00',
            last_message_status: 'delivered',
            queue_id: 1,
            queue_name: 'Financeiro',
            queue_color: '#0ea5e9',
            queue_status: 'finished',
            queue_waiting_seconds: 0,
            assigned_user_id: 72,
            assigned_user_name: 'Bruno Lima',
            assigned_user_avatar_url: '',
            assigned_user_online: 0,
            service_window_open: 0
        }],
        messages: {
            301: [{
                id: 1,
                direction: 'inbound',
                message_type: 'text',
                body: 'Oi, preciso da segunda via do boleto.',
                status: 'received',
                created_at: '2026-05-06 09:12:00'
            }, {
                id: 2,
                direction: 'outbound',
                message_type: 'template',
                body: '[Template] boleto_vencimento (pt_BR)',
                status: 'delivered',
                created_at: '2026-05-06 09:13:00'
            }, {
                id: 3,
                direction: 'outbound',
                message_type: 'image',
                body: 'Segue o PDF em imagem para consulta rápida.',
                status: 'read',
                created_at: '2026-05-06 09:15:00',
                media: {
                    url: mediaImage,
                    original_name: 'segunda_via.png',
                    file_size: 241220,
                    mime_type: 'image/png'
                }
            }, {
                id: 4,
                direction: 'inbound',
                message_type: 'audio',
                body: '',
                status: 'received',
                created_at: '2026-05-06 09:17:00',
                media: {
                    url: mediaAudio,
                    original_name: 'duvida-cliente.mp3',
                    file_size: 1815020,
                    mime_type: 'audio/mpeg'
                }
            }],
            302: [{
                id: 11,
                direction: 'inbound',
                message_type: 'text',
                body: 'Meu app travou na tela inicial.',
                status: 'received',
                created_at: '2026-05-06 08:55:00'
            }, {
                id: 12,
                direction: 'outbound',
                message_type: 'document',
                body: 'Manual atualizado em anexo.',
                status: 'sent',
                created_at: '2026-05-06 09:01:00',
                media: {
                    url: mediaDoc,
                    original_name: 'manual.pdf',
                    file_size: 95412,
                    mime_type: 'application/pdf'
                }
            }],
            303: [{
                id: 21,
                direction: 'outbound',
                message_type: 'template',
                body: '[Template] reativacao_plano (pt_BR)',
                status: 'failed',
                error_message: 'Template reprovado para esta janela.',
                created_at: '2026-05-05 18:40:00'
            }]
        }
    };

    const jsonResponse = (data) => Promise.resolve(new Response(JSON.stringify(data), {
        status: 200,
        headers: { 'Content-Type': 'application/json' }
    }));

    window.fetch = async (input, options = {}) => {
        const href = typeof input === 'string' ? input : String(input?.url || '');
        const url = new URL(href, window.location.origin);
        const path = url.pathname.replace('/sms', '');
        const state = new URLSearchParams(window.location.search).get('state') || 'central';

        if (path === '/campaign/whatsapp/accounts') {
            return jsonResponse({ success: true, data: mockData.accounts, can_reveal_pins: true });
        }
        if (path === '/campaign/whatsapp/number-requests') {
            return jsonResponse({ success: true, data: mockData.numberRequests, can_manage_numbers: true, can_reveal_pins: true });
        }
        if (path === '/campaign/whatsapp/numbers') {
            return jsonResponse({ success: true, data: mockData.numbers, can_reveal_pins: true });
        }
        if (path === '/campaign/whatsapp/numbers/health') {
            return jsonResponse({ success: true, data: mockData.health });
        }
        if (path === '/campaign/whatsapp/support/queues') {
            return jsonResponse({ success: true, data: mockData.queues });
        }
        if (path === '/campaign/whatsapp/support/assignable-users') {
            return jsonResponse({ success: true, data: mockData.users });
        }
        if (path === '/campaign/whatsapp/templates') {
            return jsonResponse({ success: true, data: mockData.templates });
        }
        if (path === '/campaign/whatsapp/templates/library') {
            return jsonResponse({ success: true, message: 'Modelos carregados.', data: mockData.templateLibrary });
        }
        if (path === '/campaign/whatsapp/campaigns') {
            return jsonResponse({ success: true, data: mockData.campaigns });
        }
        if (path === '/campaign/whatsapp/conversations') {
            return jsonResponse({ success: true, data: state === 'empty' ? [] : mockData.conversations });
        }
        const messageMatch = path.match(/^\/campaign\/whatsapp\/conversations\/(\d+)\/messages$/);
        if (messageMatch) {
            const conversationId = Number(messageMatch[1]);
            return jsonResponse({
                success: true,
                data: mockData.messages[conversationId] || [],
                meta: { limit: 200, has_more: conversationId === 301 }
            });
        }

        return jsonResponse({ success: true, data: [], message: 'mock-ok' });
    };
</script>

<main class="relative min-h-screen pb-12">
    <div class="absolute inset-x-0 top-0 h-72 bg-gradient-to-br from-emerald-600 via-teal-500 to-cyan-500"></div>
    <section class="relative z-10 pt-6">
        <?php include __DIR__ . '/../resources/view/whatsapp/index.html'; ?>
    </section>
</main>

<script>
    function forceModal(id) {
        const node = document.getElementById(id);
        if (!node) return;
        node.classList.remove('hidden');
        node.classList.add('flex');
    }

    function setSelectValue(id, value) {
        const field = document.getElementById(id);
        if (!field) return;
        field.value = value;
        field.dispatchEvent(new Event('change', { bubbles: true }));
    }

    function clickWhenReady(selector, attempts = 24, delay = 250) {
        const node = document.querySelector(selector);
        if (node) {
            node.click();
            return;
        }
        if (attempts <= 0) return;
        window.setTimeout(() => clickWhenReady(selector, attempts - 1, delay), delay);
    }

    window.addEventListener('load', () => {
        window.setTimeout(() => {
            if (reviewState === 'conversations' || reviewState === 'chat' || reviewState === 'empty') {
                clickWhenReady('[data-wa-section-link="conversations"]');
            }
            if (reviewState === 'chat' || reviewState === 'mobile-chat') {
                clickWhenReady('[data-wa-section-link="conversations"]');
                clickWhenReady('[data-conversation-id="301"]', 30, 300);
            }
            if (reviewState === 'queues') {
                clickWhenReady('[data-wa-open-section="queues"]');
            }
            if (reviewState === 'campaigns') {
                clickWhenReady('[data-wa-section-link="campaigns"]');
            }
            if (reviewState === 'templates') {
                clickWhenReady('[data-wa-section-link="templates"]');
            }
            if (reviewState === 'accounts') {
                clickWhenReady('[data-wa-open-section="accounts"]');
            }
            if (reviewState === 'safety') {
                clickWhenReady('[data-wa-open-section="safety"]');
            }
            if (reviewState === 'template-modal') {
                forceModal('wa-template-modal');
            }
            if (reviewState === 'pin-modal') {
                forceModal('wa-pin-modal');
            }
            if (reviewState === 'new-chat-template') {
                clickWhenReady('#wa-new-chat');
                setSelectValue('wa-new-chat-type', 'template');
            }
            if (reviewState === 'new-chat-audio') {
                clickWhenReady('#wa-new-chat');
                setSelectValue('wa-new-chat-type', 'audio');
            }
            if (reviewState === 'new-chat-text') {
                clickWhenReady('#wa-new-chat');
                setSelectValue('wa-new-chat-type', 'text');
            }
        }, 900);
    });
</script>
</body>
</html>
