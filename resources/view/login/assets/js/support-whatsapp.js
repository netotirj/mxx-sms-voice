(function () {
  const button = document.getElementById('support-whatsapp-floating');
  if (!button) return;

  const statusText = document.getElementById('support-whatsapp-status-text');
  const label = document.getElementById('support-whatsapp-label');
  const hours = document.getElementById('support-whatsapp-hours');
  const icon = button.querySelector('.support-whatsapp-floating__icon');
  const phone = '5521968943160';
  const optionCopy = {
    support: {
      label: 'Suporte tecnico',
      reply: 'Certo, vou te ajudar com suporte. Me diga seu WhatsApp e um resumo do problema para eu encaminhar melhor.'
    },
    commercial: {
      label: 'Comercial',
      reply: 'Perfeito. Me passa seu WhatsApp e o assunto comercial para eu direcionar ao time certo.'
    },
    sales: {
      label: 'Vendas',
      reply: 'Legal. Deixe seu WhatsApp e me conte o que voce procura para o time de vendas continuar.'
    },
    finance: {
      label: 'Financeiro',
      reply: 'Tudo bem. Informe seu WhatsApp e o assunto financeiro para eu organizar o atendimento.'
    }
  };
  let selectedOption = null;

  if (icon) {
    icon.textContent = 'L';
  }

  function isBusinessOpen(date) {
    const day = date.getDay();
    const minutes = date.getHours() * 60 + date.getMinutes();
    return day >= 1 && day <= 5 && minutes >= 540 && minutes < 1080;
  }

  function getNextBusinessOpening(date) {
    const next = new Date(date);
    next.setHours(9, 0, 0, 0);

    if (date.getDay() >= 1 && date.getDay() <= 5 && date.getHours() < 9) {
      return next;
    }

    do {
      next.setDate(next.getDate() + 1);
      next.setHours(9, 0, 0, 0);
    } while (next.getDay() === 0 || next.getDay() === 6);

    return next;
  }

  function formatNextBusinessOpening(date) {
    const now = new Date();
    const tomorrow = new Date(now);
    tomorrow.setDate(now.getDate() + 1);

    const time = date.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' });
    if (date.toDateString() === now.toDateString()) return `hoje às ${time}`;
    if (date.toDateString() === tomorrow.toDateString()) return `amanhã às ${time}`;

    return date.toLocaleDateString('pt-BR', {
      weekday: 'long',
      day: '2-digit',
      month: '2-digit',
      hour: '2-digit',
      minute: '2-digit'
    });
  }

  function getSupportAvailability() {
    const now = new Date();
    const online = isBusinessOpen(now);
    const nextOpening = getNextBusinessOpening(now);

    return {
      online,
      nextOpeningText: formatNextBusinessOpening(nextOpening)
    };
  }

  function createPanel() {
    let panel = document.getElementById('support-whatsapp-panel');
    if (panel) return panel;

    panel = document.createElement('div');
    panel.id = 'support-whatsapp-panel';
    panel.className = 'support-whatsapp-panel hidden';
    panel.setAttribute('aria-hidden', 'true');
    panel.innerHTML = `
      <div class="support-whatsapp-panel__header">
        <div>
          <p class="support-whatsapp-panel__eyebrow">Assistente Maxx</p>
          <h6 class="support-whatsapp-panel__title">Livia</h6>
        </div>
        <button id="support-whatsapp-close" type="button" class="support-whatsapp-panel__close" aria-label="Fechar">x</button>
      </div>

      <div id="support-bot-messages" class="support-bot-messages">
        <div class="support-bot-message support-bot-message--bot">
          Ola! Eu sou a Livia, assistente da Maxx. Escolha uma opcao para eu te direcionar.
        </div>
      </div>

      <div id="support-bot-options" class="support-bot-options">
        <button type="button" data-support-option="support">Suporte tecnico</button>
        <button type="button" data-support-option="commercial">Comercial</button>
        <button type="button" data-support-option="sales">Vendas</button>
        <button type="button" data-support-option="finance">Financeiro</button>
      </div>

      <div id="support-bot-after-message" class="support-bot-options hidden">
        <button type="button" data-support-action="new-message">Novo atendimento</button>
        <button type="button" data-support-action="close-panel">Encerrar conversa</button>
      </div>

      <div id="support-bot-contact" class="support-bot-contact hidden">
        <label class="support-whatsapp-field">
          <span>Seu WhatsApp</span>
          <input id="support-bot-phone" type="text" inputmode="numeric" placeholder="Ex: 5521999999999">
        </label>
        <label class="support-whatsapp-field">
          <span>Mensagem</span>
          <textarea id="support-bot-note" rows="3" placeholder="Conte rapidamente o que voce precisa"></textarea>
        </label>
        <p id="support-whatsapp-feedback" class="support-whatsapp-feedback"></p>
        <div class="support-whatsapp-panel__actions">
          <button id="support-bot-back" type="button" class="support-whatsapp-panel__secondary">Voltar</button>
          <button id="support-bot-whatsapp" type="button" class="support-whatsapp-panel__send">Abrir WhatsApp</button>
        </div>
      </div>
    `;

    document.body.appendChild(panel);
    return panel;
  }

  const panel = createPanel();
  const closeBtn = document.getElementById('support-whatsapp-close');
  const messages = document.getElementById('support-bot-messages');
  const options = document.getElementById('support-bot-options');
  const afterMessageOptions = document.getElementById('support-bot-after-message');
  const contact = document.getElementById('support-bot-contact');
  const phoneInput = document.getElementById('support-bot-phone');
  const noteInput = document.getElementById('support-bot-note');
  const backBtn = document.getElementById('support-bot-back');
  const whatsappBtn = document.getElementById('support-bot-whatsapp');
  const feedback = document.getElementById('support-whatsapp-feedback');
  let offlineNoticeShown = false;

  function updateWhatsAppSupportStatus() {
    const availability = getSupportAvailability();
    const online = availability.online;

    button.classList.toggle('is-offline', !online);
    button.href = '#';
    button.removeAttribute('target');
    button.removeAttribute('rel');
    button.setAttribute('aria-label', online ? 'Abrir assistente online da Maxx' : 'Abrir assistente da Maxx');

    if (statusText) statusText.textContent = online ? 'Assistente online' : 'Assistente offline';
    if (label) label.textContent = online ? 'Precisa de ajuda?' : 'Deixe seu recado';
    if (hours) hours.textContent = online ? 'Atendimento até 18h' : `Retorno ${availability.nextOpeningText}`;
  }

  function setSupportFeedback(message, type = '') {
    if (!feedback) return;
    feedback.textContent = message || '';
    feedback.classList.toggle('is-error', type === 'error');
    feedback.classList.toggle('is-success', type === 'success');
  }

  function openSupportPanel() {
    button.classList.add('is-open');
    panel.classList.remove('hidden');
    panel.setAttribute('aria-hidden', 'false');
  }

  function closeSupportPanel() {
    button.classList.remove('is-open');
    panel.classList.add('hidden');
    panel.setAttribute('aria-hidden', 'true');
  }

  function appendBotMessage(text, from = 'bot') {
    if (!messages) return;
    const div = document.createElement('div');
    div.className = `support-bot-message support-bot-message--${from}`;
    div.textContent = text;
    messages.appendChild(div);
    messages.scrollTop = messages.scrollHeight;
  }

  function showTyping() {
    if (!messages) return null;
    const div = document.createElement('div');
    div.className = 'support-bot-message support-bot-message--bot support-bot-message--typing';
    div.innerHTML = '<span></span><span></span><span></span>';
    messages.appendChild(div);
    messages.scrollTop = messages.scrollHeight;
    return div;
  }

  function appendBotMessageWithTyping(text, delay = 650) {
    const typing = showTyping();
    window.setTimeout(() => {
      typing?.remove();
      appendBotMessage(text, 'bot');
    }, delay);
  }

  function showContactForm() {
    contact?.classList.remove('hidden');
    phoneInput?.focus();
  }

  function chooseOption(option) {
    const data = optionCopy[option];
    if (!data) return;
    const availability = getSupportAvailability();

    selectedOption = option;
    setSupportFeedback('');
    appendBotMessage(data.label, 'user');
    options?.classList.add('hidden');
    afterMessageOptions?.classList.add('hidden');
    appendBotMessageWithTyping(
      availability.online
        ? data.reply
        : `No momento nosso atendimento em tempo real está offline. Você pode deixar seu recado agora e o time responde no próximo expediente (${availability.nextOpeningText}).`,
      720
    );
    window.setTimeout(showContactForm, 760);
  }

  function resetBot() {
    selectedOption = null;
    setSupportFeedback('');
    if (noteInput) noteInput.value = '';
    contact?.classList.add('hidden');
    afterMessageOptions?.classList.add('hidden');
    options?.classList.remove('hidden');
  }

  function showAfterMessageOptions() {
    selectedOption = null;
    setSupportFeedback('');
    if (noteInput) noteInput.value = '';
    contact?.classList.add('hidden');
    options?.classList.add('hidden');
    afterMessageOptions?.classList.remove('hidden');
  }

  function openWhatsApp() {
    const userPhone = (phoneInput?.value || '').replace(/\D+/g, '');
    const note = (noteInput?.value || '').trim();

    if (!selectedOption) {
      setSupportFeedback('Escolha uma area de atendimento.', 'error');
      return;
    }

    if (userPhone.length < 8) {
      setSupportFeedback('Informe seu WhatsApp com DDI e DDD.', 'error');
      return;
    }

    if (!note) {
      setSupportFeedback('Conte rapidamente o que voce precisa.', 'error');
      return;
    }

    const area = optionCopy[selectedOption]?.label || 'Atendimento';
    const text = [
      `Ola, preciso falar com ${area}.`,
      `Estou na pagina: ${document.title || 'Maxx Solutions'}.`,
      `Meu WhatsApp: ${userPhone}.`,
      `Mensagem: ${note}`
    ].join('\n');

    localStorage.setItem('support_bot_last_phone', userPhone);
    setSupportFeedback('Abrindo WhatsApp...', 'success');
    appendBotMessage('Abrir WhatsApp', 'user');
    appendBotMessageWithTyping('Tudo certo. Vou abrir o WhatsApp com sua mensagem pronta.', 520);
    window.open(`https://wa.me/${phone}?text=${encodeURIComponent(text)}`, '_blank', 'noopener,noreferrer');
    window.setTimeout(showAfterMessageOptions, 900);
  }

  updateWhatsAppSupportStatus();
  if (phoneInput) phoneInput.value = localStorage.getItem('support_bot_last_phone') || '';
  window.setInterval(updateWhatsAppSupportStatus, 60000);

  button.addEventListener('click', (event) => {
    event.preventDefault();
    openSupportPanel();
    const availability = getSupportAvailability();
    if (!availability.online && !offlineNoticeShown) {
      appendBotMessageWithTyping(`Estamos fora do horário de atendimento agora. Deixe seu recado e responderemos no próximo expediente (${availability.nextOpeningText}).`, 420);
      offlineNoticeShown = true;
    }
  });

  closeBtn?.addEventListener('click', closeSupportPanel);

  options?.addEventListener('click', (event) => {
    const btn = event.target.closest('[data-support-option]');
    if (btn) chooseOption(btn.dataset.supportOption);
  });

  afterMessageOptions?.addEventListener('click', (event) => {
    const btn = event.target.closest('[data-support-action]');
    if (!btn) return;

    if (btn.dataset.supportAction === 'new-message') {
      appendBotMessage('Novo atendimento', 'user');
      appendBotMessageWithTyping('Claro. Escolha a area que melhor combina com o novo atendimento.', 520);
      resetBot();
      return;
    }

    appendBotMessage('Encerrar conversa', 'user');
    appendBotMessageWithTyping('Tudo certo. Quando precisar, eu fico por aqui.', 520);
    afterMessageOptions.classList.add('hidden');
  });

  backBtn?.addEventListener('click', resetBot);
  whatsappBtn?.addEventListener('click', openWhatsApp);
})();
