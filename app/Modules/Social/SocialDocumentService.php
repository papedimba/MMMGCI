<?php
declare(strict_types=1);

namespace App\Modules\Social;

use App\Core\Audit;
use App\Core\Database;
use App\Core\Gate;
use App\Core\NotFound;
use App\Core\Uploads;
use App\Modules\AuditLog\SensitiveAccess;
use InvalidArgumentException;
use Throwable;

/**
 * Pièces d'une demande sociale : disque privé (Uploads, fichiers 0640), jamais dans financial_attachments. Une pièce médicale (is_medical)
 * n'est lisible qu'avec social.medical.view, son libellé aussi, et chaque consultation est journalisée (sensitive_access_logs, donnees_medicales).
 * Audit : identifiants, nature et drapeau, jamais le libellé ni le nom du fichier.
 */
final class SocialDocumentService
{
    public const KINDS = ['piece' => 'Pièce justificative', 'decision' => 'Pièce de décision'];
    public const RESTRICTED_LABEL = 'Pièce médicale (accès restreint)';
    private SocialAccess $access;
    private SensitiveAccess $sensitive;

    public function __construct(private Database $db, private Audit $audit, Gate $gate, private Uploads $uploads)
    {
        $this->access = new SocialAccess($db, $gate, $audit);
        $this->sensitive = new SensitiveAccess($db);
    }

    /** social.manage, ou social.create tant que la demande est reçue (dépôt à l'enregistrement). @param array<string,mixed> $phpFile entrée de $_FILES */
    public function add(int $requestId, array $phpFile, string $kind, ?string $label, bool $isMedical, int $actor, ?string $ip = null): int
    {
        if (!isset(self::KINDS[$kind])) {
            throw new SocialRuleViolation('Choisissez la nature de la pièce : pièce justificative ou pièce de décision.');
        }
        $label = SocialRequestService::text($label, 160, 'Libellé de la pièce');
        $path = null;
        try {
            return $this->db->atomic(function () use ($requestId, $phpFile, $kind, $label, $isMedical, $actor, $ip, &$path): int {
                $r = $this->access->lockForWrite($requestId, $actor, 'social.manage', 'social.create');
                if ($r['status'] !== 'recue' && !$this->access->allows($actor, 'social.manage')) {
                    throw SocialAccess::refused();
                }
                $fileId = $this->uploads->store($phpFile, 'document', false, $actor);
                $path = $this->uploads->path($fileId);
                $docId = $this->db->insert('social_request_documents', ['social_request_id' => $requestId, 'file_id' => $fileId, 'kind' => $kind,
                    'label' => $label, 'is_medical' => $isMedical ? 1 : 0, 'uploaded_by' => $actor]);
                if ($isMedical) {
                    $this->db->update('social_requests', ['contains_medical_data' => 1], ['id' => $requestId]);
                }
                $this->audit->log($actor, 'social.document_added', 'social_request', $requestId, null,
                    ['document_id' => $docId, 'file_id' => $fileId, 'kind' => $kind, 'is_medical' => $isMedical], $r['reference'], $ip);
                return $docId;
            });
        } catch (Throwable $e) {
            if ($path !== null && is_file($path)) {
                @unlink($path); // la ligne files est annulée avec la transaction : pas de fichier orphelin
            }
            throw $e;
        }
    }

    /**
     * Pièces visibles par $userId (pièces ordinaires : instruction ; pièces médicales : instruction ou santé, libellé masqué sans social.medical.view).
     * @return list<array{id:int, kind:string, label:string, is_medical:bool, created_at:string, downloadable:bool}>
     */
    public function list(int $requestId, int $userId, ?string $ip): array
    {
        $this->access->assertCanSeeRequest($userId, $requestId);
        $instruction = $this->access->canSeeInstruction($userId);
        $medical = $this->access->canSeeMedical($userId);
        $out = [];
        $revealed = false;
        foreach ($this->db->fetchAll('SELECT d.id, d.kind, d.label, d.is_medical, d.created_at, f.original_name FROM social_request_documents d
                JOIN files f ON f.id = d.file_id WHERE d.social_request_id = ? ORDER BY d.id', [$requestId]) as $d) {
            $isMedical = (int) $d['is_medical'] === 1;
            if (!($isMedical ? $instruction || $medical : $instruction)) {
                continue;
            }
            $out[] = ['id' => (int) $d['id'], 'kind' => (string) $d['kind'], 'is_medical' => $isMedical, 'created_at' => (string) $d['created_at'],
                'label' => $isMedical && !$medical ? self::RESTRICTED_LABEL : (string) ($d['label'] ?? $d['original_name']), 'downloadable' => !$isMedical || $medical];
            $revealed = $revealed || ($isMedical && $medical);
        }
        if ($revealed) { // le libellé d'une pièce médicale est une donnée de santé : sa lecture est une consultation
            $this->sensitive->record($userId, 'social_request', $requestId, 'donnees_medicales', $ip);
        }
        return $out;
    }

    /** Chemin du fichier à servir. Hors périmètre ou pièce d'une autre demande : 404 ; non habilité : 403 journalisé. Pièce médicale : chaque téléchargement journalisé. */
    public function stream(int $requestId, int $docId, int $userId, ?string $ip): string
    {
        $this->access->assertCanSeeRequest($userId, $requestId);
        $d = $this->db->fetch('SELECT file_id, is_medical FROM social_request_documents WHERE id = ? AND social_request_id = ?', [$docId, $requestId])
            ?? throw new NotFound('Pièce introuvable.');
        $isMedical = (int) $d['is_medical'] === 1;
        if (!($isMedical ? $this->access->canSeeMedical($userId) : $this->access->canSeeInstruction($userId))) {
            $this->access->deny($userId, $requestId, $isMedical ? 'social.medical.view' : 'social.manage|social.decide', $ip, ['document_id' => $docId]);
        }
        try {
            $path = $this->uploads->path((int) $d['file_id']);
        } catch (InvalidArgumentException) {
            throw new NotFound('Fichier introuvable.');
        }
        if ($isMedical) {
            $this->sensitive->record($userId, 'social_request', $requestId, 'donnees_medicales', $ip);
        }
        return $path;
    }
}
