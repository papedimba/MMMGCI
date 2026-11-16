<?php
declare(strict_types=1);

namespace App\Modules\Cms;

use DateTimeImmutable;
use DateTimeZone;

/** Circuit de publication commun aux contenus du CMS. */
final class ContentWorkflow
{
    public const STATUSES = ['brouillon', 'en_relecture', 'publie', 'programme', 'archive'];

    /** Transitions de l'auteur (cms.pages.manage) ; le publicateur les a aussi. */
    private const AUTHOR = ['brouillon' => ['en_relecture'], 'en_relecture' => ['brouillon']];

    private const PUBLISHER = [
        'brouillon' => ['publie', 'programme'],
        'en_relecture' => ['publie', 'programme'],
        'publie' => ['archive', 'brouillon'],
        'programme' => ['archive', 'brouillon'],
        'archive' => ['brouillon'],
    ];

    /** @return list<string> */
    public static function transitionsFor(string $from, bool $mayPublish): array
    {
        $to = self::AUTHOR[$from] ?? [];
        if ($mayPublish) {
            $to = array_values(array_unique([...$to, ...(self::PUBLISHER[$from] ?? [])]));
        }
        return $to;
    }

    public static function can(string $from, string $to, bool $mayPublish): bool
    {
        return in_array($to, self::transitionsFor($from, $mayPublish), true);
    }

    public static function assertCan(string $from, string $to, bool $mayPublish): void
    {
        if (!self::can($from, $to, $mayPublish)) {
            throw new ContentTransitionInvalid("Transition impossible : « $from » vers « $to »" . ($mayPublish ? '.' : ' (droit de publication requis).'));
        }
    }

    /** « programme » exige une date de publication strictement future. */
    public static function assertScheduledDate(?DateTimeImmutable $at, ?DateTimeImmutable $now = null): void
    {
        $now ??= new DateTimeImmutable('now', new DateTimeZone('Africa/Abidjan'));
        if ($at === null || $at <= $now) {
            throw new ContentTransitionInvalid('La publication programmée exige une date et une heure futures.');
        }
    }
}
