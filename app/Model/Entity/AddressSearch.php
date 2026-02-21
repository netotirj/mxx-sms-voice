<?php

namespace App\Model\Entity;

use WilliamCosta\DatabaseManager\Database;

class AddressSearch
{

    public int $id;
    public string $tenancy_id;
    public string $street;
    public string $number;
    public ?string $complement;
    public string $neighborhood;
    public string $city;
    public string $state;
    public string $zipcode;
    public string $country;

    /**
     * Insere ou atualiza endereço de acordo com o tenancy_id
     */
    public function save(): bool
    {
        // Verifica se já existe um endereço para esse tenancy
        $exists = (new Database('address'))->select(
            'tenancy_id = :tenancy_id',
            [':tenancy_id' => $this->tenancy_id],
            null,
            '1',
            'id'
        )->fetch(\PDO::FETCH_ASSOC);

        if ($exists) {
            // UPDATE
            return (new Database('address'))->update(
                    'tenancy_id = :tenancy_id',
                    [
                        'street' => $this->street,
                        'number' => $this->number,
                        'complement' => $this->complement,
                        'neighborhood' => $this->neighborhood,
                        'city' => $this->city,
                        'state' => $this->state,
                        'zipcode' => $this->zipcode,
                        'country' => $this->country,
                    ],
                    [
                        ':tenancy_id' => $this->tenancy_id
                    ]
                ) > 0;
        } else {
            // INSERT
            $this->id = (new Database('address'))->insert([
                'tenancy_id' => $this->tenancy_id,
                'street' => $this->street,
                'number' => $this->number,
                'complement' => $this->complement,
                'neighborhood' => $this->neighborhood,
                'city' => $this->city,
                'state' => $this->state,
                'zipcode' => $this->zipcode,
                'country' => $this->country,
            ]);

            return $this->id > 0;
        }
    }


}