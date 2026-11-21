<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Cms\ContentTransitionInvalid;
use App\Modules\Cms\ContentWorkflow as W;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class ContentWorkflowTest extends TestCase
{
    public function test_author_without_publish_can_only_submit_and_withdraw(): void
    {
        foreach (W::STATUSES as $f) {
            foreach (W::STATUSES as $t) {
                $ok = ($f === 'brouillon' && $t === 'en_relecture') || ($f === 'en_relecture' && $t === 'brouillon');
                $this->assertSame($ok, W::can($f, $t, false), "$f -> $t");
            }
        }
    }

    public function test_publisher_transitions_matrix_is_complete(): void
    {
        $allowed = [
            'brouillon' => ['en_relecture', 'publie', 'programme'],
            'en_relecture' => ['brouillon', 'publie', 'programme'],
            'publie' => ['archive', 'brouillon'],
            'programme' => ['archive', 'brouillon'],
            'archive' => ['brouillon'],
        ];
        foreach (W::STATUSES as $f) {
            foreach (W::STATUSES as $t) {
                $this->assertSame(in_array($t, $allowed[$f], true), W::can($f, $t, true), "$f -> $t");
            }
            $this->assertEqualsCanonicalizing($allowed[$f], W::transitionsFor($f, true));
        }
    }

    public function test_scheduled_requires_a_future_date(): void
    {
        $now = new DateTimeImmutable('2026-11-16 10:00:00');
        W::assertScheduledDate($now->modify('+1 minute'), $now);
        foreach ([null, $now, $now->modify('-1 day')] as $bad) {
            try {
                W::assertScheduledDate($bad, $now);
                $this->fail('Date non future acceptée');
            } catch (ContentTransitionInvalid) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_invalid_transition_throws(): void
    {
        $this->expectException(ContentTransitionInvalid::class);
        W::assertCan('brouillon', 'publie', false);
    }
}
