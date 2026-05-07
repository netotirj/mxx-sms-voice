(function () {
  const button = document.getElementById('support-whatsapp-floating');
  if (!button) return;

  const statusText = document.getElementById('support-whatsapp-status-text');
  const label = document.getElementById('support-whatsapp-label');
  const hours = document.getElementById('support-whatsapp-hours');
  const icon = button.querySelector('.support-whatsapp-floating__icon');
  const supportPhone = (button.dataset.supportWhatsappPhone || '').replace(/\D+/g, '') || '5568992024512';
  const externalWhatsAppDelayMs = 1800;
  const optionCopy = {
    support: {
      label: 'Suporte',
      onlineReply: 'Entendi. Se a dificuldade for para acessar sua conta, vou te direcionar agora para o WhatsApp da empresa.',
      offlineReply: 'Se você está com dificuldade para acessar sua conta, deixe seu WhatsApp e descreva o problema para o time analisar no próximo expediente.',
      intro: 'Olá! Estou com dificuldade para acessar minha conta.',
      prompt: 'Se puder, me ajude com esse acesso.',
      category: 'suporte de acesso'
    },
    commercial: {
      label: 'Comercial',
      onlineReply: 'Perfeito. Vou te direcionar agora para o WhatsApp da empresa no atendimento comercial.',
      offlineReply: 'No momento nosso atendimento está fora do horario. Deixe seu WhatsApp e o assunto comercial para o time continuar no proximo expediente.',
      intro: 'Olá! Gostaria de falar com o time comercial.',
      prompt: 'Preciso de informações comerciais e gostaria de um retorno.',
      category: 'comercial'
    },
    sales: {
      label: 'Vendas',
      onlineReply: 'Legal. Vou abrir o WhatsApp da empresa para voce falar com o time de vendas.',
      offlineReply: 'No momento nosso atendimento está fora do horario. Deixe seu WhatsApp e o que voce procura para o time continuar no proximo expediente.',
      intro: 'Olá! Tenho interesse em conhecer melhor as soluções da Maxx.',
      prompt: 'Gostaria de receber informações de vendas e entender a melhor opção para o meu caso.',
      category: 'vendas'
    },
    finance: {
      label: 'Financeiro',
      onlineReply: 'Tudo bem. Vou te direcionar agora para o WhatsApp da empresa no atendimento financeiro.',
      offlineReply: 'No momento nosso atendimento está fora do horario. Deixe seu WhatsApp e o assunto financeiro para o time responder no proximo expediente.',
      intro: 'Olá! Preciso falar com o financeiro.',
      prompt: 'Preciso de informações sobre pagamentos, faturas ou assuntos financeiros.',
      category: 'financeiro'
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
          Ola! Eu sou a Livia, assistente da Maxx. Escolha a area para falar com nosso time.
        </div>
      </div>

      <div id="support-bot-options" class="support-bot-options">
        <button type="button" data-support-option="support">Suporte</button>
        <button type="button" data-support-option="commercial">Comercial</button>
        <button type="button" data-support-option="sales">Vendas</button>
        <button type="button" data-support-option="finance">Financeiro</button>
      </div>

      <div id="support-bot-after-message" class="support-bot-options hidden">
        <button type="button" data-support-action="new-message">Novo atendimento</button>
        <button type="button" data-support-action="open-whatsapp">Abrir WhatsApp da empresa</button>
        <button type="button" data-support-action="close-panel">Encerrar conversa</button>
      </div>

      <div id="support-bot-contact" class="support-bot-contact hidden">
        <label class="support-whatsapp-field">
          <span>Seu WhatsApp para retorno</span>
          <input id="support-bot-phone" type="text" inputmode="numeric" placeholder="Ex: 5521999999999">
        </label>
        <label class="support-whatsapp-field">
          <span>Mensagem</span>
          <textarea id="support-bot-note" rows="3" placeholder="Conte rapidamente o que voce precisa"></textarea>
        </label>
        <p id="support-whatsapp-feedback" class="support-whatsapp-feedback"></p>
        <div class="support-whatsapp-panel__actions">
          <button id="support-bot-back" type="button" class="support-whatsapp-panel__secondary">Voltar</button>
          <button id="support-bot-whatsapp" type="button" class="support-whatsapp-panel__send">Enviar para WhatsApp da empresa</button>
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
    afterMessageOptions?.classList.add('hidden');
    contact?.classList.remove('hidden');
    phoneInput?.focus();
  }

  function openWhatsAppConversation(includeContactDetails = false) {
    const selectedArea = optionCopy[selectedOption] || null;
    const area = selectedArea?.label || 'Atendimento';
    const userPhone = (phoneInput?.value || '').replace(/\D+/g, '');
    const note = (noteInput?.value || '').trim();

    if (!selectedOption || !selectedArea) {
      setSupportFeedback('Escolha uma area de atendimento.', 'error');
      return false;
    }

    if (includeContactDetails) {
      if (userPhone.length < 8) {
        setSupportFeedback('Informe seu WhatsApp com DDI e DDD.', 'error');
        return false;
      }

      if (!note) {
        setSupportFeedback('Conte rapidamente o que voce precisa.', 'error');
        return false;
      }
    }

    const text = [
      selectedArea.intro || `Ola, preciso falar com ${area}.`,
      selectedArea.prompt || '',
      `Pagina de origem: ${document.title || 'Maxx Solutions'}.`,
      includeContactDetails ? `WhatsApp para retorno: ${userPhone}.` : '',
      includeContactDetails ? `Detalhes: ${note}` : '',
      `Area selecionada: ${selectedArea.category || area}.`
    ].filter(Boolean).join('\n');

    if (includeContactDetails) {
      localStorage.setItem('support_bot_last_phone', userPhone);
      setSupportFeedback('Mensagem preparada. Em breve entraremos em contato.', 'success');
      appendBotMessage('Enviar para WhatsApp da empresa', 'user');
      appendBotMessageWithTyping('Tudo certo. Vou preparar sua mensagem e abrir o WhatsApp da empresa em instantes. Em breve entraremos em contato.', 520);
    } else {
      appendBotMessage('Abrir WhatsApp da empresa', 'user');
      appendBotMessageWithTyping('Tudo certo. Vou abrir o WhatsApp da empresa em instantes para você conferir a conversa. Em breve entraremos em contato.', 520);
    }

    window.setTimeout(() => {
      window.open(`https://wa.me/${supportPhone}?text=${encodeURIComponent(text)}`, '_blank', 'noopener,noreferrer');
    }, externalWhatsAppDelayMs);
    window.setTimeout(showAfterMessageOptions, externalWhatsAppDelayMs + 250);
    return true;
  }

  function chooseOption(option) {
    const data = optionCopy[option];
    if (!data) return;
    const availability = getSupportAvailability();

    selectedOption = option;
    setSupportFeedback('');
    appendBotMessage(data.label, 'user');
    options?.classList.add('hidden');
    contact?.classList.add('hidden');
    afterMessageOptions?.classList.add('hidden');

    if (availability.online) {
      appendBotMessageWithTyping(data.onlineReply, 720);
      window.setTimeout(() => {
        openWhatsAppConversation(false);
      }, 880);
      return;
    }

    appendBotMessageWithTyping(
      `${data.offlineReply} O retorno acontece no próximo expediente (${availability.nextOpeningText}).`,
      720
    );
    window.setTimeout(showContactForm, 760);
  }

  function resetBot() {
    selectedOption = null;
    setSupportFeedback('');
    if (phoneInput) phoneInput.value = localStorage.getItem('support_bot_last_phone') || '';
    if (noteInput) noteInput.value = '';
    contact?.classList.add('hidden');
    afterMessageOptions?.classList.add('hidden');
    options?.classList.remove('hidden');
  }

  function showAfterMessageOptions() {
    setSupportFeedback('');
    if (phoneInput) phoneInput.value = localStorage.getItem('support_bot_last_phone') || '';
    if (noteInput) noteInput.value = '';
    contact?.classList.add('hidden');
    options?.classList.add('hidden');
    afterMessageOptions?.classList.remove('hidden');
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

    if (btn.dataset.supportAction === 'open-whatsapp') {
      openWhatsAppConversation(false);
      return;
    }

    appendBotMessage('Encerrar conversa', 'user');
    appendBotMessageWithTyping('Tudo certo. Quando precisar, eu fico por aqui.', 520);
    afterMessageOptions.classList.add('hidden');
  });

  backBtn?.addEventListener('click', resetBot);
  whatsappBtn?.addEventListener('click', () => openWhatsAppConversation(true));
})();
