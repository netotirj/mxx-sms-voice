<?php

/**
 * Descrição da Classe VoiceWorker
 *
 * A classe VoiceWorker é o núcleo do sistema de discagem e gerenciamento de chamadas de voz.
 * Ela opera como um worker em loop contínuo, processando filas de chamadas e gerenciando
 * a distribuição de chamadas para agentes disponíveis.
 *
 * Funcionalidades Principais:
 *
 * 1. **Loop Principal (run())**:
 *    - Processa chamadas da fila Redis 'voice:queue'
 *    - Trata solicitações de transferência via 'voice:transfer_request'
 *    - Controla CPS (Chamadas Por Segundo) para evitar sobrecarga
 *    - Gerencia estratégias de distribuição de chamadas
 *
 * 2. **Gerenciamento de Agentes**:
 *    - Sincroniza status de agentes com chamadas ativas
 *    - Pré-reserva agentes antes de originar chamadas
 *    - Suporta múltiplas estratégias: rrmemory, leastrecent, linear, ringall
 *    - Filtra agentes por skills, VIP e endpoints específicos
 *
 * 3. **Originação de Chamadas**:
 *    - Cria canais de chamada via Asterisk ARI
 *    - Define contexto de chamada (call_context) com informações essenciais
 *    - Trata erros de conexão e implementa retry com backoff exponencial
 *    - Move chamadas falhidas para DLQ (Dead Letter Queue)
 *
 * 4. **Sistema de Transferência**:
 *    - Decide qual agente receberá a transferência baseada na estratégia
 *    - Gerencia reservas de agentes para transferências
 *    - Trata casos de ringall (tocar para múltiplos agentes)
 *    - Implementa anti-timeout com cache de decisões
 *
 * 5. **Monitoramento e Logging**:
 *    - Registra erros HTTP e de conexão
 *    - Notifica status de campanhas em tempo real
 *    - Mantém histórico de eventos por campanha
 *    - Log único para evitar spam
 *
 * 6. **Controle de Qualidade**:
 *    - Gate de Stasis: só processa quando o listener está online
 *    - Validação de heartbeat do ARI
 *    - Classificação de erros (permanentes vs transientes)
 *    - Deduplicação de erros na DLQ
 *
 * Problema de Criação de Bridge:
 *
 * O VoiceWorker prepara o terreno para a criação de bridges ao:
 * - Reservar agentes apropriados antes da chamada
 * - Definir o contexto da chamada (voice:call_context:{call_id})
 * - Escolher a estratégia de distribuição
 * - Garantir que agentes estejam disponíveis e online
 *
 * A criação efetiva da bridge (conexão entre canais) é feita pelo listener
 * (provavelmente listener.php) quando recebe eventos do Stasis e consulta
 * o contexto definido pelo VoiceWorker.
 *
 * Pontos Críticos para Debug de Bridge:
 * - Verificar se o call_context foi definido corretamente
 * - Confirmar que o agente foi reservado (reserved_agent)
 * - Checar se o listener está processando eventos de UP/Answer
 * - Validar se os canais estão sendo criados no ARI
 * - Monitorar logs de transferência e reserva
 */

echo "Descrição da Classe VoiceWorker gerada com sucesso!\n";
echo "Esta classe gerencia o workflow completo de chamadas de voz,\n";
echo "desde a originação até a distribuição para agentes.\n";
echo "Para problemas de bridge, foque no contexto da chamada e reserva de agentes.\n";