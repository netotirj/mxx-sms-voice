// TermsModal.js
// Controle de aceite de Termos no registro Tailwind
// Upload Modal integrado
// Autor: Maxx Solutions | 2025

document.addEventListener('DOMContentLoaded', () => {
    const TERMS_KEY = 'acceptedTerms';
    const notyf = new Notyf({
        duration: 5000,
        position: { x: 'right', y: 'top' },
    });

    // ================= Modal de Termos =======================
    const btnRegister = document.getElementById('btnRegister');
    const modal = document.getElementById('modalTermos');
    const modalContent = document.getElementById('termosContent');
    const btnAccept = document.getElementById('btnAceitarTermos');
    const btnRecusar = document.getElementById('btnRecusarTermos');

    if (!btnRegister || !modal || !modalContent || !btnAccept || !btnRecusar) {
        console.error('TermsModal.js: Elementos necessários não encontrados no DOM.');
        return;
    }

    // Habilita botão "Aceitar" ao rolar até o fim dos termos
    modalContent.addEventListener('scroll', () => {
        if (modalContent.scrollTop + modalContent.clientHeight >= modalContent.scrollHeight - 5) {
            btnAccept.disabled = false;
        }
    });

    // Ao clicar em Registrar, verifica se os termos foram aceitos
    btnRegister.addEventListener('click', (e) => {
        if (!localStorage.getItem(TERMS_KEY)) {
            e.preventDefault();
            modal.classList.remove('hidden');
            modal.classList.add('flex');
            notyf.info('Leia os termos e role até o final para aceitar.');
            return;
        }
        enviarCadastro();
    });

    // Aceitar Termos
    btnAccept.addEventListener('click', () => {
        localStorage.setItem(TERMS_KEY, 'true');
        modal.classList.add('hidden');
        modal.classList.remove('flex');
        notyf.success('Termos aceitos. Você pode se cadastrar agora.');
    });

    // Recusar Termos
    btnRecusar.addEventListener('click', () => {
        modal.classList.add('hidden');
        modal.classList.remove('flex');
        notyf.error('Você precisa aceitar os termos para prosseguir.');
    });

    // Enviar cadastro
    function enviarCadastro() {
        const name = document.querySelector('input[placeholder="Nome"]').value.trim();
        const lastname = document.querySelector('input[placeholder="Sobrenome"]').value.trim();
        const phone = document.querySelector('input[placeholder="Telefone"]').value.trim();
        const email = document.querySelector('input[placeholder="E-mail"]').value.trim();
        const password = document.querySelector('input[placeholder="Crie sua Senha"]').value.trim();
        const confirmPass = document.querySelector('input[placeholder="Confirme a Senha"]').value.trim();
        const captcha = document.querySelector('[name="g-recaptcha-response"]').value;
        const termsAccepted = document.querySelector('#customCheckc1').checked;
        const baseUrl = document.querySelector('meta[name="base-url"]').getAttribute('content');

        if (!termsAccepted) {
            notyf.error('Você deve aceitar os Termos e Condições.');
            return;
        }
        if (!name || !lastname || !phone || !email || !password || !confirmPass) {
            notyf.error('Preencha todos os campos obrigatórios.');
            return;
        }
        if (password !== confirmPass) {
            notyf.error('As senhas não coincidem.');
            return;
        }

        btnRegister.disabled = true;
        btnRegister.querySelector('.btn-text').classList.add('hidden');
        btnRegister.querySelector('.btn-spinner').classList.remove('hidden');

        const dataPost = new FormData();
        dataPost.append('name', name);
        dataPost.append('lastname', lastname);
        dataPost.append('phone', phone);
        dataPost.append('email', email);
        dataPost.append('password', password);
        dataPost.append('g-recaptcha-response', captcha);
        dataPost.append('accept_terms', 'on');

        fetch(`${baseUrl}/register`, {
            method: 'POST',
            body: dataPost
        })
        .then(response => response.json())
        .then(data => {
            if (data.status === 'OK') {
                notyf.success(data.message || 'Cadastro realizado com sucesso!');
                setTimeout(() => {
                    window.location.href = `${baseUrl}/dashboard`;
                }, 1500);
            } else {
                notyf.error(data.message || 'Erro ao realizar cadastro.');
            }
        })
        .catch(error => {
            console.error('Erro:', error);
            notyf.error('Erro de conexão ou servidor.');
        })
        .finally(() => {
            btnRegister.disabled = false;
            btnRegister.querySelector('.btn-text').classList.remove('hidden');
            btnRegister.querySelector('.btn-spinner').classList.add('hidden');
        });
    }

    // ================= Modal de Upload ========================
    window.openUploadModal = function (id) {
        console.log('Abrir modal upload para campanha', id);
        document.getElementById('campaignId').value = id;
        ModalTerms.open('uploadModal');
    };

    const uploadForm = document.getElementById('uploadForm');
    if (uploadForm) {
        uploadForm.addEventListener('submit', function (e) {
            e.preventDefault();

            const formData = new FormData(this);
            const campaignId = formData.get('campaignId');
            console.log('Enviando arquivo para a campanha', campaignId);

            fetch('/upload-endpoint.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                notyf.success('Upload realizado com sucesso!');
                ModalTerms.close('uploadModal');
                this.reset();
            })
            .catch(error => {
                console.error('Erro no upload:', error);
                notyf.error('Erro ao enviar o arquivo.');
            });
        });
    } else {
        console.warn('Upload form não encontrado no DOM.');
    }
});

