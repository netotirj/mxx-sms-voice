/**
 * camp.js
 * Gerenciamento de ações de campanha:
 * - Editar campanha
 * - Upload de arquivos com barra de progresso
 * - Formulário de criação de campanha
 * - Contador de caracteres
 */

// Define base URL a partir de meta tag no HTML
const baseUrl = (document.querySelector('meta[name="base-url"]')?.getAttribute('content') || '').replace(/\/$/, '');

document.addEventListener('DOMContentLoaded', () => {
    const uploadModal = document.getElementById('uploadModal');
    if (uploadModal && uploadModal.parentElement !== document.body) {
        document.body.appendChild(uploadModal);
    }
});

// Instancia notyf globalmente para notificações
window.notyf = window.notyf || new Notyf({
    duration: 4000,
    ripple: false,
    position: { x: 'right', y: 'top' },
    types: [
        { type: 'success', backgroundColor: '#10b981', icon: { className: 'notyf__icon--success', tagName: 'i' } },
        { type: 'error', backgroundColor: '#e11d48', icon: { className: 'notyf__icon--error', tagName: 'i' } },
        { type: 'info', className: 'notyf__toast--info', backgroundColor: '#334155', icon: false }
    ]
});

async function parseJsonResponse(response) {
    const text = await response.text();
    if (!text) return {};

    try {
        return JSON.parse(text);
    } catch (error) {
        console.error('Resposta não JSON:', text);
        return {
            status: response.status,
            message: text.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim() || 'Resposta inválida do servidor.'
        };
    }
}

function showSuccess(message) {
    notyf.success(message);
}

function showError(message) {
    notyf.error(message);
}

/* ==================== UPLOAD DE ARQUIVOS COM PROGRESS ==================== */
const uploadForm = document.getElementById('uploadForm');
if (uploadForm) {
    uploadForm.addEventListener('submit', function (e) {
        e.preventDefault();

        const formData = new FormData(uploadForm);
        const xhr = new XMLHttpRequest();
        const progressBar = document.getElementById('uploadProgressBar');
        const progressText = document.getElementById('uploadProgressText');
        const submitButton = uploadForm.querySelector('button[type="submit"]');
        submitButton.disabled = true;

        xhr.upload.addEventListener('progress', function (event) {
            if (event.lengthComputable && progressBar) {
                const percent = Math.round((event.loaded / event.total) * 100);
                progressBar.style.width = percent + '%';
                if (progressText) progressText.textContent = percent + '%';
            }
        });

        xhr.open('POST', `${baseUrl}/campaign/upload`);
        xhr.responseType = 'json';

        xhr.onload = function () {
            submitButton.disabled = false;
            let response = xhr.response;

            // Tenta fazer o parse caso venha string
            if (typeof response === 'string') {
                try {
                    response = JSON.parse(response);
                } catch (e) {
                showError('Erro ao processar resposta do servidor.');
                    resetUploadProgress();
                    return;
                }
            }

            if (xhr.status === 200 && response.status === 200) {
                showSuccess(response.message || 'Upload realizado com sucesso!');
                closeUploadModal();
                refreshCampaigns();
            } else {
                showError(response.message || 'Erro no upload.');
                resetUploadProgress();
            }
        };

        xhr.onerror = function () {
            submitButton.disabled = false;
            showError('Erro ao enviar o arquivo.');
            resetUploadProgress();
        };

        xhr.send(formData);
    });
}

/* =============== MODAL DE UPLOAD =============== */
document.addEventListener('click', function (event) {
    const uploadButton = event.target.closest('.btn-upload');
    if (!uploadButton) return;

    const campaignId = uploadButton.dataset.id;
    document.getElementById('campaignId').value = campaignId;

    const modal = document.getElementById('uploadModal');
    if (!modal) return;

    const modalContent = document.getElementById('uploadModalContent');

    modal.classList.remove('hidden');
    modal.classList.add('flex');
    modalContent.classList.remove('scale-95', 'opacity-0');
    modalContent.classList.add('scale-100', 'opacity-100');

    setTimeout(() => document.getElementById('fileInput')?.focus(), 50);
});

