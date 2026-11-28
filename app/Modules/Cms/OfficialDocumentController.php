<?php
declare(strict_types=1);

namespace App\Modules\Cms;

use App\Core\Csrf;
use App\Core\Gate;
use App\Core\Http\Request;
use App\Core\Session;
use App\Core\View;

/** Écrans des documents officiels (/admin/site/documents). */
final class OfficialDocumentController extends SimpleScreen
{
    protected const SUBTITLE = 'Documents officiels de la Mutuelle (statuts, comptes rendus…). Un document n\'est visible du public qu\'une fois publié par un publicateur.';

    public function __construct(View $view, Csrf $csrf, Session $session, Gate $gate, private DocumentService $documents)
    {
        parent::__construct($view, $csrf, $session, $gate, $documents);
    }

    protected function base(): string
    {
        return '/admin/site/documents';
    }

    protected function names(): array
    {
        return ['documents', 'document', 'documents officiels'];
    }

    protected function fields(): array
    {
        return ['title', 'category', 'document_date', 'media_id'];
    }

    protected function columns(): array
    {
        $base = $this->base();
        return [
            ['title', 'Titre', '', false, static fn (array $a): string => '<a href="' . e($base . '/' . $a['id']) . '">' . e($a['title']) . '</a>'],
            ['category', 'Catégorie'],
            ['document_date', 'Date', '', false, static fn (array $a): string => e($a['document_date'] === null ? '' : (new \DateTimeImmutable((string) $a['document_date']))->format('d/m/Y'))],
        ];
    }

    protected function persist(Request $r, ?array $row, array $values): int
    {
        if ($row === null) {
            return $this->documents->create($values, $this->actor($r));
        }
        $this->documents->update((int) $row['id'], $values, $this->actor($r));
        return (int) $row['id'];
    }

    protected function formData(?array $row): array
    {
        return ['categories' => $this->documents->categories(), 'files' => ['' => '— Choisir —'] + $this->documents->mediaChoices(DocumentService::MIMES)];
    }
}
