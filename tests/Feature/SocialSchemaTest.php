<?php
declare(strict_types=1);

namespace Tests\Feature;

use Tests\DatabaseTestCase;

final class SocialSchemaTest extends DatabaseTestCase
{
    private function expectSqlError(string $sql, array $params = []): void
    {
        try {
            $this->db->query($sql, $params);
        } catch (\PDOException) {
            $this->addToAssertionCount(1);
            return;
        }
        $this->fail('Contrainte attendue : ' . $sql);
    }

    private function insert(string $sql, array $params): int
    {
        $this->db->query($sql, $params);
        return (int) $this->db->pdo()->lastInsertId();
    }

    /** @return array{0:int,1:int,2:int} user, demande, type */
    private function fixtures(): array
    {
        $n = bin2hex(random_bytes(4));
        $u = $this->insert('INSERT INTO users (name, email, password) VALUES (?, ?, ?)', ["U $n", "u$n@example.test", 'x']);
        $m = $this->insert('INSERT INTO members (member_number, last_name, first_names) VALUES (?, ?, ?)', ["T-$n", 'Test', 'Soc']);
        $t = (int) $this->db->fetchValue("SELECT id FROM social_request_types WHERE code = 'urgences'");
        $r = $this->insert("INSERT INTO social_requests (reference, member_id, type_id, received_at, channel, description, created_by) VALUES (?, ?, ?, '2026-01-05', 'guichet', 'd', ?)", ["AS-$n", $m, $t, $u]);
        return [$u, $r, $t];
    }

    public function test_migrations_apply_on_fresh_database(): void
    {
        $tables = array_merge(...array_map('array_values', $this->db->fetchAll("SHOW TABLES LIKE 'social\\_%'")));
        foreach (['social_request_types', 'social_requests', 'social_request_medical', 'social_request_documents', 'social_request_reviews', 'social_decisions', 'social_request_events', 'social_request_notes'] as $t) {
            $this->assertContains($t, $tables);
        }
        $this->assertSame(2, (int) $this->db->fetchValue("SELECT COUNT(*) FROM schema_migrations WHERE version IN ('0025_social.sql', '0026_social_permissions.sql')"));
    }

    public function test_eight_article_9_types_seeded_verbatim_with_null_parameters(): void
    {
        $rows = $this->db->fetchAll('SELECT * FROM social_request_types ORDER BY id');
        $this->assertSame(['sante', 'deces', 'maladie', 'famille', 'emploi', 'formation', 'retraite', 'urgences'], array_column($rows, 'code'));
        $this->assertSame([
            'Information, orientation sanitaire et dispositifs d\'accès aux soins.',
            'Soutien à la famille du membre décédé selon les conditions prévues.',
            'Assistance exceptionnelle après examen du dossier.',
            'Accompagnement des ayants droit dans les limites des règles adoptées.',
            'Mise en relation avec les armements et diffusion d\'opportunités.',
            'Information et orientation vers les formations professionnelles.',
            'Accompagnement des anciens marins.',
            'Étude des demandes d\'aide en situation sociale urgente.',
        ], array_column($rows, 'action_text'));
        foreach ($rows as $r) {
            foreach (['criteria_text', 'ceiling_amount', 'waiting_period_days', 'deciding_body', 'allowed_aid_kinds', 'params_decision_ref', 'params_validated_by', 'params_validated_at'] as $c) {
                $this->assertNull($r[$c], $r['code'] . '.' . $c);
            }
            $this->assertSame(1, (int) $r['is_active']);
        }
    }

    public function test_decision_is_unique_per_request(): void
    {
        [$u, $r] = $this->fixtures();
        $sql = "INSERT INTO social_decisions (social_request_id, decision, deciding_body, decision_ref, reason, member_message, decided_at, recorded_by) VALUES (?, 'refusee', 'o', 'ref', 'm', 'msg', '2026-01-06', ?)";
        $this->db->query($sql, [$r, $u]);
        $this->expectSqlError($sql, [$r, $u]);
    }

    public function test_amount_checks_reject_zero_and_negative(): void
    {
        [$u, $r, $t] = $this->fixtures();
        foreach (['0', '-5'] as $a) {
            $this->expectSqlError("INSERT INTO social_decisions (social_request_id, decision, amount, deciding_body, decision_ref, reason, member_message, decided_at, recorded_by) VALUES (?, 'accordee', ?, 'o', 'r', 'm', 'x', '2026-01-06', ?)", [$r, $a, $u]);
            $this->expectSqlError("INSERT INTO social_request_reviews (social_request_id, reviewer_id, opinion, recommended_amount, comment) VALUES (?, ?, 'favorable', ?, 'c')", [$r, $u, $a]);
            $this->expectSqlError('UPDATE social_request_types SET ceiling_amount = ? WHERE id = ?', [$a, $t]);
        }
    }

    public function test_disbursement_social_decision_fk_is_enforced(): void
    {
        [$u] = $this->fixtures();
        $n = bin2hex(random_bytes(4));
        $a = $this->insert('INSERT INTO financial_accounts (name) VALUES (?)', ["Cpt $n"]);
        $c = $this->insert('INSERT INTO financial_categories (name, direction) VALUES (?, ?)', ["Dep $n", 'depense']);
        $this->expectSqlError("INSERT INTO disbursement_requests (reference, account_id, category_id, amount, beneficiary_name, purpose, social_decision_id, initiated_by) VALUES (?, ?, ?, 10, 'b', 'p', 999999999, ?)", ["D-$n", $a, $c, $u]);
    }

    public function test_social_settings_are_non_configure(): void
    {
        $rows = $this->db->fetchAll("SELECT `key`, `value`, status FROM settings WHERE `group` = 'social' ORDER BY `key`");
        $this->assertSame(['eligibility_rule', 'processing_deadline_days', 'reference_format', 'retention_months'], array_column($rows, 'key'));
        foreach ($rows as $r) {
            $this->assertSame('non_configure', $r['status']);
        }
        $this->assertSame('"AS-{YYYY}-{NNNN}"', array_column($rows, 'value', 'key')['reference_format']);
    }
}