function closeUploadModal() {
    const modal = document.getElementById('uploadModal');
    const modalContent = document.getElementById('uploadModalContent');
    if (!modal || !modalContent) return;

    modalContent.classList.add('scale-95', 'opacity-0');
    modalContent.classList.remove('scale-100', 'opacity-100');

    setTimeout(() => {
        modal.classList.add('hidden');
        modal.classList.remove('flex');
        resetUploadProgress();
        uploadForm?.reset();
        setUploadFileName('');
    }, 200);
}
window.closeUploadModal = closeUploadModal;

document.getElementById('uploadModal')?.addEventListener('click', e => {
    if (e.target.id === 'uploadModal') closeUploadModal();
});

document.addEventListener('keydown', e => {
    if (e.key === 'Escape') closeUploadModal();
});

function resetUploadProgress() {
    const progressBar = document.getElementById('uploadProgressBar');
    if (progressBar) {
        progressBar.style.width = '0%';
    }
    const progressText = document.getElementById('uploadProgressText');
    if (progressText) progressText.textContent = '0%';
}

function setUploadFileName(name) {
    const fileName = document.getElementById('uploadFileName');
    if (fileName) fileName.textContent = name || 'Clique para selecionar o arquivo';
}

document.getElementById('fileInput')?.addEventListener('change', (event) => {
    setUploadFileName(event.target.files?.[0]?.name || '');
});

function refreshCampaigns() {
    if (typeof window.refreshCampaignTable === 'function') {
        Promise.resolve(window.refreshCampaignTable()).catch((error) => {
            console.error('Erro ao atualizar tabela:', error);
            document.dispatchEvent(new CustomEvent('campaigns:reload'));
        });
        return;
    }

    if (typeof window.loadCampaigns === 'function') {
        Promise.resolve(window.loadCampaigns()).catch((error) => {
            console.error('Erro ao atualizar tabela:', error);
        });
        return;
    }

    document.dispatchEvent(new CustomEvent('campaigns:reload'));
}

/*Spinner dos botoes*/
function toggleButtonSpinner(button, show = true, minVisibleMs = 1000) {
    const spinner = button.find('.icon-spinner');
    const others = button.find('[class^="icon-"]:not(.icon-spinner)');

    if (show) {
        button.prop('disabled', true);
        spinner.removeClass('hidden');
        others.addClass('hidden');
        // Marca o tempo de início
        button.data('spinner-start', Date.now());
    } else {
        const elapsed = Date.now() - (button.data('spinner-start') || 0);
        const delay = Math.max(minVisibleMs - elapsed, 0);
        setTimeout(() => {
            button.prop('disabled', false);
            spinner.addClass('hidden');
            others.removeClass('hidden');
        }, delay);
    }
}

