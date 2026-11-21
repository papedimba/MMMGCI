<?php
declare(strict_types=1);

namespace Tests\Support;

use App\Core\Audit;
use App\Core\Database;
use App\Core\Gate;
use App\Core\Sequence;
use App\Core\Settings;
use App\Modules\Disbursements\DisbursementService;
use App\Modules\Finance\Ledger;
use DomainException;

/** Jeu d'essai du circuit de décaissement : initiateur, deux approbateurs, un payeur, un compte approvisionné, une catégorie de dépense. */
trait DisbursementFixtures
{
    protected int $account;
    protected int $category;
    protected int $initiator;
    protected int $approverA;
    protected int $approverB;
    protected int $payer;

    protected function setUpCircuit(string $opening = '1000000.00'): void
    {
        foreach (['disbursement_payments', 'disbursement_approvals', 'disbursement_revisions'] as $t) {
            $this->db->query("DELETE FROM $t");
        }
        $this->db->query("DELETE FROM financial_attachments WHERE attachable_type = 'disbursement_request'");
        $this->db->query('DELETE FROM disbursement_requests');
        $this->db->query('DELETE FROM users'); // user_roles en cascade : chaque test part d'une population d'approbateurs connue
        $this->db->query("UPDATE settings SET `value` = NULL, status = 'non_configure', validated_by = NULL, validated_at = NULL, decision_ref = NULL WHERE `group` = 'finance'");
        $this->db->query('DELETE FROM approval_rules');
        $this->db->query("INSERT INTO approval_rules (min_amount, max_amount, approvals_required) VALUES (0, NULL, 2)");
        foreach (['dec_init' => ['disbursement.initiate'], 'dec_appr' => ['disbursement.approve'], 'dec_pay' => ['disbursement.pay'],
            'dec_init_appr' => ['disbursement.initiate', 'disbursement.approve'], 'dec_init_pay' => ['disbursement.initiate', 'disbursement.pay']] as $role => $perms) {
            $this->db->query('INSERT IGNORE INTO roles (name, label) VALUES (?, ?)', [$role, $role]);
            foreach ($perms as $p) {
                $this->db->query('INSERT IGNORE INTO role_permissions (role_id, permission_id) SELECT r.id, p.id FROM roles r, permissions p WHERE r.name = ? AND p.name = ?', [$role, $p]);
            }
        }
        $this->initiator = $this->user('init', ['dec_init']);
        $this->approverA = $this->user('appA', ['dec_appr']);
        $this->approverB = $this->user('appB', ['dec_appr']);
        $this->payer = $this->user('payer', ['dec_pay']);
        $n = bin2hex(random_bytes(4));
        $this->account = $this->db->insert('financial_accounts', ['name' => "Caisse $n", 'opening_balance' => $opening]);
        $this->category = $this->db->insert('financial_categories', ['name' => "Aides $n", 'direction' => 'depense']);
    }

    /** @param list<string> $roles */
    protected function user(string $name, array $roles, bool $active = true): int
    {
        $id = $this->db->insert('users', ['name' => $name, 'email' => $name . bin2hex(random_bytes(4)) . '@dec.test', 'password' => 'x', 'is_active' => $active ? 1 : 0]);
        foreach ($roles as $role) {
            $this->db->query('INSERT INTO user_roles (user_id, role_id) SELECT ?, id FROM roles WHERE name = ?', [$id, $role]);
        }
        return $id;
    }

    protected function audit(?Database $db = null): Audit
    {
        return new Audit($db ?? $this->db, (string) self::config()->get('APP_KEY'));
    }

    protected function ledger(?Database $db = null): Ledger
    {
        $db ??= $this->db;
        return new Ledger($db, $this->audit($db), new Sequence($db));
    }

    protected function svc(?Database $db = null): DisbursementService
    {
        $db ??= $this->db;
        return new DisbursementService($db, $this->audit($db), $this->ledger($db), new Sequence($db), new Settings($db), new Gate($db));
    }

    protected function settings(): Settings
    {
        return new Settings($this->db);
    }

    /** Fichier téléversé par $by (l'initiateur par défaut). */
    protected function file(?int $by = null): int
    {
        return $this->db->insert('files', ['path' => 'p/' . bin2hex(random_bytes(4)), 'original_name' => 'facture-secrete.pdf', 'mime' => 'application/pdf', 'size' => 10,
            'sha256' => str_repeat('c', 64), 'uploaded_by' => $by ?? $this->initiator]);
    }

    protected function revision(int $id): int
    {
        return (int) $this->db->fetchValue('SELECT revision FROM disbursement_requests WHERE id = ?', [$id]);
    }

    /** Approbation sur la révision courante (celle que l'approbateur a sous les yeux), sauf $seenRevision explicite. */
    protected function approveNow(int $id, int $approver, ?string $comment = null, ?int $seenRevision = null): void
    {
        $this->svc()->approve($id, $approver, $seenRevision ?? $this->revision($id), $comment);
    }

    protected function rejectNow(int $id, int $actor, string $reason): void
    {
        $this->svc()->reject($id, $actor, $this->revision($id), $reason);
    }

    protected function draft(int $cents = 5_000_000, ?int $initiator = null): int
    {
        return $this->svc()->create(['amount' => $cents, 'beneficiary_name' => 'Veuve Kouassi', 'account_id' => $this->account, 'category_id' => $this->category,
            'purpose' => 'Aide décès'], $initiator ?? $this->initiator);
    }

    protected function submitted(int $cents = 5_000_000, ?int $initiator = null): int
    {
        $id = $this->draft($cents, $initiator);
        $this->svc()->revise($id, ['add_files' => [$this->file($initiator) => 'Acte de décès']], '', $initiator ?? $this->initiator);
        $this->svc()->submit($id, $initiator ?? $this->initiator);
        return $id;
    }

    protected function approved(int $cents = 5_000_000, ?int $initiator = null): int
    {
        $id = $this->submitted($cents, $initiator);
        $this->approveNow($id, $this->approverA, 'ok');
        $this->approveNow($id, $this->approverB);
        return $id;
    }

    /** Paiement de ce que le payeur voit à l'écran (révision et montant courants), sauf valeurs vues explicites. */
    protected function payNow(int $id, ?int $payer = null, ?string $key = null, string $method = 'virement', ?int $proof = null, ?int $seenRevision = null, ?int $seenAmount = null): int
    {
        $payer ??= $this->payer;
        $amount = \App\Core\Money::fromDecimal((string) $this->db->fetchValue('SELECT amount FROM disbursement_requests WHERE id = ?', [$id]));
        return $this->svc()->pay($id, $payer, $seenRevision ?? $this->revision($id), $seenAmount ?? $amount, ['method' => $method], $proof ?? $this->file($payer), $key ?? bin2hex(random_bytes(16)));
    }

    protected function state(int $id): string
    {
        return (string) $this->db->fetchValue('SELECT status FROM disbursement_requests WHERE id = ?', [$id]);
    }

    /** @param class-string<DomainException> $class */
    protected function refuses(callable $f, string $class, string $needle = ''): void
    {
        try {
            $f();
            $this->fail("refus attendu ($class)");
        } catch (DomainException $e) {
            $this->assertInstanceOf($class, $e, $e->getMessage());
            $this->assertStringContainsString($needle, $e->getMessage());
        }
    }
}
