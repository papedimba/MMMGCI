<?php declare(strict_types=1);
/**
 * Aperçu minimal de la copie de travail (les vrais gabarits publics des blocs viennent en L4) : tout est échappé,
 * le texte riche est renettoyé par RichText::sanitize.
 * @var array<string,mixed> $page @var array{media:array<int,array{url:string,alt:string}>,pages:array<int,string>} $refs
 */
use App\Modules\Cms\Blocks;
use App\Modules\Cms\RichText;

$lines = static fn (string $s): string => nl2br(e($s), false);
?>
<div class="preview-banner" role="note"><strong>Aperçu — non publié</strong> <a href="<?= e('/admin/site/pages/' . $page['id']) ?>">Retour à la page</a></div>
<article class="pub-page preview-page">
<h1><?= e($page['title']) ?></h1>
<?php foreach ($page['blocks'] as $b):
    switch ($b['type'] ?? '') {
        case 'texte':
            echo '<div class="preview-rich">' . RichText::sanitize((string) $b['html']) . '</div>';
            break;
        case 'titre':
            $tag = (int) ($b['level'] ?? 2) === 3 ? 'h3' : 'h2';
            echo "<$tag>" . e((string) $b['text']) . "</$tag>";
            break;
        case 'intro':
            echo '<section class="preview-intro"><h2>' . e((string) $b['title']) . '</h2>' . ((string) $b['note'] !== '' ? '<p>' . $lines((string) $b['note']) . '</p>' : '') . '</section>';
            break;
        case 'faits':
            echo '<dl class="preview-facts">';
            foreach ($b['items'] as $it) {
                echo '<div><dt>' . e((string) $it['label']) . '</dt><dd>' . e((string) $it['value']) . '</dd></div>';
            }
            echo '</dl>';
            break;
        case 'domaines':
            echo '<ul class="preview-list">';
            foreach ($b['items'] as $it) {
                echo '<li><strong>' . e((string) $it['name']) . '</strong>' . ((string) $it['nature'] !== '' ? ' (' . e((string) $it['nature']) . ')' : '')
                    . ((string) $it['description'] !== '' ? '<br>' . $lines((string) $it['description']) : '') . '</li>';
            }
            echo '</ul>';
            break;
        case 'liste':
            echo '<ul class="preview-list">';
            foreach ($b['items'] as $it) {
                echo '<li>' . e((string) $it['text']) . '</li>';
            }
            echo '</ul>';
            break;
        case 'appel':
            $href = $b['page_id'] !== null ? (isset($refs['pages'][$b['page_id']]) ? '/' . $refs['pages'][$b['page_id']] : null) : (string) $b['url'];
            echo '<section class="preview-cta"><h2>' . e((string) $b['title']) . '</h2>' . ((string) $b['text'] !== '' ? '<p>' . $lines((string) $b['text']) . '</p>' : '')
                . ($href !== null && Blocks::isSafeUrl($href) ? '<a class="btn btn-primary" href="' . e($href) . '"><span>' . e((string) $b['button_label']) . '</span></a>' : '') . '</section>';
            break;
        case 'image':
            $m = $refs['media'][$b['media_id']] ?? null;
            echo $m === null ? '<p class="preview-placeholder">Image introuvable.</p>'
                : '<figure class="preview-figure"><img src="' . e($m['url']) . '" alt="' . e($m['alt']) . '" loading="lazy">'
                    . ((string) $b['caption'] !== '' ? '<figcaption>' . e((string) $b['caption']) . '</figcaption>' : '') . '</figure>';
            break;
        default:
            $param = match ($b['type'] ?? '') {
                'documents' => (string) $b['category'] !== '' ? 'catégorie « ' . $b['category'] . ' »' : 'toutes catégories',
                'articles', 'evenements' => (int) $b['count'] . ' au plus',
                'formulaire' => Blocks::FORMS[$b['form_key']] ?? '',
                default => '',
            };
            echo '<p class="preview-placeholder">' . e('Bloc « ' . (Blocks::TYPES[$b['type'] ?? ''] ?? 'inconnu') . ' » (' . $param . ') : contenu dynamique affiché sur le site public.') . '</p>';
    }
endforeach; ?>
</article>