// ================= ModalTerms Helper =======================
const ModalTerms = (function () {
    "use strict";
    return {
        open: function (modalId) {
            const modalEl = document.getElementById(modalId);
            if (!modalEl) {
                console.error(`Modal com id ${modalId} não encontrado.`);
                return;
            }
            let modalInstance = bootstrap.Modal.getInstance(modalEl);
            if (!modalInstance) {
                modalInstance = new bootstrap.Modal(modalEl);
            }
            modalInstance.show();
        },
        close: function (modalId) {
            const modalEl = document.getElementById(modalId);
            if (!modalEl) {
                console.error(`Modal com id ${modalId} não encontrado.`);
                return;
            }
            const modalInstance = bootstrap.Modal.getInstance(modalEl);
            if (modalInstance) {
                modalInstance.hide();
            }
        }
    };
})();







/*document.addEventListener('DOMContentLoaded', () => {
    const TERMS_KEY = 'acceptedTerms';

    const notyf = new Notyf({
        duration: 5000,
        position: { x: 'right', y: 'top' },

    });

    const btnRegister = document.getElementById('btnRegister');
    const modal = document.getElementById('modalTermos');
    const modalContent = document.getElementById('termosContent');
    const btnAccept = document.getElementById('btnAceitarTermos');
    const btnRecusar = document.getElementById('btnRecusarTermos');

    if (!btnRegister || !modal || !modalContent || !btnAccept || !btnRecusar) {
        console.error('TermsModal.js: Elementos necessários não encontrados no DOM.');
        return;
    }

    // Habilita botão "Aceitar" ao rolar até o fim dos termos
    modalContent.addEventListener('scroll', () => {
        if (modalContent.scrollTop + modalContent.clientHeight >= modalContent.scrollHeight - 5) {
            btnAccept.disabled = false;
        }
    });

    // Ao clicar em Registrar, verifica se os termos foram aceitos
    btnRegister.addEventListener('click', (e) => {
        if (!localStorage.getItem(TERMS_KEY)) {
            e.preventDefault();
            modal.classList.remove('hidden');
            modal.classList.add('flex');
            notyf.open({
                type: 'success',
                message: 'Leia os termos e role até o final para aceitar.'
            });
            return;
        }
        enviarCadastro();
    });

    // Aceita os termos, salva no localStorage e fecha o modal
    btnAccept.addEventListener('click', () => {
        localStorage.setItem(TERMS_KEY, 'true');
        modal.classList.add('hidden');
        modal.classList.remove('flex');
        notyf.success('Termos aceitos. Você pode se cadastrar agora.');
    });

    // Recusa os termos, fecha o modal e exibe aviso
    btnRecusar.addEventListener('click', () => {
        modal.classList.add('hidden');
        modal.classList.remove('flex');
        notyf.error('Você precisa aceitar os termos para prosseguir.');
    });

    // Envia os dados do formulário de cadastro
    function enviarCadastro() {
        const name = document.querySelector('input[placeholder="Nome"]').value.trim();
        const lastname = document.querySelector('input[placeholder="Sobrenome"]').value.trim();
        const email = document.querySelector('input[placeholder="E-mail"]').value.trim();
        const password = document.querySelector('input[placeholder="crie sua Senha"]').value.trim();
        const confirmPass = document.querySelector('input[placeholder="Confirme a senha"]').value.trim();
        const termsAccepted = document.querySelector('#customCheckc1').checked;
        const baseUrl = document.querySelector('meta[name="base-url"]').getAttribute('content');

        if (!termsAccepted) {
            notyf.error('Você deve aceitar os Termos e Condições.');
            return;
        }
        if (!name || !lastname || !email || !password || !confirmPass) {
            notyf.error('Preencha todos os campos obrigatórios.');
            return;
        }
        if (password !== confirmPass) {
            notyf.error('As senhas não coincidem.');
            return;
        }

        btnRegister.disabled = true;
        btnRegister.querySelector('.btn-text').classList.add('hidden');
        btnRegister.querySelector('.btn-spinner').classList.remove('hidden');

        const dataPost = new FormData();
        dataPost.append('name', name);
        dataPost.append('lastname', lastname);
        dataPost.append('email', email);
        dataPost.append('password', password);
        dataPost.append('accept_terms', 'on');

        fetch(`${baseUrl}/register`, {
            method: 'POST',
            body: dataPost
        })
            .then(response => response.json())
            .then(data => {
                if (data.status === 'OK') {
                    notyf.success(data.message || 'Cadastro realizado com sucesso!');
                    setTimeout(() => {
                        window.location.href = `${baseUrl}/dashboard`;
                    }, 1500);
                } else {
                    notyf.error(data.message || 'Erro ao realizar cadastro.');
                }
            })
            .catch(error => {
                console.error('Erro:', error);
                notyf.error('Erro de conexão ou servidor.');
            })
            .finally(() => {
                btnRegister.disabled = false;
                btnRegister.querySelector('.btn-text').classList.remove('hidden');
                btnRegister.querySelector('.btn-spinner').classList.add('hidden');
            });
    }

    
});




/*
    document.getElementById('uploadForm').addEventListener('submit', function (e) {
    e.preventDefault();

    const formData = new FormData(this);
    const campaignId = formData.get('campaignId');

    const xhr = new XMLHttpRequest();
    xhr.open('POST', '/upload-endpoint.php'); // ajuste para sua rota de upload

    const progressBar = document.getElementById('uploadProgressBar');

    xhr.upload.addEventListener('progress', function (e) {
        if (e.lengthComputable) {
            const percentComplete = Math.round((e.loaded / e.total) * 100);
            progressBar.style.width = percentComplete + '%';
            progressBar.setAttribute('aria-valuenow', percentComplete);
            progressBar.textContent = percentComplete + '%';
        }
    });

    xhr.onload = function () {
        if (xhr.status === 200) {
            try {
                const response = JSON.parse(xhr.responseText);
                notyf.success('Upload realizado com sucesso!');
                ModalTerms.close('uploadModal');
                progressBar.style.width = '0%';
                progressBar.setAttribute('aria-valuenow', 0);
                progressBar.textContent = '0%';
                document.getElementById('uploadForm').reset();
            } catch (e) {
                console.error('Erro ao processar resposta JSON', e);
                notyf.error('Erro inesperado ao processar o upload.');
            }
        } else {
            console.error('Erro no upload', xhr.responseText);
            notyf.error('Erro ao enviar o arquivo.');
        }
    };

    xhr.onerror = function () {
        console.error('Erro de rede ao enviar arquivo.');
        notyf.error('Erro de rede no upload.');
    };

    xhr.send(formData);
});

*/