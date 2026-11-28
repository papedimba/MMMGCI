<?php
declare(strict_types=1);

namespace App\Modules\Social;

use App\Core\Audit;
use App\Core\Database;
use App\Core\Gate;
use App\Core\Money;
use App\Core\NotFound;
use DomainException;
use PDOException;

/**
 * Types de demande (CDC art. 9) et leurs paramètres. Critères, plafond, carence, organe et sortes d'aide viennent des instances :
 * tant que `params_decision_ref` est NULL, les lectures du circuit renvoient NULL (« non configuré ») et aucune règle n'est appliquée.
 */
final class SocialTypeService
{
    public const AID_KINDS = ['financiere' => 'Aide financière', 'orientation' => 'Orientation', 'accompagnement' => 'Accompagnement', 'materielle' => 'Aide matérielle'];
    private const PARAMS = ['criteria_text', 'ceiling_amount', 'waiting_period_days', 'deciding_body', 'allowed_aid_kinds'];

    public function __construct(private Database $db, private Audit $audit, private Gate $gate)
    {
    }

    /** @return list<array<string,mixed>> */
    public function all(bool $activeOnly = false): array
    {
        return $this->db->fetchAll('SELECT * FROM social_request_types' . ($activeOnly ? ' WHERE is_active = 1' : '') . ' ORDER BY id');
    }

    /** @return array<string,mixed> */
    public function find(int $id): array
    {
        return $this->db->fetch('SELECT * FROM social_request_types WHERE id = ?', [$id]) ?? throw new NotFound("Type $id introuvable.");
    }

    /** @param array<string,mixed> $params clés de PARAMS (absente ou NULL = non configuré) */
    public function configure(int $id, array $params, string $decisionRef, int $actor): void
    {
        $ref = $this->authorize($actor, $decisionRef);
        $row = [
            'criteria_text' => $this->text($params['criteria_text'] ?? null, 5000, 'Critères'),
            'ceiling_amount' => null, 'waiting_period_days' => null,
            'deciding_body' => $this->text($params['deciding_body'] ?? null, 160, 'Organe compétent'),
            'allowed_aid_kinds' => null,
        ];
        $c = $params['ceiling_amount'] ?? null;
        if ($c !== null) {
            if (!is_int($c) || $c <= 0) {
                throw new DomainException('Plafond : doit être strictement positif.');
            }
            $row['ceiling_amount'] = Money::toDecimal($c);
        }
        $w = $params['waiting_period_days'] ?? null;
        if ($w !== null) {
            if (!is_int($w) || $w < 0) {
                throw new DomainException('Délai de carence : nombre de jours entier, zéro ou plus.');
            }
            $row['waiting_period_days'] = $w;
        }
        $kinds = $params['allowed_aid_kinds'] ?? null;
        if ($kinds !== null) {
            if (!is_array($kinds) || array_diff($kinds, array_keys(self::AID_KINDS)) !== []) {
                throw new DomainException('Sorte d\'aide inconnue : choisissez parmi les sortes proposées.');
            }
            $row['allowed_aid_kinds'] = $kinds === [] ? null : json_encode(array_values(array_unique($kinds)), JSON_THROW_ON_ERROR);
        }
        $set = array_keys(array_filter($row, static fn ($v): bool => $v !== null));
        if ($set === []) {
            throw new DomainException('Renseignez au moins un paramètre décidé par les instances.');
        }
        $this->db->atomic(function () use ($id, $row, $ref, $actor, $set): void {
            $this->lock($id);
            $this->db->query('UPDATE social_request_types SET criteria_text = ?, ceiling_amount = ?, waiting_period_days = ?, deciding_body = ?, allowed_aid_kinds = ?,
                params_decision_ref = ?, params_validated_by = ?, params_validated_at = NOW() WHERE id = ?',
                [...array_values($row), $ref, $actor, $id]);
            $this->audit->log($actor, 'social.type_configured', 'social_request_type', $id, null, ['changed' => $set, 'decision_ref' => $ref]);
        });
    }

