# Monitor SIP / SNGREP

## Visão geral

Primeira versão do painel sob demanda para diagnóstico SIP em tempo real, sem daemon permanente e sem stack pesada tipo Homer.

Fluxos suportados:

- `SNGREP rede`: captura SIP UDP/TCP por interface/porta
- `PJSIP Logger`: captura SIP TLS/WebRTC já descriptografado pelo Asterisk
- `Automático`: escolhe `both`, `pjsip` ou `sngrep` conforme backend disponível e filtro

## Dependências necessárias

- `sngrep`
- `asterisk`
- `script` do `util-linux`
- `timeout` do `coreutils`
- acesso SSH ao host do Asterisk, se a captura rodar remoto
- permissão para executar:
  - `asterisk -rx "..."`
  - `sngrep`
  - `kill`

## Comandos de verificação

```bash
which sngrep
sngrep -V
which asterisk
asterisk -rx "core show version"
asterisk -rx "pjsip show endpoints"
asterisk -rx "pjsip set logger off"
```

## Instalação

Exemplo Debian/Ubuntu:

```bash
apt-get update
apt-get install -y sngrep asterisk util-linux coreutils
```

Se o painel estiver fora do host do Asterisk:

- `SIP_MONITOR_EXEC_MODE=ssh`
- `SIP_MONITOR_HOST=IP_OU_HOST_DO_ASTERISK`
- `SIP_MONITOR_USER=root`
- `SIP_MONITOR_PORT=22`
- `SIP_MONITOR_KEY=/caminho/da/chave`
- `SIP_MONITOR_USE_SUDO=1`
- `SIP_MONITOR_REMOTE_DIR=/tmp/maxx-sip-monitor`

Controle de concorrência:

- `SIP_MONITOR_MAX_SIMULTANEOUS=1`

## Banco de dados

Rodar:

```bash
mysql -u SEU_USUARIO -p SEU_BANCO < /var/www/painel/sql/20260519_sip_monitor.sql
```

Tabelas criadas:

- `sip_monitor_sessions`
- `sip_monitor_logs`

## Arquivos criados

- `app/Controller/Pages/SipMonitor.php`
- `app/Model/Entity/SipMonitorSession.php`
- `app/Model/Entity/SipMonitorLog.php`
- `app/Service/SipMonitorCommandRunner.php`
- `app/Service/SipMonitorCommandBuilder.php`
- `app/Service/SipMonitorSessionService.php`
- `resources/view/sip-monitor/index.html`
- `routes/dash/sip-monitor.php`
- `run_sip_monitor_cleanup.php`
- `sql/20260519_sip_monitor.sql`
- `docs/sip-monitor.md`

## Rotas criadas

- `GET /admin/sip-monitor`
- `GET /admin/sip-monitor/status`
- `POST /admin/sip-monitor/start`
- `POST /admin/sip-monitor/stop`
- `GET /admin/sip-monitor/stream`
- `POST /admin/sip-monitor/emergency-stop`

## Permissões criadas

- catálogo global: `admin_sip_monitor`
- menu administrativo: `Monitor SIP`

Perfis liberados:

- `super_admin`
- `admin`
- `developer`
- `support_l1`
- `support_l2`

## Como iniciar uma captura

1. Abrir `Administrativo > Monitor SIP`
2. Escolher modo, filtro, porta, interface e duração
3. Clicar em `Iniciar captura`

## Como parar uma captura

- botão `Parar captura`
- expiração automática

Ao finalizar:

- processos são mortos
- `pjsip set logger off` é executado

## Como matar sessão travada

Limpeza de expiradas:

```bash
php /var/www/painel/run_sip_monitor_cleanup.php
```

Stop forçado:

```bash
php /var/www/painel/run_sip_monitor_cleanup.php --all
```

## Como garantir que o PJSIP logger ficou off

```bash
asterisk -rx "pjsip set logger off"
```

O painel também executa isso:

- ao parar manualmente
- ao expirar
- no stop de emergência

## Cron recomendado

```cron
* * * * * /usr/bin/php /var/www/painel/run_sip_monitor_cleanup.php >> /var/log/sms/sip-monitor-cleanup.log 2>&1
```

## Limitações conhecidas

- nesta primeira versão a aba `SNGREP` mostra a saída capturada do processo, não um terminal web interativo completo com `xterm.js`
- filtros de rede estritos no `sngrep` são mais confiáveis para `IP`, `porta` e `interface`
- `ramal`, `número`, `call-id` e `tronco` dependem mais do parser/backend e do `PJSIP Logger`
- `SNGREP` puro continua limitado para conteúdo TLS se o binário/ambiente não estiver preparado
- o parser de eventos SIP é best-effort

## Próximos passos futuros

- `xterm.js` + PTY/WebSocket
- exportação de `pcap`
- timeline unificada `SNGREP + PJSIP`
- filtros mais finos por diálogo/canal
- retenção e histórico operacional das sessões
