<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Social\SocialTransitionInvalid;
use App\Modules\Social\SocialWorkflow;
use PHPUnit\Framework\TestCase;

final class SocialWorkflowTest extends TestCase
{
    private const EXPECTED = [
        'recue>en_instruction' => 'assign', 'en_instruction>en_attente_pieces' => 'requestDocuments', 'en_attente_pieces>en_instruction' => 'resumeInstruction',
        'en_instruction>soumise_decision' => 'submitForDecision', 'soumise_decision>accordee' => 'decide', 'soumise_decision>refusee' => 'decide',
        'accordee>en_paiement' => 'prepareDisbursement', 'en_paiement>accordee' => 'paymentFailed', 'accordee>cloturee' => 'close', 'refusee>cloturee' => 'close',
        'en_paiement>cloturee' => 'close',
    ];

    public function test_transition_table_is_pinned(): void
    {
        $actual = [];
        foreach (SocialWorkflow::TRANSITIONS as $from => $tos) {
            foreach ($tos as $to => $action) {
                $actual["$from>$to"] = $action;
            }
        }
        ksort($actual);
        $expected = self::EXPECTED;
        ksort($expected);
        self::assertSame($expected, $actual);
        self::assertSame([], SocialWorkflow::TRANSITIONS['cloturee']);
        self::assertSame(SocialWorkflow::STATUSES, array_keys(SocialWorkflow::TRANSITIONS));
    }

    public function test_every_transition_not_in_table_is_refused(): void
    {
        $actions = array_unique(array_values(self::EXPECTED));
        $checked = 0;
        foreach (SocialWorkflow::STATUSES as $from) {
            foreach (SocialWorkflow::STATUSES as $to) {
                $allowed = self::EXPECTED["$from>$to"] ?? null;
                foreach ([null, ...$actions] as $action) {
                    $ok = $allowed !== null && ($action === null || $action === $allowed);
                    try {
                        SocialWorkflow::assert($from, $to, $action);
                        self::assertTrue($ok, "$from → $to par " . ($action ?? '(toute action)') . ' aurait dû être refusé');
                    } catch (SocialTransitionInvalid $e) {
                        self::assertFalse($ok, "$from → $to par $action aurait dû passer");
                        self::assertStringStartsWith('Passage impossible', $e->getMessage());
                    }
                    $checked++;
                }
            }
        }
        self::assertSame(64 * (count($actions) + 1), $checked);
        self::assertFalse(SocialWorkflow::permits('cloturee', 'assign'));
        self::assertFalse(SocialWorkflow::permits('recue', 'resumeInstruction'), 'recue → en_instruction n\'est porté que par l\'affectation');
        self::assertTrue(SocialWorkflow::permits('en_attente_pieces', 'assign'), 'réaffectation sans changement d\'état');
    }

    public function test_forged_received_to_granted_is_refused(): void
    {
        $this->expectException(SocialTransitionInvalid::class);
        SocialWorkflow::assert('recue', 'accordee', 'decide');
    }
}
