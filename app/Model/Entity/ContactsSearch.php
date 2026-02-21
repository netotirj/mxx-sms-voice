<?php

namespace App\Model\Entity;

use WilliamCosta\DatabaseManager\Database;

class ContactsSearch
{
    public int $id;
    public string $tenancy_id;
    public int $user_id;
    public int $campaign_id;
    public string $name;
    public string $phone;
    public string $created_at;
    public string $updated_at;

    /**
     * Registra o contato na tabela 'contacts'
     */
    public function register(): bool
{

    try {
        $this->id = (new Database('contacts'))->insert([
            'tenancy_id'   => $this->tenancy_id,
            'user_id'      => $this->user_id,
            'campaign_id'  => $this->campaign_id,
            'name'         => $this->name,
            'phone'        => $this->phone,
            'created_at'   => $this->created_at,
            'updated_at'   => $this->updated_at
        ]);

        if (!$this->id) {
            throw new \Exception('Falha ao inserir no banco.');
        }

        return true;

    } catch (\Exception $e) {
        echo "Erro ao inserir contato: " . $e->getMessage();
        return false;
    }
}

}




