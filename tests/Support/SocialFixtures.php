<?php
declare(strict_types=1);

namespace Tests\Support;

use App\Core\Audit;
use App\Core\Gate;
use App\Core\Sequence;
use App\Modules\Finance\Ledger;
use App\Modules\Social\SocialRequestService;

/** Jeu d'essai du circuit social : zones A/B, un adhérent par zone, enregistreur, deux gestionnaires, un décideur, un compte sans droit. */
trait SocialFixtures
{
    protected int $zoneA;
    protected int $zoneB;
    protected int $memberA;
    protected int $memberB;
    protected int $typeId;
    protected int $creator;
    protected int $zoneCreator;
    protected int $manager;
    protected int $manager2;
    protected int $decider;
    protected int $plain;

    protected function setUpSocial(): void
    {
        $this->db->query("UPDATE social_request_types SET criteria_text = NULL, ceiling_amount = NULL, waiting_period_days = NULL, deciding_body = NULL, allowed_aid_kinds = NULL,
            params_decision_ref = NULL, params_validated_by = NULL, params_validated_at = NULL, is_active = 1");
        foreach (['so_create' => ['social.create', 'social.view', 'members.scope.all'], 'so_zone' => ['social.create', 'social.view'],
            'so_manage' => ['social.manage', 'social.view', 'members.scope.all'], 'so_decide' => ['social.decide', 'social.view', 'members.scope.all'],
            'so_view' => ['social.view', 'members.scope.all']] as $role => $perms) {
            $this->db->query('INSERT IGNORE INTO roles (name, label) VALUES (?, ?)', [$role, $role]);
            foreach ($perms as $p) {
                $this->db->query('INSERT IGNORE INTO role_permissions (role_id, permission_id) SELECT r.id, p.id FROM roles r, permissions p WHERE r.name = ? AND p.name = ?', [$role, $p]);
            }
        }
        $n = bin2hex(random_bytes(3));
        $this->zoneA = $this->db->insert('zones', ['name' => "SA $n", 'type' => 'region']);
        $this->zoneB = $this->db->insert('zones', ['name' => "SB $n", 'type' => 'region']);
        $this->memberA = $this->db->insert('members', ['member_number' => "SA$n", 'last_name' => 'Kouassi', 'first_names' => 'Ama', 'zone_id' => $this->zoneA, 'status' => 'actif']);
        $this->memberB = $this->db->insert('members', ['member_number' => "SB$n", 'last_name' => 'Konan', 'first_names' => 'Yao', 'zone_id' => $this->zoneB, 'status' => 'actif']);
        $this->typeId = (int) $this->db->fetchValue("SELECT id FROM social_request_types WHERE code = 'maladie'");
        $this->creator = $this->socialUser('creator', ['so_create']);
        $this->zoneCreator = $this->socialUser('zone', ['so_zone'], $this->zoneA);
        $this->manager = $this->socialUser('manager', ['so_manage']);
        $this->manager2 = $this->socialUser('manager2', ['so_manage']);
        $this->decider = $this->socialUser('decider', ['so_decide']);
        $this->plain = $this->socialUser('plain', ['so_view']);
    }

    /** @param list<string> $roles */
    protected function socialUser(string $name, array $roles, ?int $zone = null, bool $active = true): int
    {
        $id = $this->db->insert('users', ['name' => $name, 'email' => $name . bin2hex(random_bytes(4)) . '@so.test', 'password' => 'x', 'is_active' => $active ? 1 : 0, 'zone_id' => $zone]);
        foreach ($roles as $role) {
            $this->db->query('INSERT INTO user_roles (user_id, role_id) SELECT ?, id FROM roles WHERE name = ?', [$id, $role]);
        }
        return $id;
    }

    protected function socialSvc(): SocialRequestService
    {
        return new SocialRequestService($this->db, new Audit($this->db, 'base64:' . base64_encode(str_repeat('k', 32))), new Sequence($this->db), new Gate($this->db));
    }

    /** @param array<string,mixed> $over */
    protected function registerRequest(array $over = []): int
    {
        return $this->socialSvc()->register($over + ['member_id' => $this->memberA, 'type_id' => $this->typeId, 'received_at' => Ledger::now()->modify('-1 day')->format('Y-m-d'),
            'channel' => 'guichet', 'priority' => 'normale', 'description' => 'Demande d\'aide'], $this->creator);
    }

    /** @param array<string,mixed> $over */
    protected function grant(array $over = []): array
    {
        return $over + ['decision' => 'accordee', 'aid_kind' => 'financiere', 'amount' => 5_000_000, 'reason' => 'Dossier complet', 'member_message' => 'Votre demande est accordée.',
            'decision_ref' => 'PV-CA-12', 'deciding_body' => 'Conseil d\'administration'];
    }

    /** Amène une nouvelle demande dans l'état voulu par le service (en_paiement : forcé en base, l'action relève de la tâche 5). */
    protected function requestIn(string $state, string $aidKind = 'financiere'): int
    {
        $s = $this->socialSvc();
        $id = $this->registerRequest();
        if ($state === 'recue') {
            return $id;
        }
        $s->assign($id, $this->manager, $this->manager);
        if ($state === 'en_instruction') {
            return $id;
        }
        if ($state === 'en_attente_pieces') {
            $s->requestDocuments($id, 'Certificat à fournir', $this->manager);
            return $id;
        }
        $s->addReview($id, 'favorable', null, 'Avis favorable', $this->manager);
        $s->submitForDecision($id, $this->manager);
        if ($state === 'soumise_decision') {
            return $id;
        }
        $aidKind = $state === 'cloturee' ? 'orientation' : $aidKind; // une aide financière ne se clôture qu'après paiement
        $s->decide($id, $state === 'refusee' ? $this->grant(['decision' => 'refusee', 'aid_kind' => null, 'amount' => null])
            : $this->grant($aidKind === 'financiere' ? [] : ['aid_kind' => $aidKind, 'amount' => null]), $this->decider);
        if ($state === 'en_paiement') {
            $this->db->query("UPDATE social_requests SET status = 'en_paiement' WHERE id = ?", [$id]);
        }
        if ($state === 'cloturee') {
            $s->close($id, null, $this->manager);
        }
        return $id;
    }

    protected function state(int $id): string
    {
        return (string) $this->db->fetchValue('SELECT status FROM social_requests WHERE id = ?', [$id]);
    }
}
