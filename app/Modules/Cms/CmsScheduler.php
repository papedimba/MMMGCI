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

/** Chaque minute : bin/cron.php (toutes les 5 min) démarre avec une gigue, un intervalle de 5 min sauterait un cycle. Tâche cron « cms.publish_scheduled » : publie les contenus programmés échus ; un échec n'empêche pas les autres. */
final class CmsScheduler
{
    /** @param list<ScheduledContent> $sources */
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
                error_log('cms.publish_scheduled : lecture impossible (' . $source->scheduledTable() . ') ' . $e::class);
                continue;
            }
            foreach ($ids as $id) {
                try {
                    $source->publishDue($id, $now);
                    $count++;
                } catch (Throwable $e) {
                    error_log('cms.publish_scheduled : échec ' . $source->scheduledTable() . " n° $id : " . $e::class); // jamais le message : il peut citer du contenu
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
        $scheduler->register('cms.publish_scheduled', 1, static function () use ($db, $audit): void {
            $gate = new Gate($db);
            (new self($audit, [new PageService($db, $audit, $gate), new ArticleService($db, $audit, $gate), new EventService($db, $audit, $gate)]))
                ->publishDue(new DateTimeImmutable('now', new DateTimeZone('Africa/Abidjan')));
        });
    }
}
