<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Audit;
use App\Core\Sequence;
use App\Modules\Finance\AttachmentService;
use App\Modules\Finance\Ledger;
use DateTimeImmutable;
use DomainException;
use Tests\DatabaseTestCase;

final class LedgerTest extends DatabaseTestCase
{
    private function root(): string
    {
        return dirname(__DIR__, 2);
    }

    /** @return list<string> */
    private function phpFiles(string $dir): array
    {
        $out = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $f) {
            if ($f->getExtension() === 'php') {
                $out[] = $f->getPathname();
            }
        }
        return $out;
    }

    public function test_ledger_has_no_update_or_delete_path(): void
    {
        foreach ((new \ReflectionClass(Ledger::class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $m) {
            $this->assertDoesNotMatchRegularExpression('/^(update|delete|remove|edit|set)/i', $m->getName());
        }
        // Aucun code applicatif n'écrit autrement que par INSERT dans le grand livre.
        foreach ($this->phpFiles($this->root() . '/app') as $file) {
            $src = (string) file_get_contents($file);
            $this->assertDoesNotMatchRegularExpression('/UPDATE\s+`?financial_transactions|DELETE\s+FROM\s+`?financial_transactions|(update|delete)\(\s*[\'"]financial_transactions/i', $src, $file);
        }
        // Les contrôles SQL de la base : montant nul et double contre-passation sont refusés même hors de la couche PHP.
        $acc = $this->db->insert('financial_accounts', ['name' => 'Imm ' . bin2hex(random_bytes(4))]);
        $cat = (int) $this->db->fetchValue("SELECT id FROM financial_categories LIMIT 1");
        $this->expectException(\PDOException::class);
        $this->db->insert('financial_transactions', ['reference' => 'TX-Z-' . bin2hex(random_bytes(3)), 'account_id' => $acc, 'category_id' => $cat, 'direction' => 'recette', 'amount' => '0.00', 'occurred_at' => '2026-01-01 00:00:00']);
    }

    public function test_attachment_service_whitelists_targets(): void
    {
        $audit = new Audit($this->db, 'base64:' . base64_encode(str_repeat('k', 32)));
        $ledger = new Ledger($this->db, $audit, new Sequence($this->db));
        $acc = $this->db->insert('financial_accounts', ['name' => 'Pj ' . bin2hex(random_bytes(4))]);
        $cat = (int) $this->db->fetchValue("SELECT id FROM financial_categories WHERE name = 'Dons, legs et subventions'");
        $tx = $ledger->record($acc, $cat, 'recette', 1000, new DateTimeImmutable('now'), [], 1);
        $fid = $this->db->insert('files', ['path' => 'p', 'original_name' => 'p.pdf', 'mime' => 'application/pdf', 'size' => 1, 'sha256' => str_repeat('b', 64)]);
        $svc = new AttachmentService($this->db, $audit);
        $this->assertGreaterThan(0, $svc->attach('financial_transaction', $tx, $fid, 'Reçu', 1));
        foreach ([['payment', $tx], ['users; DROP', $tx], ['financial_transaction', 999999]] as [$type, $id]) {
            try {
                $svc->attach($type, $id, $fid, 'x', 1);
                $this->fail('Refus attendu');
            } catch (DomainException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
