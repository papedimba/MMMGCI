<?php
declare(strict_types=1);

namespace App\Modules\Cms;

use App\Core\Csrf;
use App\Core\Gate;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\NotFound;
use App\Core\Session;
use App\Core\View;
use App\Modules\AuditLog\SensitiveAccess;

/** Écrans des témoignages (/admin/site/temoignages) ; la pièce de consentement n'est servie que par download() (cms.publish, consultation journalisée). */
final class TestimonialController extends SimpleScreen
{
    protected const SUBTITLE = 'Désactivés par défaut. Publication : droit de publication, réglage « site.testimonials_enabled » validé par les instances et consentement signé.';

    public function __construct(View $view, Csrf $csrf, Session $session, Gate $gate, private TestimonialService $testimonials, private SensitiveAccess $sensitive)
    {
        parent::__construct($view, $csrf, $session, $gate, $testimonials);
    }

    protected function base(): string
    {
        return '/admin/site/temoignages';
    }

    protected function names(): array
    {
        return ['testimonials', 'témoignage', 'témoignages'];
    }

    protected function fields(): array
    {
        return ['body', 'author_name', 'author_role'];
    }

    protected function columns(): array
    {
        $base = $this->base();
        return [
            ['author_name', 'Auteur', '', false, static fn (array $a): string => '<a href="' . e($base . '/' . $a['id']) . '">' . e($a['author_name']) . '</a>'],
            ['author_role', 'Fonction'],
            ['consent', 'Consentement', '', false, static fn (array $a): string => $a['consent_file_id'] === null ? ui('badge', ['kind' => 'warn', 'label' => 'Manquant']) : ui('badge', ['kind' => 'ok', 'label' => 'Reçu'])],
        ];
    }

    protected function notice(): string
    {
        return $this->testimonials->enabled() ? '' : ui('alert', ['kind' => 'warn', 'message' => 'Les témoignages sont désactivés : le réglage « site.testimonials_enabled » n\'est pas validé par les instances. Aucun témoignage ne peut être publié.']);
    }

    protected function persist(Request $r, ?array $row, array $values): int
    {
        $file = $r->file('consent');
        if ($row === null) {
            return $this->testimonials->create($values, $file, $this->actor($r));
        }
        $this->testimonials->update((int) $row['id'], $values, $file, $this->actor($r));
        return (int) $row['id'];
    }

    protected function formData(?array $row): array
    {
        return ['enabled' => $this->testimonials->enabled(), 'hasConsent' => $row !== null && $row['consent_file_id'] !== null];
    }

    protected function publicationNote(array $row): string
    {
        return $this->testimonials->enabled() ? '' : 'Le réglage « site.testimonials_enabled » n\'est pas validé : la publication sera refusée.';
    }

    public function download(Request $r): Response
    {
        return $this->found(function () use ($r): Response {
            $id = $this->id($r);
            $file = $this->testimonials->consentFile($id);
            try {
                $body = (string) file_get_contents($this->testimonials->path((int) $file['id']));
            } catch (\InvalidArgumentException) {
                throw new NotFound('Fichier introuvable.');
            }
            $name = preg_replace('/[^A-Za-z0-9._-]+/', '_', (string) $file['original_name']) ?: 'consentement';
            $this->sensitive->record($this->actor($r), 'cms_testimonial', $id, 'consent', $r->ip()); // après la lecture réussie du fichier
            return new Response($body, 200, ['Content-Type' => (string) $file['mime'], 'Content-Length' => (string) strlen($body),
                'Content-Disposition' => 'attachment; filename="' . $name . '"', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
        });
    }
}
