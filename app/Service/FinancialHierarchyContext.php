<?php

namespace App\Service;

class FinancialHierarchyContext
{
    public int $actor_user_id = 0;
    public string $actor_role = '';
    public string $tenancy_id = '';
    public int $owner_admin_id = 0;
    public ?int $parent_user_id = null;
    public ?int $reseller_id = null;
    public bool $actor_has_wallet = false;
    public bool $owner_has_wallet = false;
    public bool $reseller_has_wallet = false;
    public string $chain_type = '';
    public array $billing_chain = [];

    public static function fromArray(array $data): self
    {
        $context = new self();

        foreach ($data as $field => $value) {
            if (property_exists($context, $field)) {
                $context->{$field} = $value;
            }
        }

        return $context;
    }

    public function toArray(): array
    {
        return [
            'actor_user_id' => $this->actor_user_id,
            'actor_role' => $this->actor_role,
            'tenancy_id' => $this->tenancy_id,
            'owner_admin_id' => $this->owner_admin_id,
            'parent_user_id' => $this->parent_user_id,
            'reseller_id' => $this->reseller_id,
            'actor_has_wallet' => $this->actor_has_wallet,
            'owner_has_wallet' => $this->owner_has_wallet,
            'reseller_has_wallet' => $this->reseller_has_wallet,
            'chain_type' => $this->chain_type,
            'billing_chain' => $this->billing_chain,
        ];
    }
}
