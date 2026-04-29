// emoji.js
import * as picmoPopup from "https://cdn.jsdelivr.net/npm/@picmo/popup-picker@5.8.1/dist/index.js";

/*document.addEventListener("DOMContentLoaded", () => {
    const messageInput = document.getElementById("messageInput");
    const preview = document.getElementById("messagePreview");
    const counter = document.getElementById("counter");
    const warning = document.getElementById("limitWarning");
    const emojiBtn = document.getElementById("emojiBtn");

    if (!messageInput || !preview || !counter) return;

    let charsetLimit = 160; // inicial, será atualizado pelo camp.js

    // Função para atualizar o contador
    function updateCounter() {
        let value = messageInput.value;
        const arr = Array.from(value);

        if (arr.length > charsetLimit) {
            value = arr.slice(0, charsetLimit).join("");
            messageInput.value = value;
        }

        const length = Array.from(value).length;
        const smsCount = Math.ceil(length / charsetLimit) || 1;

        preview.innerText = length > 0 ? value : "Digite uma mensagem 😊";
        counter.textContent = `SMS cobrados: ${smsCount} / Caracteres: ${length} (limite ${charsetLimit})`;

        if (warning) {
            if (length >= charsetLimit) {
                warning.classList.remove("hidden");
                messageInput.classList.add("border", "border-red-500");
            } else {
                warning.classList.add("hidden");
                messageInput.classList.remove("border", "border-red-500");
            }
        }
    }

    // Expor função global para camp.js atualizar charsetLimit
    window.setCharsetLimit = function(limit) {
        charsetLimit = limit;
        updateCounter();
    };

    // Evento input normal
    messageInput.addEventListener("input", updateCounter);

    // Inicializa emoji picker
    if (emojiBtn) {
        const picker = picmoPopup.createPopup(
            { emojiSize: "1.4em", hideOnEmojiSelect: false },
            { referenceElement: emojiBtn, triggerElement: emojiBtn, position: "top-end" }
        );

        picker.addEventListener("emoji:select", (selection) => {
            const emoji = selection.emoji;
            const start = messageInput.selectionStart;
            const end = messageInput.selectionEnd;
            const text = messageInput.value;

            messageInput.value = text.substring(0, start) + emoji + text.substring(end);
            messageInput.selectionStart = messageInput.selectionEnd = start + emoji.length;
            messageInput.focus();
            updateCounter();
        });

        emojiBtn.addEventListener("click", () => picker.toggle());
    }

    // Chamada inicial
    updateCounter();
});

document.addEventListener("DOMContentLoaded", () => {
    // --- Edição ---
    const messageInputEdit = document.getElementById("messageInputEdit");
    const previewEdit = document.getElementById("messagePreviewEdit"); // se tiver
    const counterEdit = document.getElementById("counterEdit");
    const warningEdit = document.getElementById("limitWarningEdit");
    const emojiBtnEdit = document.getElementById("emojiBtnEdit");

    if (messageInputEdit && counterEdit) {
        let charsetLimitEdit = 160; // inicial

        function updateCounterEdit() {
            let value = messageInputEdit.value;
            const arr = Array.from(value);

            if (arr.length > charsetLimitEdit) {
                value = arr.slice(0, charsetLimitEdit).join("");
                messageInputEdit.value = value;
            }

            const length = Array.from(value).length;
            const smsCount = Math.ceil(length / charsetLimitEdit) || 1;

            if (previewEdit) {
                previewEdit.innerText = length > 0 ? value : "Digite uma mensagem 😊";
            }
            counterEdit.textContent = `SMS cobrados: ${smsCount} / Caracteres: ${length} (limite ${charsetLimitEdit})`;

            if (warningEdit) {
                if (length >= charsetLimitEdit) {
                    warningEdit.classList.remove("hidden");
                    messageInputEdit.classList.add("border", "border-red-500");
                } else {
                    warningEdit.classList.add("hidden");
                    messageInputEdit.classList.remove("border", "border-red-500");
                }
            }
        }

        window.setCharsetLimitEdit = function(limit) {
            charsetLimitEdit = limit;
            updateCounterEdit();
        };

        messageInputEdit.addEventListener("input", updateCounterEdit);

        // Emoji picker
        if (emojiBtnEdit) {
            const pickerEdit = picmoPopup.createPopup(
                { emojiSize: "1.4em", hideOnEmojiSelect: false },
                { referenceElement: emojiBtnEdit, triggerElement: emojiBtnEdit, position: "top-end" }
            );

            pickerEdit.addEventListener("emoji:select", (selection) => {
                const emoji = selection.emoji;
                const start = messageInputEdit.selectionStart;
                const end = messageInputEdit.selectionEnd;
                const text = messageInputEdit.value;

                messageInputEdit.value = text.substring(0, start) + emoji + text.substring(end);
                messageInputEdit.selectionStart = messageInputEdit.selectionEnd = start + emoji.length;
                messageInputEdit.focus();
                updateCounterEdit();
            });

            emojiBtnEdit.addEventListener("click", () => pickerEdit.toggle());
        }

        updateCounterEdit();
    }
});*/





