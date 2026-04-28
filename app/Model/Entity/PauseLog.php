<?php

namespace App\Model\Entity;

use WilliamCosta\DatabaseManager\Database;

class PauseLog
{
    public $id;
    public $tenancy_id;
    public $pausa_config_id;
    public $user_id;
    public $start_time;
    public $end_time;
    public $status;

    /**
     * Inicia uma pausa para um agente
     */
    public function iniciarPausa(): bool
    {
        $this->start_time = date('Y-m-d H:i:s');
        $this->status     = 'active';

        $this->id = (new Database('pausas_logs'))->insert([
            'tenancy_id'      => $this->tenancy_id,
            'pausa_config_id' => $this->pausa_config_id,
            'user_id'         => $this->user_id,
            'start_time'      => $this->start_time,
            'status'          => $this->status
        ]);

        return true;
    }

    /**
     * Finaliza a pausa atual do agente
     */
    public static function finalizarPausaAtiva($userId, $tenancyId): bool
    {
        $now = date('Y-m-d H:i:s');

        return (new Database('pausas_logs'))->update(
            'user_id = ' . (int)$userId . ' AND tenancy_id = "' . $tenancyId . '" AND status = "active"',
            [
                'end_time' => $now,
                'status'   => 'completed'
            ]
        );
    }

    /**
     * Logs ativos com filtro obrigatório de tenancy e opcional de user
     */
    public static function getLogsAtivos(string $tenancyId, ?int $userId = null): array|false
    {
        $where = 'tenancy_id = :tenancy_id AND status = :status';
        $params = [
            ':tenancy_id' => $tenancyId,
            ':status'     => 'active'
        ];

        if (!is_null($userId)) {
            $where .= ' AND user_id = :user_id';
            $params[':user_id'] = $userId;
        }

        $result = (new Database('pausas_logs'))->select($where, $params);

        if (!$result) {
            return [];
        }

        return $result->fetchAll(\PDO::FETCH_CLASS, self::class);
    }

    /**
     * Logs do dia com o mesmo filtro obrigatório
     */
    public static function getLogsDoDia(string $tenancyId, ?int $userId = null): array|false
    {
        $hoje = date('Y-m-d');

        $where = 'tenancy_id = :tenancy_id AND DATE(start_time) = :hoje';
        $params = [
            ':tenancy_id' => $tenancyId,
            ':hoje'       => $hoje
        ];

        if (!is_null($userId)) {
            $where .= ' AND user_id = :user_id';
            $params[':user_id'] = $userId;
        }

        $result = (new Database('pausas_logs'))->select($where, $params);

        if (!$result) {
            return [];
        }

        return $result->fetchAll(\PDO::FETCH_CLASS, self::class);
    }
}