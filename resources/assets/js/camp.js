/**
 * camp.js
 * Gerenciamento de ações de campanha:
 * - Editar campanha
 * - Upload de arquivos com barra de progresso
 * - Formulário de criação de campanha
 * - Contador de caracteres
 */

// Define base URL a partir de meta tag no HTML
const baseUrl = document.querySelector('meta[name="base-url"]')?.getAttribute('content') || '';

// Instancia notyf globalmente para notificações
window.notyf = new Notyf({ duration: 4000, position: { x: 'right', y: 'top' } });

/* ==================== UPLOAD DE ARQUIVOS COM PROGRESS ==================== */
const uploadForm = document.getElementById('uploadForm');
if (uploadForm) {
    uploadForm.addEventListener('submit', function (e) {
        e.preventDefault();

        const formData = new FormData(uploadForm);
        const xhr = new XMLHttpRequest();
        const progressBar = document.getElementById('uploadProgressBar');
        const submitButton = uploadForm.querySelector('button[type="submit"]');
        submitButton.disabled = true;

        xhr.upload.addEventListener('progress', function (event) {
            if (event.lengthComputable && progressBar) {
                const percent = Math.round((event.loaded / event.total) * 100);
                progressBar.style.width = percent + '%';
                progressBar.textContent = percent + '%';
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
                    notyf.error('Erro ao processar resposta do servidor.');
                    resetUploadProgress();
                    return;
                }
            }

            if (xhr.status === 200 && response.status === 200) {
                notyf.success(response.message || 'Upload realizado com sucesso!');
                closeUploadModal();
                if (typeof reloadCampaignTable === 'function') reloadCampaignTable();
            } else {
                notyf.error(response.message || 'Erro no upload.');
                resetUploadProgress();
            }
        };

        xhr.onerror = function () {
            submitButton.disabled = false;
            notyf.error('Erro ao enviar o arquivo.');
            resetUploadProgress();
        };

        xhr.send(formData);
    });
}

/* =============== MODAL DE UPLOAD =============== */
$('#sorting-table tbody').on('click', '.btn-upload', function () {
    const campaignId = $(this).data('id');
    document.getElementById('campaignId').value = campaignId;

    const modal = document.getElementById('uploadModal');
    const modalContent = document.getElementById('uploadModalContent');
    const overlay = modal.querySelector('.fixed'); // O fundo escurecido

    // Exibir o modal e fundo
    modal.classList.remove('hidden');
    overlay.classList.remove('opacity-0');
    overlay.classList.add('opacity-50');
    modalContent.classList.remove('scale-95', 'opacity-0');
    modalContent.classList.add('scale-100', 'opacity-100');

    setTimeout(() => document.getElementById('fileInput')?.focus(), 50);
});

function closeUploadModal() {
    const modal = document.getElementById('uploadModal');
    const modalContent = document.getElementById('uploadModalContent');
    const overlay = modal.querySelector('.fixed'); // O fundo escurecido

    // Fechar o modal e fundo
    modalContent.classList.add('scale-95', 'opacity-0');
    modalContent.classList.remove('scale-100', 'opacity-100');
    overlay.classList.add('opacity-0'); // Ocultar o fundo

    // Esperar a transição para remover o modal da tela e garantir que o conteúdo seja acessível
    setTimeout(() => {
        modal.classList.add('hidden');
        overlay.classList.remove('opacity-50'); // Remover a opacidade do fundo
        resetUploadProgress();
        uploadForm?.reset();
    }, 200);
}

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
        progressBar.textContent = '0%';
    }
}

