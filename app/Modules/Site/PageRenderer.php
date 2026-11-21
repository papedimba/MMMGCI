<?php
declare(strict_types=1);

namespace App\Modules\Site;

use App\Core\View;
use App\Modules\Cms\Blocks;
use App\Modules\Cms\PublishedContent;
use Throwable;

/**
 * Blocs d'une page publiée, dans l'ordre, chacun par public/blocks/<type>.php. Le type n'est utilisé comme nom de vue
 * qu'après contrôle dans Blocks::TYPES ; un bloc inconnu ou en erreur est ignoré (journal sans contenu), jamais d'erreur 500.
 */
final class PageRenderer
{
    public function __construct(private View $view, private PublishedContent $content)
    {
    }

    /** @param array<string,mixed> $page version en ligne (PublishedContent::page) */
    public function render(array $page): string
    {
        $out = '';
        $seen = [];
        $home = ($page['template'] ?? '') === 'accueil'; // accueil : un bloc dynamique vide est omis au lieu d'afficher son état vide
        foreach (is_array($page['blocks'] ?? null) ? $page['blocks'] : [] as $i => $b) {
            $type = is_array($b) && is_string($b['type'] ?? null) ? $b['type'] : '';
            if (!isset(Blocks::TYPES[$type])) {
                error_log('Site public : bloc de type inconnu ignoré (page ' . (int) ($page['id'] ?? 0) . ', bloc ' . (int) $i . ')');
                continue;
            }
            try {
                $out .= $this->view->partial('public/blocks/' . $type, ['b' => $b, 'content' => $this->content, 'first' => !isset($seen[$type]), 'home' => $home]);
                $seen[$type] = true;
            } catch (Throwable $e) {
                error_log('Site public : bloc ' . $type . ' ignoré (' . get_class($e) . ', page ' . (int) ($page['id'] ?? 0) . ')');
            }
        }
        return $out;
    }
}
