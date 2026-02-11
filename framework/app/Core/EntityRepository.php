<?php
// app/Core/EntityRepository.php

namespace App\Core;

use PDO;

class EntityRepository extends BaseRepository
{
    public function __construct(
        string $entityName,
        ?PDO $db = null,
        ?int $tenantId = null,
        ?EntityRegistry $registry = null
    ) {
        $registry = $registry ?? new EntityRegistry();
        $entity = $registry->get($entityName);
        parent::__construct($entity, $db, $tenantId);
    }
}
