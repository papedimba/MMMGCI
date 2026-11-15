<?php
declare(strict_types=1);

namespace App\Modules\Contributions;

use App\Core\Audit;
use App\Core\Database;
use App\Core\Money;
use DomainException;
use PDOException;

/**
 * Types de cotisation. Rien n'est inventé : montant, périodicité et autorisation viennent des instances ; sans eux le type reste « non_configure ».
 * Un type se désactive (retour à non_configure), il ne se supprime jamais.
 */
final class ContributionTypeService
{
    public const KINDS = ['droit_adhesion' => 'Droit d\'adhésion', 'periodique' => 'Cotisation périodique', 'exceptionnelle' => 'Cotisation exceptionnelle'];

    public function __construct(private Database $db, private Audit $audit)
    {
    }

    /** @param array<string,mixed> $data name, kind, amount (texte, centimes ou vide), periodicity, authorization_ref, affects_account_id, status */
    public function save(?int $id, array $data, int $actor): int
    {
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 120) {
            throw new DomainException('Nom : obligatoire, 120 caractères au maximum.');
        }
        $kind = (string) ($data['kind'] ?? '');
        if (!isset(self::KINDS[$kind])) {
            throw new DomainException('Nature de cotisation invalide.');
        }
        $amount = $data['amount'] ?? null;
        if (is_string($amount)) {
            try {
                $amount = trim($amount) === '' ? null : Money::parse($amount);
            } catch (\InvalidArgumentException $e) {
                throw new DomainException('Montant : ' . $e->getMessage(), 0, $e);
            }
        }
        if ($amount !== null && (!is_int($amount) || $amount <= 0)) {
            throw new DomainException('Montant : doit être strictement positif.');
        }
        $periodicity = trim((string) ($data['periodicity'] ?? '')) ?: null;
        if ($periodicity !== null && !isset(Periods::VOCABULARY[$periodicity])) {
            throw new DomainException('Périodicité invalide.');
        }
        $ref = trim((string) ($data['authorization_ref'] ?? '')) ?: null;
        if ($ref !== null && mb_strlen($ref) > 100) {
            throw new DomainException('Référence de l\'autorisation : 100 caractères au maximum.');
        }
        $accountId = (int) ($data['affects_account_id'] ?? 0) ?: null;
        $status = (string) ($data['status'] ?? 'non_configure');
        if (!in_array($status, ['non_configure', 'actif'], true)) {
            throw new DomainException('État invalide.');
        }
        if ($kind === 'exceptionnelle' && $ref === null) {
            throw new DomainException('Une cotisation exceptionnelle exige la référence de son autorisation (décision ou procès-verbal).');
        }
        if ($status === 'actif') {
            $missing = array_keys(array_filter(['un montant' => $amount === null, 'la référence de l\'autorisation' => $ref === null, 'le compte crédité' => $accountId === null]));
            if ($missing !== []) {
                throw new DomainException('Activation impossible : il faut ' . implode(', ', $missing) . '.');
            }
        }
        return $this->db->atomic(function () use ($id, $name, $kind, $amount, $periodicity, $ref, $accountId, $status, $actor): int {
            if ($accountId !== null) {
                $active = $this->db->fetchValue('SELECT is_active FROM financial_accounts WHERE id = ?', [$accountId]);
                if ($active === null) {
                    throw new DomainException('Compte crédité inconnu.');
                }
                if ($status === 'actif' && (int) $active !== 1) {
                    throw new DomainException('Activation impossible : le compte crédité doit être actif.');
                }
            }
            $row = ['name' => $name, 'kind' => $kind, 'amount' => $amount === null ? null : Money::toDecimal($amount), 'periodicity' => $periodicity,
                'authorization_ref' => $ref, 'affects_account_id' => $accountId, 'status' => $status];
            try {
                if ($id === null) {
                    $id = $this->db->insert('contribution_types', $row);
                    $this->audit->log($actor, 'contributions.type_created', 'contribution_type', $id, null, $row);
                } else {
                    $old = $this->db->fetch('SELECT name, kind, amount, periodicity, authorization_ref, affects_account_id, status FROM contribution_types WHERE id = ? FOR UPDATE', [$id])
                        ?? throw new DomainException('Type de cotisation inconnu.');
                    if ($this->db->fetchValue("SELECT 1 FROM contribution_calls WHERE contribution_type_id = ? AND status <> 'annulee' LIMIT 1", [$id]) !== null
                        && ($old['kind'] !== $kind || $old['periodicity'] !== $periodicity || $old['amount'] !== ($row['amount']))) {
                        throw new DomainException('Ce type a déjà des appels : nature, périodicité et montant ne se modifient plus (libellé, autorisation et désactivation restent possibles).');
                    }
                    $this->db->update('contribution_types', $row, ['id' => $id]);
                    $this->audit->log($actor, 'contributions.type_updated', 'contribution_type', $id, $old, $row);
                }
            } catch (PDOException $e) {
                throw ($e->errorInfo[1] ?? 0) === 1062 ? new DomainException('Un type porte déjà ce nom.', 0, $e) : $e;
            }
            return $id;
        });
    }
}
