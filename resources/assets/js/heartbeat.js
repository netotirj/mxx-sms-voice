document.addEventListener('DOMContentLoaded', () => {
    const baseUrl = document.querySelector('meta[name="base-url"]')?.getAttribute('content') || '';
    const SESSION_STATE_KEY = 'app_session_state';
    const LAST_ACTIVITY_KEY = 'app_last_activity_at';

    window.notyf = window.notyf || new Notyf({
        duration: 4000,
        ripple: false,
        position: { x: 'right', y: 'top' },
    });
    const notyf = window.notyf;

    let heartbeatIntervalId = null;
    let inactivityTimer = null;
    let lastHeartbeatAt = 0;
    let lastHeartbeatAttemptAt = 0;
    let logoutInProgress = false;
    let keepSessionAlivePromise = null;
    let lastConnectionErrorToastAt = 0;

    const HEARTBEAT_INTERVAL = 2 * 60 * 1000;   // 2 minutos
    const HEARTBEAT_FAILURE_RETRY = 30 * 1000;  // 30 segundos
    const INACTIVITY_LIMIT = 30 * 60 * 1000;    // 30 minutos
    const CONNECTION_ERROR_TOAST_COOLDOWN = 60 * 1000; // 1 minuto

    function saveSessionState() {
        const state = {
            lastRoute: window.location.pathname + window.location.search + window.location.hash,
            timestamp: Date.now(),
            lastActivityAt: getLastActivityAt()
        };

        localStorage.setItem(SESSION_STATE_KEY, JSON.stringify(state));
    }

    function clearSessionState() {
        localStorage.removeItem(SESSION_STATE_KEY);
        localStorage.removeItem(LAST_ACTIVITY_KEY);
        localStorage.removeItem('agente_sessao');
        sessionStorage.clear();
    }

    function setLastActivityAt(timestamp = Date.now()) {
        localStorage.setItem(LAST_ACTIVITY_KEY, String(timestamp));
    }

    function getLastActivityAt() {
        const raw = localStorage.getItem(LAST_ACTIVITY_KEY);
        const parsed = Number(raw);
        return Number.isFinite(parsed) && parsed > 0 ? parsed : Date.now();
    }

    function markActivity() {
        setLastActivityAt();
        resetInactivityTimer();
        saveSessionState();
        maybeKeepSessionAlive();
    }

    function redirectToLogin(message, useLogoutRoute = false) {
        if (logoutInProgress) return;
        logoutInProgress = true;

        clearInterval(heartbeatIntervalId);
        clearTimeout(inactivityTimer);
        clearSessionState();

        notyf.error(message);
        setTimeout(() => {
            window.location.href = useLogoutRoute
                ? `${baseUrl}/dashboard/logout`
                : `${baseUrl}/login`;
        }, 1500);
    }

    function hasExceededInactivityLimit() {
        return (Date.now() - getLastActivityAt()) >= INACTIVITY_LIMIT;
    }

    function enforceInactivityLimit() {
        if (!hasExceededInactivityLimit()) return false;
        redirectToLogin('Sessão expirada por inatividade. Redirecionando...', true);
        return true;
    }

    async function restoreSessionState() {
        const raw = localStorage.getItem(SESSION_STATE_KEY);
        if (!raw) return;

        try {
            const state = JSON.parse(raw);
            if (!state?.lastRoute) return;

            const current = window.location.pathname + window.location.search + window.location.hash;

            if (
                window.location.pathname === '/dashboard' &&
                state.lastRoute !== current &&
                state.lastRoute !== '/dashboard'
            ) {
                window.location.href = state.lastRoute;
            }
        } catch (e) {
            console.error('Erro ao restaurar estado da sessão:', e);
        }
    }

    async function keepSessionAlive() {
        if (keepSessionAlivePromise) {
            return keepSessionAlivePromise;
        }

        lastHeartbeatAttemptAt = Date.now();

        keepSessionAlivePromise = (async () => {
        try {
            const response = await fetch(`${baseUrl}/users/session`, {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json',
                },
            });

            if (!response.ok) {
                throw new Error(`HTTP error! Status: ${response.status}`);
            }

            const data = await response.json();

            if (data.status === 'OK') {
                lastHeartbeatAt = Date.now();
                saveSessionState();
                return true;
            }

            if (data.status === 419 || data.status === 401) {
                redirectToLogin(data.message || 'Sessão expirada! Redirecionando...');
                return false;
            }

            notyf.error(data.message || 'Erro ao manter a sessão ativa.');
            return false;

        } catch (error) {
            const now = Date.now();
            if ((now - lastConnectionErrorToastAt) >= CONNECTION_ERROR_TOAST_COOLDOWN) {
                lastConnectionErrorToastAt = now;
                notyf.error('Erro de conexão ao verificar sessão.');
            }
            return false;
        } finally {
            keepSessionAlivePromise = null;
        }
        })();

        return keepSessionAlivePromise;
    }

    function maybeKeepSessionAlive(force = false) {
        if (logoutInProgress || enforceInactivityLimit()) {
            return;
        }

        const now = Date.now();
        if (keepSessionAlivePromise) {
            return;
        }

        const lastReferenceAt = Math.max(lastHeartbeatAt, lastHeartbeatAttemptAt);
        const retryWindow = lastHeartbeatAt > 0 ? HEARTBEAT_INTERVAL : HEARTBEAT_FAILURE_RETRY;

        if (!force && (now - lastReferenceAt) < retryWindow) {
            return;
        }

        keepSessionAlive();
    }

    function resetInactivityTimer() {
        clearTimeout(inactivityTimer);
        const remaining = Math.max(0, INACTIVITY_LIMIT - (Date.now() - getLastActivityAt()));
        inactivityTimer = setTimeout(() => {
            enforceInactivityLimit();
        }, remaining);
    }

    ['mousemove', 'keydown', 'mousedown', 'touchstart', 'click', 'scroll'].forEach(event => {
        document.addEventListener(event, () => {
            markActivity();
        }, { passive: true });
    });

    window.addEventListener('beforeunload', () => {
        saveSessionState();
    });

    window.addEventListener('focus', () => {
        if (!enforceInactivityLimit()) {
            maybeKeepSessionAlive(true);
        }
    });

    document.addEventListener('visibilitychange', () => {
        if (!document.hidden && !enforceInactivityLimit()) {
            maybeKeepSessionAlive(true);
        }
    });

    (async () => {
        setLastActivityAt(getLastActivityAt());

        if (enforceInactivityLimit()) return;

        const ok = await keepSessionAlive();

        if (!ok) return;

        await restoreSessionState();
        resetInactivityTimer();

        heartbeatIntervalId = setInterval(async () => {
            if (logoutInProgress || enforceInactivityLimit()) {
                clearInterval(heartbeatIntervalId);
                return;
            }

            const recentlyActive = (Date.now() - getLastActivityAt()) < HEARTBEAT_INTERVAL;
            if (!recentlyActive) {
                return;
            }

            const valid = await keepSessionAlive();
            if (!valid) {
                clearInterval(heartbeatIntervalId);
            }
        }, HEARTBEAT_INTERVAL);
    })();
});
