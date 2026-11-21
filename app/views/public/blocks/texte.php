<?php declare(strict_types=1);
/** Bloc texte : seul HTML admis d'une page, déjà nettoyé à l'enregistrement et renettoyé ici. @var array<string,mixed> $b */
use App\Modules\Cms\RichText;
?>
<div class="pub-block pub-rich"><?= RichText::sanitize(plain($b['html'] ?? '')) ?></div>
