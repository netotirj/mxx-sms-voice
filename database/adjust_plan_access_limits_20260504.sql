-- Ajusta limites de acesso simultaneo e permissao de criacao de usuarios por plano.
-- Basic: 1 acesso e sem criacao de usuarios.
-- Standard: 5 acessos e pode criar usuarios.
-- Plus e planos superiores/de usuarios: 10 acessos e pode criar usuarios.

UPDATE mxx_plans
SET simultaneous_access = 1,
    users_create = 'n'
WHERE status = 'active'
  AND name_plan = 'Basic';

UPDATE mxx_plans
SET simultaneous_access = 5,
    users_create = 'y'
WHERE status = 'active'
  AND name_plan = 'Standard';

UPDATE mxx_plans
SET simultaneous_access = 10,
    users_create = 'y'
WHERE status = 'active'
  AND name_plan IN ('Plus', 'Basic - Users', 'Light - Users', 'Plus - Users');
