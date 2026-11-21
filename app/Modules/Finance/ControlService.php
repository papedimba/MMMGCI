<?php
declare(strict_types=1);

namespace App\Modules\Finance;

use App\Core\Audit;
use App\Core\Database;
use App\Core\Export\Csv;
use App\Core\Export\Decimal;
use App\Core\Export\Xlsx;
use App\Core\Money;
use App\Core\Settings;
use App\Modules\Disbursements\ApprovalRules;
use Generator;

/** Lecture seule du grand livre et du circuit de décaissement pour l'organe de contrôle : aucune écriture ici, hormis le journal d'audit des exports. */
final class ControlService
{
    public const PER_PAGE = 30;
    private const CHUNK = 500;
    public const TX_HEADERS = ['Référence', 'Date', 'Compte', 'Catégorie', 'Sens', 'Montant (XOF)', 'Origine', 'Contre-passation de', 'Contre-passée par'];
    public const DISB_HEADERS = ['Référence', 'Créée le', 'Soumise le', 'Compte', 'Catégorie', 'Montant (XOF)', 'État', 'Bénéficiaire', 'Objet', 'Révision', 'Payée le', 'Écriture'];
    public const SOURCES = ['contribution_call' => 'Cotisation', 'disbursement' => 'Décaissement', 'ressource' => 'Ressource saisie'];

    public function __construct(private Database $db, private Audit $audit, private Ledger $ledger)
    {
    }

    /** Critères texte d'URL -> clause SQL + paramètres (jamais de concaténation de valeur). @param array<string,string> $f @return array{string, list<mixed>} */
    private static function txWhere(array $f): array
    {
        [$w, $p] = [[], []];
        $map = ['du' => ['t.occurred_at >= ?', '%s 00:00:00'], 'au' => ['t.occurred_at <= ?', '%s 23:59:59'], 'compte' => ['t.account_id = ?', '%d'],
            'sens' => ['t.direction = ?', '%s'], 'source' => ['t.source_type = ?', '%s']];
        foreach ($map as $k => [$sql, $fmt]) {
            if (($f[$k] ?? '') !== '') {
                [$w[], $p[]] = [$sql, sprintf($fmt, $f[$k])];
            }
        }
        return [$w === [] ? '' : 'WHERE ' . implode(' AND ', $w), $p];
    }

