<?php
declare(strict_types=1);

namespace App\Modules\Cms;

use DateTimeImmutable;

/** Contenu à publication programmée (pages, articles, évènements) : sert à CmsScheduler. */
interface ScheduledContent
{
    /** Nom de la table (journal d'audit). */
    public function scheduledTable(): string;

    /** @return list<int> contenus « programme » dont la date est échue. */
    public function dueIds(DateTimeImmutable $now): array;

    /** Passe un contenu échu en « publie » (acteur système, audit sans acteur) ; sans effet s'il n'est plus échu. */
    public function publishDue(int $id, DateTimeImmutable $now): void;
}