// camp.js
document.addEventListener("DOMContentLoaded", () => {
    const form = document.getElementById("smsCampaignForm");
    const newButton = document.getElementById("createButton");

    const senderInput = document.getElementById("senderInput");
    const charsetInput = document.getElementById("charsetInput");

    if (!form || !newButton) return;

    // Ativa grupos de botões
    function activateGroup(groupId, hiddenInput) {
        const buttons = document.querySelectorAll(`#${groupId} .option-btn`);
        if (!buttons.length || !hiddenInput) return;

        buttons.forEach(btn => {
            btn.addEventListener("click", () => {
                buttons.forEach(b => {
                    b.classList.remove("bg-blue-600", "text-white");
                    b.classList.add("bg-white", "hover:bg-gray-50", "text-gray-700");
                    b.dataset.active = "false";
                });

                btn.classList.add("bg-blue-600", "text-white");
                btn.classList.remove("bg-white", "hover:bg-gray-50", "text-gray-700");
                btn.dataset.active = "true";

                hiddenInput.value = btn.dataset.value;

                if (groupId === "group2") {
                    window.setCharsetLimit?.(btn.dataset.value);
                }
            });
        });

        // Seleciona o botão ativo ou default
        const activeBtn = Array.from(buttons).find(b => b.dataset.active === "true") || buttons[0];
        activeBtn.classList.add("bg-blue-600", "text-white");
        hiddenInput.value = activeBtn.dataset.value;

        if (groupId === "group2") {
            window.setCharsetLimit?.(activeBtn.dataset.value);
        }
    }

    activateGroup("group1", senderInput);
    activateGroup("group2", charsetInput);

    // Form submit
    form.addEventListener("submit", async (e) => {
        e.preventDefault();
        newButton.disabled = true;

        const btnText = document.getElementById("createButtonText");
        const btnLoader = document.getElementById("createButtonLoader");
        btnText.textContent = "Enviando...";
        btnLoader.classList.remove("hidden");

        try {
            const formData = new FormData(form);
            const response = await fetch(`${baseUrl}/campaign/new`, { method: "POST", body: formData });
            const result = await response.json();

            if (response.ok) {
                showSuccess(result.message || "Campanha criada com sucesso!");
                setTimeout(() => window.location.href = `${baseUrl}/campaign`, 900);
            } else {
                showError(result.message || "Erro ao criar campanha.");
            }
        } catch (err) {
            console.error(err);
            showError("Erro de conexão ao enviar a campanha.");
        } finally {
            newButton.disabled = false;
            btnText.textContent = "Criar";
            btnLoader.classList.add("hidden");
        }
    });
});

document.addEventListener("DOMContentLoaded", () => {
    const form = document.getElementById("smsCampaignFormEdit");
    const updateButton = document.getElementById("updateButton");
    const campaignId = document.getElementById("campaignIdInput")?.value;
    const currentSender = form?.dataset.sender || 'short';
    const currentCharset = form?.dataset.charset || '0';
    const currentStatus = form?.dataset.status || 'y';

    if (!form || !updateButton) return;

    // Variáveis para armazenar valores selecionados
    let selectedSender = null;
    let selectedCharset = null;

    // Função para ativar grupo de botões
    function activateGroup(groupId, setValueCallback) {
        const buttons = document.querySelectorAll(`#${groupId} .option-btn`);
        if (!buttons.length) return;

        buttons.forEach(btn => {
            btn.addEventListener("click", (e) => {
                e.preventDefault(); // evita submit acidental

                // Desativa todos
                buttons.forEach(b => {
                    b.classList.remove("bg-blue-600", "text-white");
                    b.classList.add("bg-white", "hover:bg-gray-50", "text-gray-700");
                    b.dataset.active = "false";
                });

                // Ativa o clicado
                btn.classList.add("bg-blue-600", "text-white");
                btn.classList.remove("bg-white", "hover:bg-gray-50", "text-gray-700");
                btn.dataset.active = "true";

                // Atualiza valor selecionado
                setValueCallback(btn.dataset.value);

                // Se for charset, atualiza limite
                if (groupId === "group2Edit") {
                    window.setCharsetLimitEdit?.(btn.dataset.value);
                }
            });
        });

        // Seleciona botão ativo inicial
        const desiredValue = groupId === "group1Edit" ? currentSender : currentCharset;
        const activeBtn = Array.from(buttons).find(b => b.dataset.value === desiredValue) || buttons[0];
        activeBtn.classList.add("bg-blue-600", "text-white");
        activeBtn.classList.remove("bg-white", "hover:bg-gray-50", "text-gray-700");
        activeBtn.dataset.active = "true";
        setValueCallback(activeBtn.dataset.value);

        if (groupId === "group2Edit") {
            window.setCharsetLimitEdit?.(activeBtn.dataset.value);
        }
    }

    // Ativa os grupos
    activateGroup("group1Edit", (val) => selectedSender = val);
    activateGroup("group2Edit", (val) => selectedCharset = val);
    const statusEdit = document.getElementById("statusEdit");
    if (statusEdit) statusEdit.value = currentStatus;

    // Submit
    form.addEventListener("submit", async (e) => {
        e.preventDefault();
        updateButton.disabled = true;

        const btnText = document.getElementById("updateButtonText");
        const btnLoader = document.getElementById("updateButtonLoader");
        btnText.textContent = "Atualizando...";
        btnLoader.classList.remove("hidden");

        try {
            // Cria FormData manualmente incluindo os valores dos botões
            const formData = new FormData(form);
            formData.set("sender", selectedSender);
            formData.set("charset", selectedCharset);

            const response = await fetch(`${baseUrl}/campaign/${campaignId}/edit`, {
                method: "POST",
                body: formData
            });
            const result = await response.json();

            if (response.ok) {
                showSuccess(result.message || "Campanha atualizada com sucesso!");
                setTimeout(() => window.location.href = `${baseUrl}/campaign`, 900);
            } else {
                showError(result.message || "Erro ao atualizar campanha.");
            }
        } catch (err) {
            console.error(err);
            showError("Erro de conexão ao atualizar a campanha.");
        } finally {
            updateButton.disabled = false;
            btnText.textContent = "Atualizar";
            btnLoader.classList.add("hidden");
        }
    });
});





