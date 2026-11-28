<?php
declare(strict_types=1);

namespace App\Modules\Social;

use App\Core\Audit;
use App\Core\Export\Csv;
use App\Core\Export\Decimal;
use App\Core\Export\Xlsx;
use App\Core\Money;

/** Export des demandes sociales : colonnes fixes, jamais de pièce, description, avis, note, motif, message ni donnée de santé. */
final class SocialExport
{
    public const HEADERS = ['Référence', 'Date de réception', 'N° d\'adhérent', 'Zone', 'Type', 'Priorité', 'Statut', 'Responsable', 'Décision', 'Sorte d\'aide', 'Montant accordé', 'Date de clôture'];

    public function __construct(private SocialSearch $search, private Audit $audit)
    {
    }

    public function file(string $format, int $userId, array $criteria, ?string $ip, ?string $ua): string
    {
        $rows = [];
        foreach ($this->search->stream($userId, $criteria) as $r) {
            $amount = $r['amount'] === null ? '' : ($format === 'xlsx' ? new Decimal((string) $r['amount']) : Money::format(Money::fromDecimal((string) $r['amount'])));
            $rows[] = [(string) $r['reference'], date('d/m/Y', strtotime((string) $r['received_at'])), (string) $r['member_number'], (string) $r['zone_name'], (string) $r['type_name'],
                SocialRequestService::PRIORITIES[$r['priority']] ?? (string) $r['priority'], mb_strtoupper(mb_substr($l = SocialWorkflow::LABELS[$r['status']] ?? (string) $r['status'], 0, 1)) . mb_substr($l, 1), (string) $r['assignee_name'],
                $r['decision'] === null ? '' : ($r['decision'] === 'accordee' ? 'Accordée' : 'Refusée'), SocialTypeService::AID_KINDS[$r['aid_kind']] ?? (string) $r['aid_kind'], $amount,
                $r['closed_at'] === null ? '' : date('d/m/Y', strtotime((string) $r['closed_at']))];
        }
        $this->audit->log($userId, 'social.exported', 'social_request', null, null, ['rows' => count($rows), 'format' => $format, 'criteria' => $this->search->applied($userId, $criteria)], null, $ip, $ua);
        return $format === 'xlsx' ? Xlsx::build('Demandes sociales', self::HEADERS, $rows) : Csv::build(self::HEADERS, $rows);
    }
}
