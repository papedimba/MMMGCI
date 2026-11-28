<?php
declare(strict_types=1);

namespace App\Modules\Social;

use App\Core\Audit;
use App\Core\Database;
use App\Core\Gate;
use App\Modules\AuditLog\SensitiveAccess;

/** Données de santé d'une demande (table séparée) : lecture social.medical.view, chaque lecture journalisée ; écriture social.manage ET social.medical.view. */
final class SocialMedicalService
{
    private SocialAccess $access;

    public function __construct(private Database $db, private Audit $audit, Gate $gate)
    {
        $this->access = new SocialAccess($db, $gate, $audit);
    }

    public function read(int $requestId, int $userId, ?string $ip): ?string
    {
        $this->access->assertCanSeeRequest($userId, $requestId);
        if (!$this->access->canSeeMedical($userId)) {
            $this->access->deny($userId, $requestId, 'social.medical.view', $ip);
        }
        $details = $this->db->fetchValue('SELECT details FROM social_request_medical WHERE social_request_id = ?', [$requestId]);
        (new SensitiveAccess($this->db))->record($userId, 'social_request', $requestId, 'donnees_medicales', $ip);
        return $details === null ? null : (string) $details;
    }

    public function write(int $requestId, string $details, int $actor, ?string $ip = null): void
    {
        $details = SocialRequestService::text($details, 5000, 'Données de santé') ?? throw new SocialRuleViolation('Saisissez les données de santé (5 000 caractères au maximum).');
        $this->db->atomic(function () use ($requestId, $details, $actor, $ip): void {
            $r = $this->access->lockForWrite($requestId, $actor, 'social.manage');
            if (!$this->access->canWriteMedical($actor)) {
                throw SocialAccess::refused();
            }
            $created = $this->db->fetchValue('SELECT 1 FROM social_request_medical WHERE social_request_id = ?', [$requestId]) === null;
            $this->db->query('INSERT INTO social_request_medical (social_request_id, details, updated_by) VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE details = VALUES(details), updated_by = VALUES(updated_by)', [$requestId, $details, $actor]);
            $this->db->update('social_requests', ['contains_medical_data' => 1], ['id' => $requestId]);
            $this->audit->log($actor, 'social.medical_updated', 'social_request', $requestId, null, ['created' => $created], $r['reference'], $ip);
        });
    }
}