    /** Valide les critères reçus : valeurs inconnues ignorées. @param array<string,mixed> $in @return array<string,string> */
    public static function txFilters(array $in): array
    {
        $date = static fn (mixed $v): string => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $v) && strtotime($v) !== false ? $v : '';
        $src = is_string($in['source'] ?? null) && isset(self::SOURCES[$in['source']]) ? $in['source'] : '';
        return array_filter(['du' => $date($in['du'] ?? ''), 'au' => $date($in['au'] ?? ''), 'compte' => ctype_digit((string) ($in['compte'] ?? '')) ? (string) $in['compte'] : '',
            'sens' => in_array($in['sens'] ?? '', ['recette', 'depense'], true) ? (string) $in['sens'] : '', 'source' => $src], static fn (string $v): bool => $v !== '');
    }

    private const TX_FROM = 'FROM financial_transactions t JOIN financial_accounts a ON a.id = t.account_id JOIN financial_categories c ON c.id = t.category_id
        LEFT JOIN financial_transactions o ON o.id = t.reversal_of_id LEFT JOIN financial_transactions r ON r.reversal_of_id = t.id';
    private const TX_COLS = 't.id, t.reference, t.occurred_at, t.direction, t.amount, t.source_type, t.source_id, t.description, t.reversal_of_id, a.name AS account_name, c.name AS category_name,
        o.reference AS reversal_of_reference, r.id AS reversed_by_id, r.reference AS reversed_by_reference';

    /** @param array<string,string> $f @return array{rows: list<array<string,mixed>>, total: int, page: int, pages: int} */
    public function transactions(array $f, int $page): array
    {
        [$where, $p] = self::txWhere($f);
        $total = (int) $this->db->fetchValue("SELECT COUNT(*) FROM financial_transactions t $where", $p);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min(max(1, $page), $pages);
        $rows = $this->db->fetchAll('SELECT ' . self::TX_COLS . ' ' . self::TX_FROM . " $where ORDER BY t.occurred_at DESC, t.id DESC LIMIT " . self::PER_PAGE . ' OFFSET ' . (($page - 1) * self::PER_PAGE), $p);
        if ($rows !== []) { // justificatifs des écritures : consultés par la route existante (liée, journalisée)
            $in = implode(',', array_fill(0, count($rows), '?'));
            $files = [];
            foreach ($this->db->fetchAll("SELECT a.attachable_id, a.file_id, f.original_name FROM financial_attachments a JOIN files f ON f.id = a.file_id
                WHERE a.attachable_type = 'financial_transaction' AND a.attachable_id IN ($in) ORDER BY a.id", array_column($rows, 'id')) as $f) {
                $files[(int) $f['attachable_id']][] = $f;
            }
            foreach ($rows as &$row) {
                $row['files'] = $files[(int) $row['id']] ?? [];
            }
            unset($row);
        }
        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => $pages];
    }

    /** Parcours par clé (id > dernier), sans OFFSET. @return Generator<int, array<string,mixed>> */
    private function keyset(string $cols, string $from, string $where, array $p, string $idCol): Generator
    {
        $last = 0;
        do {
            $rows = $this->db->fetchAll("SELECT $cols $from " . ($where === '' ? 'WHERE' : "$where AND") . " $idCol > ? ORDER BY $idCol LIMIT " . self::CHUNK, [...$p, $last]);
            foreach ($rows as $row) {
                yield $row;
                $last = (int) $row['id'];
            }
        } while (count($rows) === self::CHUNK);
    }

    /** @param array<string,string> $f @return array{string, list<mixed>} */
    private static function disbWhere(array $f): array
    {
        [$w, $p] = [[], []];
        $map = ['du' => ['d.created_at >= ?', '%s 00:00:00'], 'au' => ['d.created_at <= ?', '%s 23:59:59'], 'compte' => ['d.account_id = ?', '%d'], 'etat' => ['d.status = ?', '%s']];
        foreach ($map as $k => [$sql, $fmt]) {
            if (($f[$k] ?? '') !== '') {
                [$w[], $p[]] = [$sql, sprintf($fmt, $f[$k])];
            }
        }
        return [$w === [] ? '' : 'WHERE ' . implode(' AND ', $w), $p];
    }

    /** @param array<string,mixed> $in @return array<string,string> */
    public static function disbFilters(array $in): array
    {
        $f = self::txFilters($in);
        $etat = in_array($in['etat'] ?? '', ['brouillon', 'soumis', 'approuve_1', 'approuve_2', 'rejete', 'annule', 'paye'], true) ? (string) $in['etat'] : '';
        return array_filter(array_intersect_key($f, ['du' => 1, 'au' => 1, 'compte' => 1]) + ['etat' => $etat], static fn (string $v): bool => $v !== '');
    }

    private const DISB_FROM = 'FROM disbursement_requests d JOIN financial_accounts a ON a.id = d.account_id JOIN financial_categories c ON c.id = d.category_id
        LEFT JOIN users u ON u.id = d.initiated_by LEFT JOIN disbursement_payments p ON p.disbursement_request_id = d.id LEFT JOIN financial_transactions t ON t.id = p.financial_transaction_id';
    private const DISB_COLS = 'd.id, d.reference, d.created_at, d.submitted_at, d.amount, d.status, d.revision, d.beneficiary_name, d.purpose, a.name AS account_name, c.name AS category_name,
        u.name AS initiator_name, p.paid_at, t.reference AS transaction_reference';

    /** @param array<string,string> $f @return array{rows: list<array<string,mixed>>, total: int, page: int, pages: int} */
    public function disbursements(array $f, int $page): array
    {
        [$where, $p] = self::disbWhere($f);
        $total = (int) $this->db->fetchValue("SELECT COUNT(*) FROM disbursement_requests d $where", $p);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min(max(1, $page), $pages);
        $rows = $this->db->fetchAll('SELECT ' . self::DISB_COLS . ' ' . self::DISB_FROM . " $where ORDER BY d.id DESC LIMIT " . self::PER_PAGE . ' OFFSET ' . (($page - 1) * self::PER_PAGE), $p);
        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => $pages];
    }

    /** Historique complet : révisions, toutes les décisions (caduques comprises), rejet, paiement, pièces, écritures et contre-passations. @return ?array<string,mixed> */
    public function disbursement(int $id): ?array
    {
        $d = $this->db->fetch('SELECT d.*, a.name AS account_name, c.name AS category_name, ui.name AS initiator_name, ur.name AS rejected_by_name
            FROM disbursement_requests d JOIN financial_accounts a ON a.id = d.account_id JOIN financial_categories c ON c.id = d.category_id
            LEFT JOIN users ui ON ui.id = d.initiated_by LEFT JOIN users ur ON ur.id = d.rejected_by WHERE d.id = ?', [$id]);
        if ($d === null) {
            return null;
        }
        $d['revisions'] = $this->db->fetchAll('SELECT r.revision, r.reason, r.snapshot, r.created_at, u.name AS changed_by_name
            FROM disbursement_revisions r LEFT JOIN users u ON u.id = r.changed_by WHERE r.disbursement_request_id = ? ORDER BY r.revision', [$id]);
        $d['approvals'] = $this->db->fetchAll('SELECT a.revision, a.decision, a.comment, a.decided_at, a.invalidated_at, u.name AS approver_name
            FROM disbursement_approvals a LEFT JOIN users u ON u.id = a.approver_id WHERE a.disbursement_request_id = ? ORDER BY a.id', [$id]);
        $d['payment'] = $this->db->fetch('SELECT p.paid_at, p.method, p.external_ref, p.proof_file_id, u.name AS payer_name, f.original_name AS proof_name, t.id AS transaction_id, t.reference AS transaction_reference
            FROM disbursement_payments p LEFT JOIN users u ON u.id = p.paid_by JOIN files f ON f.id = p.proof_file_id LEFT JOIN financial_transactions t ON t.id = p.financial_transaction_id
            WHERE p.disbursement_request_id = ?', [$id]);
        $d['attachments'] = $this->db->fetchAll("SELECT a.file_id, a.label, f.original_name FROM financial_attachments a JOIN files f ON f.id = a.file_id
            WHERE a.attachable_type = 'disbursement_request' AND a.attachable_id = ? ORDER BY a.id", [$id]);
        $d['reversal'] = $d['payment'] === null || $d['payment']['transaction_id'] === null ? null : $this->db->fetch(
            'SELECT r.reference, r.occurred_at FROM financial_transactions r WHERE r.reversal_of_id = ?', [(int) $d['payment']['transaction_id']]);
        return $d;
    }

    /** Paires : écriture d'origine et sa contre-passation. @return list<array<string,mixed>> */
    public function reversals(): array
    {
        return $this->db->fetchAll('SELECT r.id AS reversal_id, r.reference AS reversal_reference, r.occurred_at AS reversal_at, r.amount, r.direction AS reversal_direction, r.description,
                o.id AS original_id, o.reference AS original_reference, o.occurred_at AS original_at, o.direction AS original_direction, a.name AS account_name, u.name AS recorded_by_name
            FROM financial_transactions r LEFT JOIN financial_transactions o ON o.id = r.reversal_of_id JOIN financial_accounts a ON a.id = r.account_id
            LEFT JOIN users u ON u.id = r.recorded_by WHERE r.reversal_of_id IS NOT NULL ORDER BY r.id DESC LIMIT 500');
    }

    /** Paiements de cotisation annulés (avec leur reçu) : nom et numéro d'adhérent, jamais d'autre donnée personnelle. @return list<array<string,mixed>> */
    public function cancelledPayments(): array
    {
        return $this->db->fetchAll('SELECT p.id, p.reference, p.amount, p.paid_at, p.cancelled_at, p.cancel_reason, m.id AS member_id, m.member_number, m.first_names, m.last_name,
                rc.receipt_number, rc.cancelled_at AS receipt_cancelled_at, t.reference AS transaction_reference, rv.reference AS reversal_reference
            FROM payments p JOIN members m ON m.id = p.member_id LEFT JOIN receipts rc ON rc.payment_id = p.id LEFT JOIN financial_transactions t ON t.id = p.financial_transaction_id
            LEFT JOIN financial_transactions rv ON rv.reversal_of_id = t.id WHERE p.cancelled_at IS NOT NULL OR rc.cancelled_at IS NOT NULL ORDER BY p.cancelled_at DESC, p.id DESC LIMIT 500');
    }

    /**
     * Solde affiché (Ledger::balance) contre recalcul indépendant : ouverture + Σ recettes − Σ dépenses en deux requêtes séparées, comptées en centimes entiers.
     * @return list<array{id:int,name:string,count:int,displayed:int,recomputed:int,gap:int,ok:bool}>
     */
    public function balances(): array
    {
        $out = [];
        foreach ($this->db->fetchAll('SELECT id, name, opening_balance FROM financial_accounts ORDER BY name, id') as $a) {
            $sum = fn (string $dir): int => Money::fromDecimal((string) $this->db->fetchValue('SELECT COALESCE(SUM(amount), 0) FROM financial_transactions WHERE account_id = ? AND direction = ?', [$a['id'], $dir]));
            $opening = (string) $a['opening_balance'];
            $recomputed = ($opening[0] === '-' ? -Money::fromDecimal(substr($opening, 1)) : Money::fromDecimal($opening)) + $sum('recette') - $sum('depense');
            $displayed = $this->ledger->balance((int) $a['id']);
            $out[] = ['id' => (int) $a['id'], 'name' => (string) $a['name'], 'count' => (int) $this->db->fetchValue('SELECT COUNT(*) FROM financial_transactions WHERE account_id = ?', [$a['id']]),
                'displayed' => $displayed, 'recomputed' => $recomputed, 'gap' => $displayed - $recomputed, 'ok' => $displayed === $recomputed];
        }
        return $out;
    }

    /** Contrôles d'intégrité en lecture seule : chaque SQL renvoie les id fautifs. @return list<array{key:string,label:string,table:string,count:int,ids:list<int>}> */
    public function anomalies(): array
    {
        $checks = [
            ['payment_no_tx', 'Paiement sans écriture au grand livre', 'payment', 'SELECT p.id FROM payments p LEFT JOIN financial_transactions t ON t.id = p.financial_transaction_id WHERE t.id IS NULL'],
            ['paid_no_tx', 'Décaissement payé sans paiement ou sans écriture', 'disbursement', "SELECT d.id FROM disbursement_requests d LEFT JOIN disbursement_payments p ON p.disbursement_request_id = d.id
                LEFT JOIN financial_transactions t ON t.id = p.financial_transaction_id WHERE d.status = 'paye' AND (p.id IS NULL OR t.id IS NULL)"],
            ['tx_unpaid', 'Dépense de décaissement dont la demande n\'est pas payée', 'transaction', "SELECT t.id FROM financial_transactions t LEFT JOIN disbursement_requests d ON d.id = t.source_id
                WHERE t.source_type = 'disbursement' AND t.direction = 'depense' AND t.reversal_of_id IS NULL AND (d.id IS NULL OR d.status <> 'paye')"],
            ['receipt_no_payment', 'Reçu sans paiement', 'receipt', 'SELECT r.id FROM receipts r LEFT JOIN payments p ON p.id = r.payment_id WHERE p.id IS NULL'],
            ['approval_by_initiator', 'Approbation valide donnée par l\'initiateur', 'disbursement', "SELECT DISTINCT d.id FROM disbursement_approvals a JOIN disbursement_requests d ON d.id = a.disbursement_request_id
                WHERE a.decision = 'approve' AND a.invalidated_at IS NULL AND a.approver_id = d.initiated_by"],
            ['payment_no_receipt', 'Paiement actif sans reçu', 'payment', 'SELECT p.id FROM payments p LEFT JOIN receipts r ON r.payment_id = p.id WHERE p.cancelled_at IS NULL AND r.id IS NULL'],
            ['payment_amount', 'Montant de l\'écriture différent de celui du paiement', 'payment', 'SELECT p.id FROM payments p JOIN financial_transactions t ON t.id = p.financial_transaction_id WHERE t.amount <> p.amount'],
            ['disbursement_amount', 'Montant de l\'écriture différent de celui de la demande payée', 'disbursement', 'SELECT d.id FROM disbursement_requests d JOIN disbursement_payments p ON p.disbursement_request_id = d.id
                JOIN financial_transactions t ON t.id = p.financial_transaction_id WHERE t.amount <> d.amount'],
            ['cancelled_no_reversal', 'Paiement annulé sans contre-passation', 'payment', 'SELECT p.id FROM payments p LEFT JOIN financial_transactions r ON r.reversal_of_id = p.financial_transaction_id WHERE p.cancelled_at IS NOT NULL AND r.id IS NULL'],
            ['reversal_orphan', 'Contre-passation dont l\'écriture d\'origine est absente', 'transaction', 'SELECT r.id FROM financial_transactions r LEFT JOIN financial_transactions o ON o.id = r.reversal_of_id WHERE r.reversal_of_id IS NOT NULL AND o.id IS NULL'],
            ['reversal_mismatch', 'Contre-passation incohérente avec son origine (compte, montant ou sens)', 'transaction', 'SELECT r.id FROM financial_transactions r JOIN financial_transactions o ON o.id = r.reversal_of_id
                WHERE r.account_id <> o.account_id OR r.amount <> o.amount OR r.direction = o.direction'],
            ['expense_outside_circuit', 'Dépense hors circuit de décaissement (ni contre-passation, ni source « décaissement »)', 'transaction', "SELECT t.id FROM financial_transactions t
                WHERE t.direction = 'depense' AND t.reversal_of_id IS NULL AND COALESCE(t.source_type, '') <> 'disbursement'"],
            ['paid_by_initiator', 'Décaissement payé par son initiateur alors que ce n\'est pas validé', 'disbursement', 'SELECT d.id FROM disbursement_requests d JOIN disbursement_payments p ON p.disbursement_request_id = d.id
                WHERE ' . $this->payerMayBeInitiator() . ' AND p.paid_by = d.initiated_by'],
        ];
        $out = [];
        foreach ($checks as [$key, $label, $table, $sql]) {
            $ids = array_map('intval', array_column($this->db->fetchAll($sql . ' ORDER BY 1 LIMIT 50'), 'id'));
            $count = (int) $this->db->fetchValue("SELECT COUNT(*) FROM ($sql) x"); // total réel, indépendant de la liste affichée
            $out[] = ['key' => $key, 'label' => $label, 'table' => $table, 'count' => $count, 'ids' => $ids];
        }
        $out[] = $this->fewApprovers();
        return $out;
    }

    /** Fragment SQL constant : « 0 = 1 » si le paramètre est validé à vrai (strict), sinon « 1 = 1 ». */
    private function payerMayBeInitiator(): string
    {
        $s = new Settings($this->db);
        return $s->isValidated('finance', 'payer_may_be_initiator') && $s->get('finance', 'payer_may_be_initiator') === true ? '0 = 1' : '1 = 1';
    }

    /** Payé avec moins d'approbateurs distincts valides que n'en exige la règle applicable à son montant (ApprovalRules, jamais moins de 2). @return array{key:string,label:string,table:string,count:int,ids:list<int>} */
    private function fewApprovers(): array
    {
        $rules = new ApprovalRules($this->db);
        $ids = [];
        foreach ($this->db->fetchAll("SELECT d.id, d.amount, (SELECT COUNT(DISTINCT a.approver_id) FROM disbursement_approvals a WHERE a.disbursement_request_id = d.id AND a.revision = d.revision
                AND a.decision = 'approve' AND a.invalidated_at IS NULL) AS valid FROM disbursement_requests d WHERE d.status = 'paye' ORDER BY d.id") as $d) {
            if ((int) $d['valid'] < $rules->requiredFor(Money::fromDecimal((string) $d['amount']))) {
                $ids[] = (int) $d['id'];
            }
        }
        return ['key' => 'paid_few_approvers', 'label' => 'Décaissement payé avec moins d\'approbateurs distincts valides que la règle d\'approbation ACTUELLE (celle en vigueur aujourd\'hui, pas celle d\'à la date du paiement)', 'table' => 'disbursement', 'count' => count($ids), 'ids' => array_slice($ids, 0, 50)];
    }

    /** Export audité d'un jeu (« ecritures » ou « decaissements »). @param array<string,string> $f @return array{body: string, rows: int} */
    public function export(string $dataset, string $format, array $f, int $userId, ?string $ip, ?string $ua): array
    {
        $rows = [];
        $amount = static fn (string $dec): string|Decimal => $format === 'xlsx' ? new Decimal($dec) : str_replace('.', ',', $dec); // XLSX : vraie cellule numérique ; CSV : virgule décimale comme les autres exports
        $day = static fn (?string $v): string => $v === null ? '' : date('d/m/Y', strtotime($v));
        if ($dataset === 'ecritures') {
            [$where, $p] = self::txWhere($f);
            foreach ($this->keyset(self::TX_COLS, self::TX_FROM, $where, $p, 't.id') as $t) {
                $rows[] = [(string) $t['reference'], $day((string) $t['occurred_at']), (string) $t['account_name'], (string) $t['category_name'], $t['direction'] === 'recette' ? 'Entrée' : 'Sortie',
                    $amount((string) $t['amount']), $t['source_type'] === null ? '' : (self::SOURCES[$t['source_type']] ?? (string) $t['source_type']),
                    (string) ($t['reversal_of_reference'] ?? ''), (string) ($t['reversed_by_reference'] ?? '')];
            }
            [$headers, $sheet, $subject] = [self::TX_HEADERS, 'Écritures', 'transactions'];
        } else {
            [$where, $p] = self::disbWhere($f);
            foreach ($this->keyset(self::DISB_COLS, self::DISB_FROM, $where, $p, 'd.id') as $d) {
                $rows[] = [(string) $d['reference'], $day((string) $d['created_at']), $day($d['submitted_at']), (string) $d['account_name'], (string) $d['category_name'], $amount((string) $d['amount']),
                    (string) $d['status'], (string) $d['beneficiary_name'], (string) $d['purpose'], (int) $d['revision'], $day($d['paid_at']), (string) ($d['transaction_reference'] ?? '')];
            }
            [$headers, $sheet, $subject] = [self::DISB_HEADERS, 'Décaissements', 'disbursements'];
        }
        $this->audit->log($userId, 'finance.control_exported', 'finance_export', null, null, ['dataset' => $subject, 'format' => $format, 'criteria' => $f, 'rows' => count($rows)], null, $ip, $ua);
        return ['body' => $format === 'xlsx' ? Xlsx::build($sheet, $headers, $rows) : Csv::build($headers, $rows), 'rows' => count($rows)];
    }
}
