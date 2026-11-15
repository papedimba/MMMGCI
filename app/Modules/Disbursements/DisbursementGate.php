<?php
declare(strict_types=1);

namespace App\Modules\Disbursements;

use App\Core\Database;

/** Le circuit ne fonctionne que si au moins deux utilisateurs actifs distincts détiennent disbursement.approve par leurs rôles (A6). */
final class DisbursementGate
{
    public const MESSAGE = 'Le circuit de décaissement est inopérant : il faut au moins deux personnes actives distinctes autorisées à approuver. '
        . 'Le super administrateur doit attribuer la permission « Approuver un décaissement » à deux utilisateurs (Administration > Rôles).';

    public function __construct(private Database $db)
    {
    }

    public function isOperational(): bool
    {
        return (int) $this->db->fetchValue(
            "SELECT COUNT(DISTINCT u.id) FROM users u
             JOIN user_roles ur ON ur.user_id = u.id
             JOIN role_permissions rp ON rp.role_id = ur.role_id
             JOIN permissions p ON p.id = rp.permission_id
             WHERE u.is_active = 1 AND p.name = 'disbursement.approve'"
        ) >= 2;
    }
}
