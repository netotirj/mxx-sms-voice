<?php

namespace App\Model\Entity;

use WilliamCosta\DatabaseManager\Database;

class AgentsPortal {

    public $id;
    public $tenancy_id;
    public $user_id;
    public $name;
    public $extension;
    public $password;
    public $status;
    public $last_activity;
    public $createdAt;
    public $updatedAt;

    /**
     * Método responsável por cadastrar o agente no banco
     */
    public function cadastrar(): bool
    {
        // Define as datas
        $this->createdAt = date('Y-m-d H:i:s');
        $this->updatedAt = date('Y-m-d H:i:s');

        // Insere no banco de dados
        $this->id = (new Database('agentes_portal'))->insert([
            'tenancy_id'    => $this->tenancy_id,
            'user_id'       => $this->user_id,
            'name'          => $this->name,
            'extension'     => $this->extension,
            'password'      => $this->password,
            'status'        => $this->status ?? 'n', // Ajustado para seu padrão enum 'y'/'n' ou 'offline'
            'createdAt'     => $this->createdAt,
            'updatedAt'     => $this->updatedAt
        ]);

        return true;
    }

    /**
     * Busca o agente pela extensão e pelo tenancy_id (UUID)
     */
    public static function getAgentByExtension($extension, $tenancyId) {
        return (new Database('agentes_portal'))
            ->select('extension = "'.$extension.'" AND tenancy_id = "'.$tenancyId.'"')
            ->fetchObject(self::class);
    }

    /**
     * Atualiza os dados do agente (Nome, Senha, Status e Atividade)
     */
    public function atualizar(): bool
    {
        // Define a data de atualização
        $this->updatedAt = date('Y-m-d H:i:s');

        return (new Database('agentes_portal'))->update('id = '.$this->id, [
            'user_id'       => $this->user_id,
            'name'          => $this->name,
            'password'      => $this->password,
            'status'        => $this->status,
            'last_activity' => $this->last_activity,
            'updatedAt'     => $this->updatedAt
        ]);
    }

    /**
     * Remove o agente do banco de dados
     */
    public function excluir(): bool
    {
        return (new Database('agentes_portal'))->delete('id = '.$this->id);
    }
}