document.addEventListener("DOMContentLoaded", () => {
    const forms = [
        {
            input: document.getElementById("messageInput"),
            preview: document.getElementById("messagePreview"),
            counter: document.getElementById("counter"),
            warning: document.getElementById("limitWarning"),
            emojiBtn: document.getElementById("emojiBtn"),
            charsetGlobalSetter: "setCharsetLimit"
        },
        {
            input: document.getElementById("messageInputEdit"),
            preview: document.getElementById("messagePreviewEdit"),
            counter: document.getElementById("counterEdit"),
            warning: document.getElementById("limitWarningEdit"),
            emojiBtn: document.getElementById("emojiBtnEdit"),
            charsetGlobalSetter: "setCharsetLimitEdit"
        }
    ];

    forms.forEach((formObj) => {
        const { input, preview, counter, warning, emojiBtn, charsetGlobalSetter } = formObj;
        if (!input || !counter) return;

        let charsetCoding = "0";

        function updateCounter() {
            let value = input.value;
            const maxLength = charsetCoding === "8" ? 1340 : 1377;
            const singleLimit = charsetCoding === "8" ? 70 : 160;
            const concatLimit = charsetCoding === "8" ? 67 : 153;

            if (Array.from(value).length > maxLength) {
                value = Array.from(value).slice(0, maxLength).join("");
                input.value = value;
            }

            const length = Array.from(value).length;
            const smsCount = length <= singleLimit ? 1 : Math.ceil(length / concatLimit);

            if (preview) preview.innerText = length > 0 ? value : "Digite uma mensagem 😊";
            counter.textContent = `SMS cobrados: ${smsCount} / Caracteres: ${length} (limite ${maxLength})`;

            if (warning) {
                if (length >= maxLength) {
                    warning.classList.remove("hidden");
                    input.classList.add("border", "border-red-500");
                } else {
                    warning.classList.add("hidden");
                    input.classList.remove("border", "border-red-500");
                }
            }
        }

        // permite mudar charset dinamicamente
        window[charsetGlobalSetter] = function(coding) {
            charsetCoding = String(coding) === "8" ? "8" : "0";
            updateCounter();
        };

        input.addEventListener("input", updateCounter);

        // emoji picker
        if (emojiBtn) {
            const picker = picmoPopup.createPopup(
                { emojiSize: "1.4em", hideOnEmojiSelect: false },
                { referenceElement: emojiBtn, triggerElement: emojiBtn, position: "top-end" }
            );

            picker.addEventListener("emoji:select", (selection) => {
                const emoji = selection.emoji;
                const start = input.selectionStart;
                const end = input.selectionEnd;
                const text = input.value;

                input.value = text.substring(0, start) + emoji + text.substring(end);
                input.selectionStart = input.selectionEnd = start + emoji.length;
                input.focus();

                updateCounter();
            });

            emojiBtn.addEventListener("click", () => picker.toggle());
        }

        updateCounter();
    });
});