    public function setActive(int $id, bool $active, string $decisionRef, int $actor): void
    {
        $ref = $this->authorize($actor, $decisionRef);
        $this->db->atomic(function () use ($id, $active, $ref, $actor): void {
            $this->lock($id);
            $this->db->query('UPDATE social_request_types SET is_active = ? WHERE id = ?', [$active ? 1 : 0, $id]);
            $this->audit->log($actor, 'social.type_activation', 'social_request_type', $id, null, ['is_active' => $active, 'decision_ref' => $ref]);
        });
    }

    /** @param array<string,mixed> $data code, name, action_text */
    public function create(array $data, string $decisionRef, int $actor): int
    {
        $ref = $this->authorize($actor, $decisionRef);
        $code = trim((string) ($data['code'] ?? ''));
        if (preg_match('/^[a-z][a-z0-9_]{1,19}$/', $code) !== 1) {
            throw new DomainException('Code : 2 à 20 caractères, minuscules, chiffres et tiret bas, commençant par une lettre.');
        }
        $name = $this->text($data['name'] ?? null, 80, 'Libellé') ?? throw new DomainException('Libellé : obligatoire, 80 caractères au maximum.');
        $action = $this->text($data['action_text'] ?? null, 255, 'Action') ?? throw new DomainException('Action : obligatoire, 255 caractères au maximum.');
        return $this->db->atomic(function () use ($code, $name, $action, $ref, $actor): int {
            try {
                $id = $this->db->insert('social_request_types', ['code' => $code, 'name' => $name, 'action_text' => $action]);
            } catch (PDOException $e) {
                throw ($e->errorInfo[1] ?? 0) === 1062 ? new DomainException('Un type porte déjà ce code.', 0, $e) : $e;
            }
            $this->audit->log($actor, 'social.type_created', 'social_request_type', $id, null, ['code' => $code, 'decision_ref' => $ref]);
            return $id;
        });
    }

    public function ceilingFor(int $typeId): ?int
    {
        $v = $this->validated($typeId, 'ceiling_amount');
        return $v === null ? null : Money::fromDecimal((string) $v);
    }

    public function waitingPeriodFor(int $typeId): ?int
    {
        $v = $this->validated($typeId, 'waiting_period_days');
        return $v === null ? null : (int) $v;
    }

    public function decidingBodyFor(int $typeId): ?string
    {
        $v = $this->validated($typeId, 'deciding_body');
        return $v === null ? null : (string) $v;
    }

    /** @return ?list<string> */
    public function allowedAidKinds(int $typeId): ?array
    {
        $v = $this->validated($typeId, 'allowed_aid_kinds');
        return $v === null ? null : json_decode((string) $v, true, 512, JSON_THROW_ON_ERROR);
    }

    private function validated(int $typeId, string $col): mixed
    {
        assert(in_array($col, self::PARAMS, true));
        return $this->db->fetchValue("SELECT $col FROM social_request_types WHERE id = ? AND params_decision_ref IS NOT NULL", [$typeId]);
    }

    private function lock(int $id): void
    {
        $this->db->fetchValue('SELECT id FROM social_request_types WHERE id = ? FOR UPDATE', [$id]) ?? throw new NotFound("Type $id introuvable.");
    }

    private function authorize(int $actor, string $decisionRef): string
    {
        if (!$this->gate->allows($actor, 'settings.validate')) {
            throw new DomainException('Ce paramètre est décidé par les instances : il demande le droit « valider les paramètres ».');
        }
        $ref = trim($decisionRef);
        if ($ref === '' || mb_strlen($ref) > 60) {
            throw new DomainException('La référence de la décision est obligatoire (60 caractères au maximum).');
        }
        return $ref;
    }

    private function text(mixed $v, int $max, string $label): ?string
    {
        $t = trim((string) $v);
        if (mb_strlen($t) > $max) {
            throw new DomainException("$label : $max caractères au maximum.");
        }
        return $t === '' ? null : $t;
    }
}