/* ======================= BOTÃO EDITAR======================= */
$(document).on('click', '.btn-edit', function () {
    const campaignId = $(this).data('id');
    window.location.href = `${baseUrl}/campaign/${campaignId}/edit`;
});
/* ======================= BOTÃO ENVIAR======================= */
$(document).on('click', '.btn-send', function () {
    const button = $(this);
    const campaignId = button.data('id');

    if (!campaignId) return showError('ID da campanha não encontrado.');

    const runSend = () => {
        toggleButtonSpinner(button, true);

        fetch(`${baseUrl}/campaign/${campaignId}/send`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' }
        })
            .then(async res => {
                const data = await parseJsonResponse(res);
                if (res.ok && Number(data.status || res.status) === 200) {
                    showSuccess(data.message || 'Campanha enviada com sucesso!');
                    refreshCampaigns();
                } else {
                    const msg = data.message || 'Erro ao enviar campanha.';
                    showError(msg);
                    if (data.balance !== undefined && data.necessary !== undefined) {
                        notyf.error(`Saldo atual: R$ ${parseFloat(data.balance).toFixed(2)}`);
                        notyf.error(`Necessário: R$ ${parseFloat(data.necessary).toFixed(2)}`);
                    }
                }
            })
            .catch(() => {
                showError('Erro na comunicação com o servidor.');
            })
            .finally(() => {
                toggleButtonSpinner(button, false);
            });
    };

    runSend();
});




/* ======================= BOTÃO DELETAR (em branco por enquanto) ======================= */
$(document).on('click', '.btn-delete', function () {
    const button = $(this);
    const campaignId = button.data('id');
    const campaignName = button.data('name');

    if (!campaignId) return showError('ID da campanha não encontrado.');

    const removeCampaign = () => {
        toggleButtonSpinner(button, true);

        fetch(`${baseUrl}/campaign/${campaignId}/delete`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' }
        })
            .then(async res => {
                const data = await parseJsonResponse(res);
                if (res.ok && Number(data.status || res.status) === 200) {
                    showSuccess(data.message || 'Campanha excluída com sucesso!');
                    refreshCampaigns();
                } else {
                    showError(data.message || 'Erro ao excluir campanha.');
                }
            })
            .catch(err => {
                console.error('Erro ao excluir:', err);
                showError('Erro de conexão ao excluir.');
            })
            .finally(() => {
                toggleButtonSpinner(button, false);
            });
    };

    if (window.Swal) {
        Swal.fire({
            title: 'Excluir campanha?',
            text: `A campanha "${campaignName}" será removida.`,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Excluir',
            cancelButtonText: 'Cancelar',
            confirmButtonColor: '#e11d48',
            cancelButtonColor: '#64748b'
        }).then(result => {
            if (result.isConfirmed) removeCampaign();
        });
        return;
    }

    if (confirm(`Tem certeza que deseja excluir a campanha "${campaignName}"?`)) {
        removeCampaign();
    }

});
