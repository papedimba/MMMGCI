<?php
declare(strict_types=1);

namespace App\Modules\Cms;

use App\Core\Audit;
use App\Core\Database;
use App\Core\Gate;
use App\Core\Scheduler;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/** Tâche cron « cms.publish_scheduled » : publie les contenus programmés échus ; un échec n'empêche pas les autres. */
final class CmsScheduler
{
    /** @param list<ScheduledContent> $sources tâche 7 : y ajouter les évènements */
    public function __construct(private Audit $audit, private array $sources)
    {
    }

    public function publishDue(DateTimeImmutable $now): int
    {
        $count = 0;
        foreach ($this->sources as $source) {
            try {
                $ids = $source->dueIds($now);
            } catch (Throwable $e) {
                error_log('cms.publish_scheduled : lecture impossible (' . $source->scheduledTable() . ') ' . $e->getMessage());
                continue;
            }
            foreach ($ids as $id) {
                try {
                    $source->publishDue($id, $now);
                    $count++;
                } catch (Throwable $e) {
                    error_log('cms.publish_scheduled : échec ' . $source->scheduledTable() . " n° $id : " . $e->getMessage());
                    try {
                        $this->audit->log(null, 'cms.schedule_failed', $source->scheduledTable(), $id, null, ['error' => $e::class]);
                    } catch (Throwable) {
                    }
                }
            }
        }
        return $count;
    }

    public static function register(Scheduler $scheduler, Database $db, Audit $audit): void
    {
        $scheduler->register('cms.publish_scheduled', 5, static function () use ($db, $audit): void {
            $gate = new Gate($db);
            (new self($audit, [new PageService($db, $audit, $gate), new ArticleService($db, $audit, $gate)]))
                ->publishDue(new DateTimeImmutable('now', new DateTimeZone('Africa/Abidjan')));
        });
    }
}
