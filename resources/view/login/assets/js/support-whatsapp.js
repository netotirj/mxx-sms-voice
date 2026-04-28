(function () {
  const button = document.getElementById('support-whatsapp-floating');
  if (!button) return;

  const statusText = document.getElementById('support-whatsapp-status-text');
  const label = document.getElementById('support-whatsapp-label');
  const hours = document.getElementById('support-whatsapp-hours');
  const phone = '5521968943160';
  const onlineMessage = 'Ola, gostaria de falar com o suporte da Maxx.';
  const offlineMessage = 'Ola, gostaria de atendimento. Sei que o horario de suporte e de segunda a sexta, das 09h as 18h.';

  function isBusinessOpen(date) {
    const day = date.getDay();
    const minutes = date.getHours() * 60 + date.getMinutes();
    return day >= 1 && day <= 5 && minutes >= 540 && minutes < 1080;
  }

  function updateWhatsAppSupportStatus() {
    const online = isBusinessOpen(new Date());
    const message = online ? onlineMessage : offlineMessage;

    button.classList.toggle('is-offline', !online);
    button.href = `https://wa.me/${phone}?text=${encodeURIComponent(message)}`;
    button.setAttribute('aria-label', online ? 'Abrir atendimento online pelo WhatsApp' : 'Enviar mensagem para o suporte no WhatsApp');

    if (statusText) statusText.textContent = online ? 'Suporte online' : 'Suporte offline';
    if (label) label.textContent = online ? 'Fale com a Maxx' : 'Enviar mensagem';
    if (hours) hours.textContent = online ? 'Atendimento ate 18h' : 'Seg a sex, 09h as 18h';
  }

  updateWhatsAppSupportStatus();
  window.setInterval(updateWhatsAppSupportStatus, 60000);
})();
