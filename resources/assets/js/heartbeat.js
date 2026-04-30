document.addEventListener('DOMContentLoaded', () => {
    const baseUrl = document.querySelector('meta[name="base-url"]')?.getAttribute('content') || '';

    window.notyf = window.notyf || new Notyf({
        duration: 4000,
        ripple: false,
        position: { x: 'right', y: 'top' },
    });
    const notyf = window.notyf;

    let heartbeatIntervalId = null;
    let inactivityTimer = null;

    const HEARTBEAT_INTERVAL = 2 * 60 * 1000;   // 2 minutos
    const INACTIVITY_LIMIT = 30 * 60 * 1000;    // 30 minutos

    function saveSessionState() {
        const state = {
            lastRoute: window.location.pathname + window.location.search + window.location.hash,
            timestamp: Date.now()
        };

        localStorage.setItem('app_session_state', JSON.stringify(state));
    }

    function clearSessionState() {
        localStorage.removeItem('app_session_state');
        localStorage.removeItem('agente_sessao');
        sessionStorage.clear();
    }

    async function restoreSessionState() {
        const raw = localStorage.getItem('app_session_state');
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
                saveSessionState();
                return true;
            }

            if (data.status === 419 || data.status === 401) {
                clearInterval(heartbeatIntervalId);
                clearTimeout(inactivityTimer);
                clearSessionState();

                notyf.error(data.message || 'Sessão expirada! Redirecionando...');
                setTimeout(() => {
                    window.location.href = `${baseUrl}/login`;
                }, 1500);

                return false;
            }

            notyf.error(data.message || 'Erro ao manter a sessão ativa.');
            return false;

        } catch (error) {
            notyf.error('Erro de conexão ao verificar sessão.');
            return false;
        }
    }

    async function logoutForInactivity() {
        clearInterval(heartbeatIntervalId);
        clearTimeout(inactivityTimer);

        try {
            await fetch(`${baseUrl}/dashboard/logout`, {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json',
                },
            });
        } catch (e) {
            console.error('Erro ao encerrar sessão por inatividade:', e);
        }

        clearSessionState();

        notyf.error('Sessão expirada por inatividade. Redirecionando...');
        setTimeout(() => {
            window.location.href = `${baseUrl}/login`;
        }, 1500);
    }

    function resetInactivityTimer() {
        clearTimeout(inactivityTimer);
        inactivityTimer = setTimeout(logoutForInactivity, INACTIVITY_LIMIT);
    }

    ['mousemove', 'keydown', 'mousedown', 'touchstart', 'click', 'scroll'].forEach(event => {
        document.addEventListener(event, () => {
            resetInactivityTimer();
            saveSessionState();
        }, { passive: true });
    });

    window.addEventListener('beforeunload', () => {
        saveSessionState();
    });

    (async () => {
        const ok = await keepSessionAlive();

        if (!ok) return;

        await restoreSessionState();
        resetInactivityTimer();

        heartbeatIntervalId = setInterval(async () => {
            const valid = await keepSessionAlive();
            if (!valid) {
                clearInterval(heartbeatIntervalId);
            }
        }, HEARTBEAT_INTERVAL);
    })();
});