function reloadCampaignTable() {
    if ($.fn.DataTable.isDataTable('#sorting-table')) {
        $('#sorting-table').DataTable().ajax.reload(null, false);
    }
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
                    const newLimit = (btn.dataset.value === "0") ? 160 : 70;
                    window.setCharsetLimit?.(newLimit);
                }
            });
        });

        // Seleciona o botão ativo ou default
        const activeBtn = Array.from(buttons).find(b => b.dataset.active === "true") || buttons[0];
        activeBtn.classList.add("bg-blue-600", "text-white");
        hiddenInput.value = activeBtn.dataset.value;

        if (groupId === "group2") {
            const newLimit = (activeBtn.dataset.value === "0") ? 160 : 70;
            window.setCharsetLimit?.(newLimit);
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
                notyf.success(result.message || "Campanha criada com sucesso!");
                setTimeout(() => window.location.href = `${baseUrl}/campaign`, 1500);
            } else {
                notyf.error(result.message || "Erro ao criar campanha.");
            }
        } catch (err) {
            console.error(err);
            notyf.error("Erro de conexão ao enviar a campanha.");
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
                    const newLimit = (btn.dataset.value === "0") ? 160 : 70;
                    window.setCharsetLimitEdit?.(newLimit);
                }
            });
        });

        // Seleciona botão ativo inicial
        const activeBtn = Array.from(buttons).find(b => b.dataset.active === "true") || buttons[0];
        activeBtn.classList.add("bg-blue-600", "text-white");
        setValueCallback(activeBtn.dataset.value);

        if (groupId === "group2Edit") {
            const newLimit = (activeBtn.dataset.value === "0") ? 160 : 70;
            window.setCharsetLimitEdit?.(newLimit);
        }
    }

    // Ativa os grupos
    activateGroup("group1Edit", (val) => selectedSender = val);
    activateGroup("group2Edit", (val) => selectedCharset = val);

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
                notyf.success(result.message || "Campanha atualizada com sucesso!");
                setTimeout(() => window.location.href = `${baseUrl}/campaign`, 1500);
            } else {
                notyf.error(result.message || "Erro ao atualizar campanha.");
            }
        } catch (err) {
            console.error(err);
            notyf.error("Erro de conexão ao atualizar a campanha.");
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
    window.location.href = `${baseURL}/campaign/${campaignId}/edit`;
});
/* ======================= BOTÃO ENVIAR======================= */
$(document).on('click', '.btn-send', function () {
    const button = $(this);
    const campaignId = button.data('id');

    if (!campaignId) return alert('ID da campanha não encontrado.');

    toggleButtonSpinner(button, true);

    fetch(`${baseUrl}/campaign/${campaignId}/send`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' }
    })
        .then(async res => {
            const data = await res.json();
            if (data.status === 200) {
                notyf.success(data.message || 'Campanha enviada com sucesso!');
                reloadCampaignTable();
                //window.location.href = `${baseURL}/reports`;
                setTimeout(() => {
                    window.location.href = `${baseURL}/reports`;
                }, 4000); // 2000ms = 2s

            } else {
                let msg = data.message || 'Erro ao enviar campanha.';
                notyf.error(msg);
                if (data.balance !== undefined && data.necessary !== undefined) {
                    notyf.error(`Saldo atual: R$ ${parseFloat(data.balance).toFixed(2)}`);
                    notyf.error(`Necessário: R$ ${parseFloat(data.necessary).toFixed(2)}`);
                }

            }
        })
        .catch(() => {
            notyf.error('Erro na comunicação com o servidor.');
        })
        .finally(() => {
            toggleButtonSpinner(button, false);
        });
});




/* ======================= BOTÃO DELETAR (em branco por enquanto) ======================= */
$(document).on('click', '.btn-delete', function () {
    const button = $(this);
    const campaignId = button.data('id');
    const campaignName = button.data('name');

    if (!campaignId) return notyf.error('ID da campanha não encontrado.');

    if (confirm(`Tem certeza que deseja excluir a campanha "${campaignName}"?`)) {
        toggleButtonSpinner(button, true);

        fetch(`${baseUrl}/campaign/${campaignId}/delete`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' }
        })
            .then(async res => {
                const data = await res.json();
                if (res.status === 200) {
                    notyf.success(data.message || 'Campanha excluída com sucesso!');
                    reloadCampaignTable();
                } else {
                    notyf.error(data.message || 'Erro ao excluir campanha.');
                }
            })
            .catch(err => {
                console.error('Erro ao excluir:', err);
                notyf.error('Erro de conexão ao excluir.');
            })
            .finally(() => {
                toggleButtonSpinner(button, false);
            });
    }

});
