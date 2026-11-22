<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Core\NotFound;
use App\Core\Settings;
use App\Modules\Finance\Ledger;
use App\Modules\Social\SocialRuleViolation;
use App\Modules\Social\SocialTransitionInvalid;
use App\Modules\Social\SocialWorkflow;
use DomainException;
use PDOException;
use Tests\DatabaseTestCase;
use Tests\Support\SocialFixtures;

final class SocialRequestServiceTest extends DatabaseTestCase
{
    use SocialFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSocial();
    }

    /** @param class-string<\Throwable> $class */
    private function refuses(callable $fn, string $class, string $fragment = ''): void
    {
        try {
            $fn();
            self::fail("$class attendue");
        } catch (\Throwable $e) {
            self::assertInstanceOf($class, $e, $e->getMessage());
            self::assertStringContainsString($fragment, $e->getMessage());
        }
    }

    /** @return array{events:int, audits:int, decisions:int, reviews:int} */
    private function counts(int $id): array
    {
        return [
            'events' => (int) $this->db->fetchValue('SELECT COUNT(*) FROM social_request_events WHERE social_request_id = ?', [$id]),
            'audits' => (int) $this->db->fetchValue("SELECT COUNT(*) FROM audit_logs WHERE auditable_type = 'social_request' AND auditable_id = ?", [$id]),
            'decisions' => (int) $this->db->fetchValue('SELECT COUNT(*) FROM social_decisions WHERE social_request_id = ?', [$id]),
            'reviews' => (int) $this->db->fetchValue('SELECT COUNT(*) FROM social_request_reviews WHERE social_request_id = ?', [$id]),
        ];
    }

    private function validateType(array $params): void
    {
        $this->db->query('UPDATE social_request_types SET ceiling_amount = ?, allowed_aid_kinds = ?, deciding_body = ?, waiting_period_days = ?, params_decision_ref = ? WHERE id = ?',
            [$params['ceiling_amount'] ?? null, $params['allowed_aid_kinds'] ?? null, $params['deciding_body'] ?? null, $params['waiting_period_days'] ?? null, $params['ref'] ?? null, $this->typeId]);
    }

    /** Toutes les actions du service, appelées par un acteur qui détient chaque droit nécessaire. @return array<string, callable(int): mixed> */
    private function actions(): array
    {
        $s = $this->socialSvc();
        return [
            'assign' => fn (int $id) => $s->assign($id, $this->manager2, $this->manager),
            'requestDocuments' => fn (int $id) => $s->requestDocuments($id, 'Pièce', $this->manager),
            'resumeInstruction' => fn (int $id) => $s->resumeInstruction($id, $this->manager, null),
            'addReview' => fn (int $id) => $s->addReview($id, 'favorable', null, 'Avis', $this->manager),
            'submitForDecision' => fn (int $id) => $s->submitForDecision($id, $this->manager),
            'decide' => fn (int $id) => $s->decide($id, $this->grant(['aid_kind' => 'orientation', 'amount' => null]), $this->decider),
            'close' => fn (int $id) => $s->close($id, null, $this->manager),
        ];
    }

    // --- circuit ---

    public function test_register_creates_a_received_request_with_an_as_reference(): void
    {
        $id = $this->registerRequest(['priority' => 'urgente', 'contains_medical_data' => true]);
        $r = $this->db->fetch('SELECT * FROM social_requests WHERE id = ?', [$id]);
        self::assertSame('recue', $r['status']);
        self::assertMatchesRegularExpression('/^AS-' . Ledger::now()->format('Y') . '-\d{4}$/', $r['reference']);
        self::assertSame('urgente', $r['priority']);
        self::assertSame(1, (int) $r['contains_medical_data']);
        self::assertSame($this->creator, (int) $r['created_by']);
        $e = $this->db->fetch('SELECT * FROM social_request_events WHERE social_request_id = ?', [$id]);
        self::assertNull($e['from_status']);
        self::assertSame('recue', $e['to_status']);
    }

    public function test_register_validates_its_input(): void
    {
        $tomorrow = Ledger::now()->modify('+1 day')->format('Y-m-d');
        foreach ([['received_at' => $tomorrow], ['received_at' => '2026-02-30'], ['channel' => 'pigeon'], ['priority' => 'haute'], ['description' => '  '],
            ['type_id' => 999999], ['member_id' => 'x']] as $bad) {
            $this->refuses(fn () => $this->registerRequest($bad), DomainException::class);
        }
        $this->db->query('UPDATE social_request_types SET is_active = 0 WHERE id = ?', [$this->typeId]);
        $this->refuses(fn () => $this->registerRequest(), SocialRuleViolation::class, 'type');
        self::assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM social_requests WHERE member_id = ?', [$this->memberA]));
    }

    public function test_every_action_outside_the_table_is_refused_by_the_service(): void
    {
        foreach (array_diff(SocialWorkflow::STATUSES, ['en_paiement']) as $state) {
            foreach ($this->actions() as $action => $call) {
                if (SocialWorkflow::permits($state, $action)) {
                    continue;
                }
                $id = $this->requestIn($state);
                $before = $this->counts($id);
                $this->refuses(fn () => $call($id), DomainException::class);
                self::assertSame($state, $this->state($id), "$action depuis $state");
                self::assertSame($before, $this->counts($id), "$action depuis $state : rien n'est écrit");
            }
        }
    }

    public function test_forged_received_to_granted_is_refused(): void
    {
        $id = $this->registerRequest();
        $this->refuses(fn () => $this->socialSvc()->decide($id, $this->grant(), $this->decider), SocialTransitionInvalid::class, 'reçue');
        self::assertSame('recue', $this->state($id));
        self::assertSame(0, $this->counts($id)['decisions']);
    }

    public function test_cannot_submit_for_decision_without_review(): void
    {
        $id = $this->requestIn('en_instruction');
        $this->refuses(fn () => $this->socialSvc()->submitForDecision($id, $this->manager), SocialRuleViolation::class, 'avis');
        self::assertSame('en_instruction', $this->state($id));
        $this->socialSvc()->addReview($id, 'reserve', 2_500_000, 'Dossier à compléter', $this->manager);
        $this->socialSvc()->submitForDecision($id, $this->manager);
        self::assertSame('soumise_decision', $this->state($id));
    }

    public function test_decision_requires_reason_member_message_and_decision_ref(): void
    {
        $id = $this->requestIn('soumise_decision');
        foreach (['reason', 'member_message', 'decision_ref', 'deciding_body'] as $field) {
            foreach ([null, '   '] as $empty) {
                $this->refuses(fn () => $this->socialSvc()->decide($id, $this->grant([$field => $empty]), $this->decider), SocialRuleViolation::class);
            }
        }
        $this->refuses(fn () => $this->socialSvc()->decide($id, $this->grant(['decision' => 'peut-etre']), $this->decider), SocialRuleViolation::class);
        $this->refuses(fn () => $this->socialSvc()->decide($id, $this->grant(['aid_kind' => null]), $this->decider), SocialRuleViolation::class);
        $this->refuses(fn () => $this->socialSvc()->decide($id, $this->grant(['aid_kind' => 'virement']), $this->decider), SocialRuleViolation::class);
        $this->refuses(fn () => $this->socialSvc()->decide($id, $this->grant(['decision' => 'refusee']), $this->decider), SocialRuleViolation::class); // sorte et montant interdits
        $this->refuses(fn () => $this->socialSvc()->decide($id, $this->grant(['decided_at' => Ledger::now()->modify('+1 day')->format('Y-m-d')]), $this->decider), SocialRuleViolation::class);
        $this->refuses(fn () => $this->socialSvc()->decide($id, $this->grant(['extra' => 1]), $this->decider), SocialRuleViolation::class);
        self::assertSame(0, $this->counts($id)['decisions']);
        self::assertSame('soumise_decision', $this->state($id));
        $this->socialSvc()->decide($id, $this->grant(['decision' => 'refusee', 'aid_kind' => null, 'amount' => null]), $this->decider);
        self::assertSame('refusee', $this->state($id));
    }

    public function test_financial_decision_requires_positive_amount(): void
    {
        $id = $this->requestIn('soumise_decision');
        foreach ([null, 0, -100, '5000', 1.5] as $bad) {
            $this->refuses(fn () => $this->socialSvc()->decide($id, $this->grant(['amount' => $bad]), $this->decider), SocialRuleViolation::class, 'montant');
        }
        $did = $this->socialSvc()->decide($id, $this->grant(['amount' => 1_250_000]), $this->decider);
        self::assertSame('12500.00', $this->db->fetchValue('SELECT amount FROM social_decisions WHERE id = ?', [$did]));
        self::assertSame('accordee', $this->state($id));
    }

    public function test_non_financial_decision_has_no_amount(): void
    {
        $id = $this->requestIn('soumise_decision');
        $this->refuses(fn () => $this->socialSvc()->decide($id, $this->grant(['aid_kind' => 'orientation', 'amount' => 100_000]), $this->decider), SocialRuleViolation::class, 'montant');
        $did = $this->socialSvc()->decide($id, $this->grant(['aid_kind' => 'orientation', 'amount' => null]), $this->decider);
        $d = $this->db->fetch('SELECT aid_kind, amount FROM social_decisions WHERE id = ?', [$did]);
        self::assertSame(['aid_kind' => 'orientation', 'amount' => null], $d);
    }

    public function test_ceiling_applied_only_when_validated(): void
    {
        $this->validateType(['ceiling_amount' => '10000.00']); // valeur saisie mais non validée : ignorée
        $free = $this->requestIn('soumise_decision');
        $this->socialSvc()->decide($free, $this->grant(['amount' => 5_000_000]), $this->decider);
        self::assertSame('50000.00', $this->db->fetchValue('SELECT amount FROM social_decisions WHERE social_request_id = ?', [$free]));

        $this->validateType(['ceiling_amount' => '10000.00', 'ref' => 'PV-7']);
        $capped = $this->requestIn('soumise_decision');
        $this->refuses(fn () => $this->socialSvc()->decide($capped, $this->grant(['amount' => 1_000_001]), $this->decider), SocialRuleViolation::class, 'plafond');
        $this->socialSvc()->decide($capped, $this->grant(['amount' => 1_000_000]), $this->decider);
        self::assertSame('accordee', $this->state($capped));
    }

    public function test_aid_kind_restriction_applied_only_when_validated(): void
    {
        $this->validateType(['allowed_aid_kinds' => '["orientation"]']);
        $free = $this->requestIn('soumise_decision');
        $this->socialSvc()->decide($free, $this->grant(), $this->decider);
        self::assertSame('accordee', $this->state($free));

        $this->validateType(['allowed_aid_kinds' => '["orientation"]', 'ref' => 'PV-8']);
        $limited = $this->requestIn('soumise_decision');
        $this->refuses(fn () => $this->socialSvc()->decide($limited, $this->grant(), $this->decider), SocialRuleViolation::class, 'sorte');
        $this->socialSvc()->decide($limited, $this->grant(['aid_kind' => 'orientation', 'amount' => null]), $this->decider);
        self::assertSame('accordee', $this->state($limited));
    }

    public function test_deciding_body_of_a_validated_type_is_imposed(): void
    {
        $this->validateType(['deciding_body' => 'Bureau exécutif', 'ref' => 'PV-9']);
        $id = $this->requestIn('soumise_decision');
        $did = $this->socialSvc()->decide($id, $this->grant(['deciding_body' => null]), $this->decider);
        self::assertSame('Bureau exécutif', $this->db->fetchValue('SELECT deciding_body FROM social_decisions WHERE id = ?', [$did]));
    }

    public function test_decision_cannot_be_recorded_twice(): void
    {
        $id = $this->requestIn('soumise_decision');
        $did = $this->socialSvc()->decide($id, $this->grant(), $this->decider);
        $this->refuses(fn () => $this->socialSvc()->decide($id, $this->grant(), $this->decider), SocialTransitionInvalid::class);
        // Base : même en remettant l'état à la main, l'UNIQUE refuse une seconde décision ; le service traduit l'erreur.
        $this->db->query("UPDATE social_requests SET status = 'soumise_decision' WHERE id = ?", [$id]);
        $this->refuses(fn () => $this->socialSvc()->decide($id, $this->grant(), $this->decider), SocialRuleViolation::class, 'Une décision est déjà enregistrée.');
        $this->refuses(fn () => $this->db->insert('social_decisions', ['social_request_id' => $id, 'decision' => 'refusee', 'deciding_body' => 'X', 'decision_ref' => 'Y',
            'reason' => 'r', 'member_message' => 'm', 'decided_at' => '2026-01-01', 'recorded_by' => $this->decider]), PDOException::class);
        self::assertSame(1, $this->counts($id)['decisions']);
        self::assertSame($did, (int) $this->db->fetchValue('SELECT id FROM social_decisions WHERE social_request_id = ?', [$id]));
    }

    public function test_closed_request_is_read_only(): void
    {
        $id = $this->requestIn('cloturee');
        self::assertNotNull($this->db->fetchValue('SELECT closed_at FROM social_requests WHERE id = ?', [$id]));
        $before = $this->counts($id);
        foreach ($this->actions() as $action => $call) {
            $this->refuses(fn () => $call($id), SocialTransitionInvalid::class, 'clôturée');
        }
        self::assertSame([], $this->socialSvc()->allowedActions($id, $this->manager));
        self::assertSame([], $this->socialSvc()->allowedActions($id, $this->decider));
        self::assertSame('cloturee', $this->state($id));
        self::assertSame($before, $this->counts($id));
    }

    public function test_financial_grant_cannot_be_closed_before_payment(): void
    {
        $granted = $this->requestIn('accordee');
        $this->refuses(fn () => $this->socialSvc()->close($granted, null, $this->manager), SocialRuleViolation::class, 'paiement');
        self::assertSame('accordee', $this->state($granted));

        $paying = $this->requestIn('en_paiement');
        $this->refuses(fn () => $this->socialSvc()->close($paying, null, $this->manager), SocialRuleViolation::class, 'payé');
        $decision = (int) $this->db->fetchValue('SELECT id FROM social_decisions WHERE social_request_id = ?', [$paying]);
        $n = bin2hex(random_bytes(4));
        $account = $this->db->insert('financial_accounts', ['name' => "Fonds $n", 'opening_balance' => '0.00']);
        $category = $this->db->insert('financial_categories', ['name' => "Aide $n", 'direction' => 'depense']);
        $dr = $this->db->insert('disbursement_requests', ['reference' => "DEC-T-$n", 'account_id' => $account, 'category_id' => $category, 'amount' => '50000.00',
            'beneficiary_name' => 'Kouassi', 'purpose' => 'Aide', 'social_decision_id' => $decision, 'status' => 'approuve_2', 'initiated_by' => $this->manager]);
        $this->refuses(fn () => $this->socialSvc()->close($paying, null, $this->manager), SocialRuleViolation::class, 'payé');
        $this->db->query("UPDATE disbursement_requests SET status = 'paye' WHERE id = ?", [$dr]);
        $this->socialSvc()->close($paying, 'Aide versée', $this->manager);
        self::assertSame('cloturee', $this->state($paying));

        $refused = $this->requestIn('refusee');
        $this->socialSvc()->close($refused, null, $this->manager);
        self::assertSame('cloturee', $this->state($refused));
    }

    public function test_each_transition_writes_one_event_and_one_audit(): void
    {
        $s = $this->socialSvc();
        $id = $this->registerRequest();
        $steps = [
            ['recue', 'en_instruction', fn () => $s->assign($id, $this->manager, $this->manager, '10.0.0.1')],
            ['en_instruction', 'en_instruction', fn () => $s->assign($id, $this->manager2, $this->manager)],
            ['en_instruction', 'en_attente_pieces', fn () => $s->requestDocuments($id, 'Ordonnance', $this->manager)],
            ['en_attente_pieces', 'en_instruction', fn () => $s->resumeInstruction($id, $this->manager, 'Pièces reçues')],
            ['en_instruction', 'en_instruction', fn () => $s->addReview($id, 'favorable', 3_000_000, 'Avis motivé', $this->manager2)],
            ['en_instruction', 'soumise_decision', fn () => $s->submitForDecision($id, $this->manager)],
            ['soumise_decision', 'accordee', fn () => $s->decide($id, $this->grant(['aid_kind' => 'accompagnement', 'amount' => null]), $this->decider)],
            ['accordee', 'cloturee', fn () => $s->close($id, 'Accompagnement réalisé', $this->manager)],
        ];
        foreach ($steps as [$from, $to, $call]) {
            $before = $this->counts($id);
            $call();
            $after = $this->counts($id);
            self::assertSame($before['events'] + 1, $after['events'], "$from → $to : un événement");
            self::assertSame($before['audits'] + 1, $after['audits'], "$from → $to : un audit");
            $e = $this->db->fetch('SELECT from_status, to_status FROM social_request_events WHERE social_request_id = ? ORDER BY id DESC LIMIT 1', [$id]);
            self::assertSame(['from_status' => $from, 'to_status' => $to], $e);
            $a = $this->db->fetch("SELECT old_values, new_values, reference FROM audit_logs WHERE auditable_type = 'social_request' AND auditable_id = ? ORDER BY id DESC LIMIT 1", [$id]);
            self::assertSame($from, json_decode((string) $a['old_values'], true)['status']);
            self::assertSame($to, json_decode((string) $a['new_values'], true)['status']);
            self::assertStringStartsWith('AS-', (string) $a['reference']);
        }
        self::assertSame('10.0.0.1', $this->db->fetchValue("SELECT ip FROM audit_logs WHERE action = 'social.assigned' AND auditable_id = ?", [$id]));
        self::assertSame('Pièces reçues', $this->db->fetchValue("SELECT comment FROM social_request_events WHERE social_request_id = ? AND action = 'resumeInstruction'", [$id]));
    }

    public function test_audit_never_contains_free_text(): void
    {
        $s = $this->socialSvc();
        $id = $this->registerRequest(['description' => 'SENTINELLE-DESC']);
        $s->assign($id, $this->manager, $this->manager);
        $s->requestDocuments($id, 'SENTINELLE-PIECES', $this->manager);
        $s->resumeInstruction($id, $this->manager, 'SENTINELLE-COMMENT');
        $s->addReview($id, 'favorable', null, 'SENTINELLE-AVIS', $this->manager);
        $s->submitForDecision($id, $this->manager);
        $s->decide($id, $this->grant(['aid_kind' => 'orientation', 'amount' => null, 'reason' => 'SENTINELLE-MOTIF', 'member_message' => 'SENTINELLE-MESSAGE',
            'deciding_body' => 'SENTINELLE-ORGANE']), $this->decider);
        $s->close($id, 'SENTINELLE-CLOTURE', $this->manager);
        $rows = json_encode($this->db->fetchAll("SELECT action, old_values, new_values FROM audit_logs WHERE auditable_type = 'social_request' AND auditable_id = ?", [$id]));
        self::assertStringNotContainsString('SENTINELLE', (string) $rows);
        self::assertStringContainsString('PV-CA-12', (string) $rows, 'la référence de décision est tracée');
    }

    public function test_audit_is_the_last_lock(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Modules/Social/SocialRequestService.php');
        preg_match_all('/public function (\w+)\(/', $src, $m, PREG_OFFSET_CAPTURE);
        $bodies = [];
        foreach ($m[1] as [$name, $offset]) {
            $bodies[$name] = $this->block($src, strpos($src, '{', $offset));
        }
        foreach (['register', 'assign', 'requestDocuments', 'resumeInstruction', 'addReview', 'submitForDecision', 'decide', 'close'] as $action) {
            $body = $bodies[$action];
            $atomic = strpos($body, '$this->run(function');
            self::assertNotFalse($atomic, "$action : en transaction");
            $closure = $this->block($body, strpos($body, '{', $atomic));
            self::assertMatchesRegularExpression('/^\{\s*\$r = \$this->lock\(\$id, \$actor\);|^\{\s*\$this->deny\(\$this->permission/', $closure, "$action : le verrou (ou l'autorisation sans état) d'abord");
            $pos = (int) strrpos($closure, '$this->auditTransition(');
            self::assertGreaterThan(0, $pos, "$action : audité");
            $paren = strpos($closure, '(', $pos);
            $tail = substr($closure, $paren + strlen($this->block($closure, $paren, '(', ')')));
            self::assertMatchesRegularExpression('/^;\s*(return \$\w+;\s*)?\}$/', $tail, "$action : rien après l'audit");
            $event = strrpos($closure, '$this->event(');
            self::assertNotFalse($event, "$action : un événement");
            self::assertLessThan($pos, $event, "$action : l'événement avant l'audit");
        }
        $helper = $this->block($src, strpos($src, '{', strpos($src, 'private function auditTransition(')));
        self::assertSame(1, substr_count($helper, ';'), 'auditTransition ne fait que l\'appel à Audit::log');
        self::assertStringContainsString('$this->audit->log(', $helper);
        $lock = $this->block($src, strpos($src, '{', strpos($src, 'private function lock(')));
        self::assertStringContainsString('SELECT * FROM social_requests WHERE id = ? FOR UPDATE', $lock);
    }

    private function block(string $s, int $start, string $open = '{', string $close = '}'): string
    {
        $depth = 0;
        for ($i = $start, $n = strlen($s); $i < $n; $i++) {
            $depth += $s[$i] === $open ? 1 : ($s[$i] === $close ? -1 : 0);
            if ($depth === 0) {
                return substr($s, $start, $i - $start + 1);
            }
        }
        self::fail('bloc non fermé');
    }

    public function test_allowed_actions_follow_the_same_predicates(): void
    {
        $s = $this->socialSvc();
        $id = $this->registerRequest();
        self::assertSame(['assign'], $s->allowedActions($id, $this->manager));
        self::assertSame([], $s->allowedActions($id, $this->decider));
        $s->assign($id, $this->manager, $this->manager);
        self::assertSame(['assign', 'requestDocuments', 'addReview'], $s->allowedActions($id, $this->manager), 'pas de soumission sans avis');
        $s->addReview($id, 'favorable', null, 'ok', $this->manager);
        self::assertSame(['assign', 'requestDocuments', 'addReview', 'submitForDecision'], $s->allowedActions($id, $this->manager));
        $s->submitForDecision($id, $this->manager);
        self::assertSame([], $s->allowedActions($id, $this->manager));
        self::assertSame(['decide'], $s->allowedActions($id, $this->decider));
        $s->decide($id, $this->grant(), $this->decider);
        self::assertSame([], $s->allowedActions($id, $this->manager), 'aide financière : pas de clôture avant paiement');
        self::assertSame([], $s->allowedActions(999999, $this->manager));
    }

    // --- droits et périmètre ---

    public function test_register_requires_social_create(): void
    {
        foreach ([$this->manager, $this->decider, $this->plain] as $actor) {
            $this->refuses(fn () => $this->socialSvc()->register(['member_id' => $this->memberA, 'type_id' => $this->typeId, 'received_at' => Ledger::now()->format('Y-m-d'),
                'channel' => 'guichet', 'description' => 'x'], $actor), SocialRuleViolation::class, 'refusée');
        }
        $inactive = $this->socialUser('inactive', ['so_create'], null, false);
        $this->refuses(fn () => $this->socialSvc()->register(['member_id' => $this->memberA, 'type_id' => $this->typeId, 'received_at' => Ledger::now()->format('Y-m-d'),
            'channel' => 'guichet', 'description' => 'x'], $inactive), SocialRuleViolation::class);
        self::assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM social_requests WHERE member_id = ?', [$this->memberA]));
    }

    public function test_register_for_member_outside_scope_is_not_found(): void
    {
        $data = ['type_id' => $this->typeId, 'received_at' => Ledger::now()->format('Y-m-d'), 'channel' => 'delegue', 'description' => 'x'];
        $this->refuses(fn () => $this->socialSvc()->register($data + ['member_id' => $this->memberB], $this->zoneCreator), NotFound::class);
        $this->refuses(fn () => $this->socialSvc()->register($data + ['member_id' => 999999], $this->zoneCreator), NotFound::class);
        $deleted = $this->db->insert('members', ['member_number' => 'DEL' . bin2hex(random_bytes(3)), 'last_name' => 'X', 'first_names' => 'Y', 'zone_id' => $this->zoneA, 'deleted_at' => '2026-01-01 00:00:00']);
        $this->refuses(fn () => $this->socialSvc()->register($data + ['member_id' => $deleted], $this->creator), NotFound::class);
        $noZone = $this->socialUser('nozone', ['so_zone']);
        $this->refuses(fn () => $this->socialSvc()->register($data + ['member_id' => $this->memberA], $noZone), NotFound::class);
        $id = $this->socialSvc()->register($data + ['member_id' => $this->memberA], $this->zoneCreator);
        self::assertSame('recue', $this->state($id));
        // Hors zone : les actions et allowedActions répondent « introuvable », comme la lecture.
        $zoneManager = $this->socialUser('zmanager', ['so_zone'], $this->zoneB);
        $this->db->query("INSERT IGNORE INTO roles (name, label) VALUES ('so_zmanage', 'so_zmanage')");
        $this->db->query("INSERT IGNORE INTO role_permissions (role_id, permission_id) SELECT r.id, p.id FROM roles r, permissions p WHERE r.name = 'so_zmanage' AND p.name = 'social.manage'");
        $this->db->query("INSERT INTO user_roles (user_id, role_id) SELECT ?, id FROM roles WHERE name = 'so_zmanage'", [$zoneManager]);
        $this->refuses(fn () => $this->socialSvc()->assign($id, $this->manager, $zoneManager), NotFound::class);
        self::assertSame([], $this->socialSvc()->allowedActions($id, $zoneManager));
    }

    public function test_beneficiary_of_another_member_refused(): void
    {
        $own = $this->db->insert('member_beneficiaries', ['member_id' => $this->memberA, 'last_name' => 'Kouassi', 'first_names' => 'Koffi', 'relationship' => 'enfant']);
        $other = $this->db->insert('member_beneficiaries', ['member_id' => $this->memberB, 'last_name' => 'Konan', 'first_names' => 'Affoué', 'relationship' => 'enfant']);
        $this->refuses(fn () => $this->registerRequest(['beneficiary_id' => $other]), SocialRuleViolation::class, 'ayant droit');
        $this->refuses(fn () => $this->registerRequest(['beneficiary_id' => 999999]), SocialRuleViolation::class, 'ayant droit');
        $id = $this->registerRequest(['beneficiary_id' => $own]);
        self::assertSame($own, (int) $this->db->fetchValue('SELECT beneficiary_id FROM social_requests WHERE id = ?', [$id]));
    }

    public function test_decide_requires_social_decide(): void
    {
        $id = $this->requestIn('soumise_decision');
        $inactive = $this->socialUser('olddecider', ['so_decide'], null, false);
        foreach ([$this->manager, $this->creator, $this->plain] as $actor) {
            $this->refuses(fn () => $this->socialSvc()->decide($id, $this->grant(), $actor), SocialRuleViolation::class, 'refusée');
        }
        $this->refuses(fn () => $this->socialSvc()->decide($id, $this->grant(), $inactive), NotFound::class); // compte désactivé : plus aucun périmètre
        self::assertSame(0, $this->counts($id)['decisions']);
        // Le décideur ne peut ni instruire ni clôturer.
        $this->refuses(fn () => $this->socialSvc()->addReview($this->requestIn('en_instruction'), 'favorable', null, 'x', $this->decider), SocialRuleViolation::class);
    }

    public function test_assignee_must_hold_social_manage(): void
    {
        $id = $this->registerRequest();
        $inactive = $this->socialUser('oldmanager', ['so_manage'], null, false);
        foreach ([$this->decider, $this->plain, $inactive, 999999] as $assignee) {
            $this->refuses(fn () => $this->socialSvc()->assign($id, $assignee, $this->manager), SocialRuleViolation::class, 'affect');
        }
        $this->refuses(fn () => $this->socialSvc()->assign($id, $this->manager, $this->plain), SocialRuleViolation::class, 'refusée');
        self::assertSame('recue', $this->state($id));
        $this->socialSvc()->assign($id, $this->manager2, $this->manager);
        self::assertSame($this->manager2, (int) $this->db->fetchValue('SELECT assigned_to FROM social_requests WHERE id = ?', [$id]));
        self::assertSame('en_instruction', $this->state($id));
        $this->refuses(fn () => $this->socialSvc()->assign($id, $this->manager2, $this->manager), SocialRuleViolation::class, 'déjà');
    }

    // --- règles non inventées ---

    public function test_no_refusal_based_on_member_status_arrears_or_waiting_period(): void
    {
        $this->db->query("UPDATE members SET status = 'suspendu', joined_at = ? WHERE id = ?", [Ledger::now()->format('Y-m-d'), $this->memberA]);
        $n = bin2hex(random_bytes(3));
        $ct = $this->db->insert('contribution_types', ['name' => "T $n", 'kind' => 'periodique', 'amount' => '10000.00', 'status' => 'actif']);
        $this->db->insert('contribution_calls', ['member_id' => $this->memberA, 'contribution_type_id' => $ct, 'period_label' => 'P1', 'period_start' => '2025-01-01',
            'period_end' => '2025-01-31', 'amount_due' => '10000.00', 'due_date' => '2025-02-01', 'status' => 'appelee']);
        $this->validateType(['waiting_period_days' => 365, 'ref' => 'PV-10']); // même validée, la carence n'est qu'une information
        $id = $this->requestIn('soumise_decision');
        $this->socialSvc()->decide($id, $this->grant(), $this->decider);
        self::assertSame('accordee', $this->state($id));
        $c = $this->socialSvc()->context($id);
        self::assertSame('suspendu', $c['member_status']);
        self::assertSame(1, $c['unpaid_calls']);
        self::assertSame(365, $c['waiting_period_days']);
    }

    public function test_context_never_says_up_to_date(): void
    {
        $admin = $this->socialUser('validator', []);
        (new Settings($this->db))->validate('contributions', 'up_to_date_rule', 0, $admin, 'PV-11');
        try {
            $id = $this->registerRequest();
            $c = $this->socialSvc()->context($id);
            self::assertSame(['member_status', 'unpaid_calls', 'joined_at', 'waiting_period_days', 'ceiling'], array_keys($c));
            self::assertStringNotContainsStringIgnoringCase('à jour', json_encode($c, JSON_UNESCAPED_UNICODE));
            self::assertStringNotContainsString('up_to_date', json_encode($c));
            self::assertNull($c['waiting_period_days']);
            self::assertNull($c['ceiling']);
        } finally {
            $this->db->query("UPDATE settings SET `value` = NULL, status = 'non_configure', validated_by = NULL, validated_at = NULL, decision_ref = NULL WHERE `group` = 'contributions' AND `key` = 'up_to_date_rule'");
        }
    }
}
