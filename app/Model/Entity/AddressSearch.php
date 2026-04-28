<?php

namespace App\Model\Entity;

use WilliamCosta\DatabaseManager\Database;

class AddressSearch
{
    /**
     * Propriedades da Model (Batendo com seu DESCRIBE e print_r)
     */
    public int $id;
    public string $tenancy_id;
    public string $street;
    public string $number;
    public ?string $complement;
    public string $neighborhood;
    public string $city;
    public string $state;
    public string $zipcode;
    public string $country = 'Brasil';

    /**
     * Método responsável por inserir ou atualizar o endereço
     * @return bool
     */
    public function save(): bool
    {
        $db = new Database('address');

        // 1. Verifica se já existe um endereço para esse tenancy_id
        // Usamos o fetch para validar a existência
        $exists = $db->select(
            'tenancy_id = :tenancy_id',
            [':tenancy_id' => $this->tenancy_id],
            null,
            '1',
            'id'
        )->fetch(\PDO::FETCH_ASSOC);

        // 2. Prepara o array de valores para evitar erros de "Column cannot be null"
        // Como seu banco é Strict, garantimos string vazia em vez de null
        $values = [
            'street'       => $this->street ?? '',
            'number'       => $this->number ?? '',
            'complement'   => $this->complement ?? '',
            'neighborhood' => $this->neighborhood ?? '',
            'city'         => $this->city ?? '',
            'state'        => $this->state ?? '',
            'zipcode'      => $this->zipcode ?? '',
            'country'      => $this->country ?? 'Brasil'
        ];

        if ($exists) {
            // --- UPDATE ---
            // Retornamos true se a execução ocorrer, ignorando se o rowCount for 0
            // (pois o usuário pode salvar sem alterar nenhum dado)
            $db->update('tenancy_id = :tenancy_id', $values, [
                ':tenancy_id' => $this->tenancy_id
            ]);
            return true;

        } else {
            // --- INSERT ---
            // Adicionamos a tenancy_id apenas no insert
            $values['tenancy_id'] = $this->tenancy_id;

            $this->id = $db->insert($values);

            // Retorna true se gerou um ID (sucesso no insert)
            return (isset($this->id) && $this->id > 0);
        }
    }

    /**
     * Opcional: Busca endereço pela tenancy_id
     * Útil para carregar os dados no perfil
     */
    public static function getAddressByTenancy(string $tenancyId)
    {
        return (new Database('address'))->select('tenancy_id = :tid', [':tid' => $tenancyId])
            ->fetch(\PDO::FETCH_ASSOC);
    }
}