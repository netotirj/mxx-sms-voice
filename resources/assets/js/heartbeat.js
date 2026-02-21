// HEARTBEAT SESSION KEEPER
document.addEventListener('DOMContentLoaded', () => {
    const baseUrl = document.querySelector('meta[name="base-url"]')?.getAttribute('content') || '';
    const notyf = new Notyf({
        duration: 4000,
        position: {x: 'right', y: 'top'},
    });

    let heartbeatIntervalId; // variável global para controle

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
                // Sessão ativa, nada a fazer
            } else if (data.status === 419) {
                clearInterval(heartbeatIntervalId); // interrompe o heartbeat
                notyf.error(data.message || 'Sessão expirada! Redirecionando...');
                setTimeout(() => {
                    window.location.href = `${baseUrl}/login`;
                }, 5000);
            } else if (data.status === 401) {
                clearInterval(heartbeatIntervalId); // interrompe o heartbeat
                notyf.error(data.message || 'Usuário não autenticado! Redirecionando...');
                setTimeout(() => {
                    window.location.href = `${baseUrl}/login`;
                }, 5000);
            } else {
                notyf.error(data.message || 'Erro ao manter a sessão ativa.');
            }
        } catch (error) {
            //console.error('Erro ao verificar sessão:', error);
            notyf.error('Erro de conexão ao verificar sessão.');
        }
    }

    // Executa ao carregar
    keepSessionAlive().then(r => {

    });

    // Executa a cada 3 minutos (180000 ms)
    heartbeatIntervalId = setInterval(keepSessionAlive, 180000);


    let inactivityTimer;
    const INACTIVITY_LIMIT = 30 * 60 * 1000; // 30 minutos em milissegundos

    function logoutForInactivity() {
        clearInterval(heartbeatIntervalId);
        notyf.error('Sessão expirada por inatividade. Redirecionando...');
        setTimeout(() => {
            window.location.href = `${baseUrl}/dashboard/logout`;
        }, 5000);
    }

    function resetInactivityTimer() {
        clearTimeout(inactivityTimer);
        inactivityTimer = setTimeout(logoutForInactivity, INACTIVITY_LIMIT);
    }

// Escuta qualquer tipo de atividade do usuário
    ['mousemove', 'keydown', 'mousedown', 'touchstart'].forEach(event => {
        document.addEventListener(event, resetInactivityTimer);
    });

// Inicia o timer pela primeira vez
    resetInactivityTimer();


});
