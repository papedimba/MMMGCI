<?php
declare(strict_types=1);

namespace App\Modules\Cms;

use App\Core\Csrf;
use App\Core\Gate;
use App\Core\Http\Request;
use App\Core\Session;
use App\Core\View;

/** Écrans des bannières (/admin/site/bannieres). */
final class BannerController extends SimpleScreen
{
    protected const SUBTITLE = 'Messages courts affichés en tête du site. Trois bannières publiées au plus (période en cours ou à venir).';

    public function __construct(View $view, Csrf $csrf, Session $session, Gate $gate, private BannerService $banners)
    {
        parent::__construct($view, $csrf, $session, $gate, $banners);
    }

    protected function base(): string
    {
        return '/admin/site/bannieres';
    }

    protected function names(): array
    {
        return ['banners', 'bannière', 'bannières'];
    }

    protected function fields(): array
    {
        return ['title', 'link_url', 'starts_at', 'ends_at'];
    }

    protected function columns(): array
    {
        $base = $this->base();
        $fmt = static fn (?string $d): string => $d === null ? '—' : (new \DateTimeImmutable($d))->format('d/m/Y H:i');
        return [
            ['title', 'Texte', '', false, static fn (array $a): string => '<a href="' . e($base . '/' . $a['id']) . '">' . e($a['title']) . '</a>'],
            ['starts_at', 'Début', '', false, static fn (array $a): string => e($fmt($a['starts_at']))],
            ['ends_at', 'Fin', '', false, static fn (array $a): string => e($fmt($a['ends_at']))],
        ];
    }

    protected function prefill(array $row): array
    {
        foreach (['starts_at', 'ends_at'] as $k) {
            $row[$k] = CmsContent::inputDate($row[$k] === null ? null : (string) $row[$k]);
        }
        return $row;
    }

    protected function persist(Request $r, ?array $row, array $values): int
    {
        if ($row === null) {
            return $this->banners->create($values, $this->actor($r));
        }
        $this->banners->update((int) $row['id'], $values, $this->actor($r));
        return (int) $row['id'];
    }
}
