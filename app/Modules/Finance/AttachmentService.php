<?php
declare(strict_types=1);

namespace App\Modules\Finance;

use App\Core\Audit;
use App\Core\Database;
use DomainException;

/** Justificatifs polymorphes (financial_attachments). Types autorisés : cible = table de la liste blanche ; les lots suivants ajoutent leurs types ici. */
final class AttachmentService
{
    /** @var array<string,string> type => table (liste blanche : jamais un nom venu de l'utilisateur dans le SQL) */
    public const TARGETS = ['financial_transaction' => 'financial_transactions'];

    public function __construct(private Database $db, private Audit $audit)
    {
    }

    public function attach(string $type, int $id, int $fileId, string $label, int $actor): int
    {
        $table = self::TARGETS[$type] ?? throw new DomainException('Type de pièce jointe non géré.');
        return $this->db->atomic(function () use ($table, $type, $id, $fileId, $label, $actor): int {
            if ($this->db->fetchValue("SELECT 1 FROM `$table` WHERE id = ?", [$id]) === null) {
                throw new DomainException('Élément à justifier introuvable.');
            }
            if ($this->db->fetchValue('SELECT 1 FROM files WHERE id = ?', [$fileId]) === null) {
                throw new DomainException('Fichier introuvable.');
            }
            $label = mb_substr(trim($label), 0, 160);
            $aid = $this->db->insert('financial_attachments', ['attachable_type' => $type, 'attachable_id' => $id, 'file_id' => $fileId,
                'label' => $label === '' ? null : $label, 'uploaded_by' => $actor]);
            $this->audit->log($actor, 'finance.attachment_added', $type, $id, null, ['attachment_id' => $aid, 'file_id' => $fileId]);
            return $aid;
        });
    }
}
