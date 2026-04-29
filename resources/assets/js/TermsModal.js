document.addEventListener('DOMContentLoaded', () => {
    const modal = document.getElementById('modalTermos');
    const modalContent = document.getElementById('termosContent');
    const btnAccept = document.getElementById('btnAceitarTermos');
    const btnRecusar = document.getElementById('btnRecusarTermos');

    if (!modal || !modalContent || !btnAccept || !btnRecusar) {
        return;
    }

    const enableAccept = () => {
        btnAccept.disabled = false;
        btnAccept.classList.remove('opacity-50');
    };

    modalContent.addEventListener('scroll', () => {
        if (modalContent.scrollTop + modalContent.clientHeight >= modalContent.scrollHeight - 5) {
            enableAccept();
        }
    });

    if (modalContent.scrollHeight <= modalContent.clientHeight + 5) {
        enableAccept();
    }
});

window.ModalTerms = {
    open(modalId) {
        const modal = document.getElementById(modalId);
        if (!modal) {
            return;
        }

        modal.classList.remove('hidden');
        modal.classList.add('flex');
    },

    close(modalId) {
        const modal = document.getElementById(modalId);
        if (!modal) {
            return;
        }

        modal.classList.add('hidden');
        modal.classList.remove('flex');
    }
};
