<?php

require __DIR__ . '/vendor/autoload.php';

use App\Model\Entity\SipMonitorSession;
use App\Service\SipMonitorCommandRunner;
use App\Service\SipMonitorSessionService;

$forceAll = in_array('--all', $argv ?? [], true);

SipMonitorSessionService::cleanupExpiredSessions();

if ($forceAll) {
    foreach (SipMonitorSession::listRecent(100) as $session) {
        if (in_array((string)($session['status'] ?? ''), ['starting', 'running'], true)) {
            SipMonitorSessionService::stop((int)$session['id'], null, 'emergency');
        }
    }

    SipMonitorCommandRunner::run('asterisk -rx "pjsip set logger off" 2>&1 || true', true);
    echo "Sessões SIP ativas encerradas.\n";
    exit(0);
}

echo "Sessões expiradas limpas.\n";
