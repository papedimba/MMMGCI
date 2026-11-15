<?php
declare(strict_types=1);

namespace App\Modules\Contributions;

use App\Core\Database;
use App\Core\Sequence;
use App\Modules\Finance\Ledger;

/** Reçus : un par paiement (UNIQUE), numéro technique REC-AAAA-NNNNN. Jamais modifiés, sauf le marquage « annulé » par PaymentService::cancel. */
final class ReceiptService
{
    public function __construct(private Database $db, private Sequence $sequence)
    {
    }

    /** À appeler dans la transaction du paiement (verrou : séquence en dernier). @return int id du reçu */
    public function issue(int $paymentId): int
    {
        return $this->db->atomic(fn (): int => $this->db->insert('receipts', [
            'payment_id' => $paymentId, 'receipt_number' => $this->sequence->next('receipt', 'REC', 5),
            'issued_at' => Ledger::now()->format('Y-m-d H:i:s'),
        ]));
    }

    /** @return array<string,mixed>|null */
    public function forPayment(int $paymentId): ?array
    {
        return $this->db->fetch("SELECT r.receipt_number, r.issued_at, r.cancelled_at AS receipt_cancelled_at, r.cancel_reason AS receipt_cancel_reason,
                p.id AS payment_id, p.reference, p.member_id, p.amount, p.method, p.paid_at, p.external_ref, p.cancelled_at, p.cancel_reason,
                m.last_name, m.first_names, m.member_number, u.name AS received_by_name,
                t.name AS type_name, c.period_label
            FROM payments p JOIN receipts r ON r.payment_id = p.id JOIN members m ON m.id = p.member_id
            LEFT JOIN users u ON u.id = p.received_by LEFT JOIN contribution_calls c ON c.id = p.contribution_call_id
            LEFT JOIN contribution_types t ON t.id = c.contribution_type_id WHERE p.id = ?", [$paymentId]);
    }
}
