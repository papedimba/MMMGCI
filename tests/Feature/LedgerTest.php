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

    /** Balayage des sources : tout appel applicatif à Ledger::record passe une source non vide (une écriture sans source n'est contre-passable par aucun écran). */
    public function test_every_ledger_record_caller_sets_a_source_type(): void
    {
        $calls = [];
        foreach ($this->phpFiles($this->root() . '/app') as $file) {
            $src = (string) file_get_contents($file);
            if (!preg_match_all('/->ledger->record\(/', $src, $m, PREG_OFFSET_CAPTURE)) {
                continue;
            }
            foreach ($m[0] as [$match, $offset]) {
                $depth = 0;
                for ($i = $offset + strlen($match) - 1; $i < strlen($src); $i++) {
                    $depth += $src[$i] === '(' ? 1 : ($src[$i] === ')' ? -1 : 0);
                    if ($depth === 0) {
                        break;
                    }
                }
                $args = substr($src, $offset, $i - $offset + 1);
                $this->assertMatchesRegularExpression("/'source_type'\\s*=>\\s*'[a-z_]+'/", $args, basename($file) . ' : source_type littéral non vide attendu');
                $calls[] = basename($file);
            }
        }
        sort($calls);
        $this->assertSame(['DisbursementService.php', 'FinanceController.php', 'PaymentService.php'], $calls);
        // Aucun autre chemin vers Ledger::record (variable autre que $this->ledger).
        foreach ($this->phpFiles($this->root() . '/app') as $file) {
            $src = (string) file_get_contents($file);
            if (str_contains($src, 'Ledger') && basename($file) !== 'Ledger.php') {
                $this->assertDoesNotMatchRegularExpression('/\$(?!this->ledger)\w+(->\w+)*->record\([^)]*\b(recette|depense)\b/', $src, $file);
            }
        }
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
