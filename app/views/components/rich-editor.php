<?php declare(strict_types=1);
/**
 * Éditeur riche : un vrai <textarea> (fonctionne sans JS, balises autorisées expliquées) ; editor.js le remplace par une zone éditable.
 * La valeur est toujours renettoyée côté serveur (RichText::fromRequestField).
 * @var array<string,mixed> $props @var Closure $e
 */
$id = 'f-' . ($props['name'] ?? '');
$error = (string) ($props['error'] ?? '');
$hint = (string) ($props['hint'] ?? '');
$required = !empty($props['required']);
$describedBy = trim(($hint !== '' ? $id . '-hint ' : '') . $id . '-help' . ($error !== '' ? ' ' . $id . '-err' : ''));
?>
<div class="field rich-editor<?= $error !== '' ? ' has-error' : '' ?>" data-rich-editor data-picker-url="/admin/site/medias/selecteur">
<label for="<?= $e($id) ?>" id="<?= $e($id) ?>-label"><?= $e($props['label'] ?? '') ?><?= $required ? ' <span class="req" aria-hidden="true">*</span><span class="visually-hidden"> (obligatoire)</span>' : '' ?></label>
<?php if ($hint !== ''): ?><p class="field-hint" id="<?= $e($id) ?>-hint"><?= $e($hint) ?></p>
<?php endif; ?>
<p class="field-hint rich-help" id="<?= $e($id) ?>-help">Balises autorisées : &lt;h2&gt; à &lt;h4&gt; (titres), &lt;p&gt;, &lt;strong&gt;, &lt;em&gt;, &lt;ul&gt;/&lt;ol&gt; avec &lt;li&gt;, &lt;blockquote&gt;, &lt;br&gt;, &lt;a href="https://…"&gt; et &lt;img src="/media/…" alt="description"&gt;. Tout le reste est retiré à l'enregistrement.</p>
<textarea id="<?= $e($id) ?>" name="<?= $e($props['name'] ?? '') ?>" class="control rich-source" rows="12" aria-describedby="<?= $e($describedBy) ?>"<?= $error !== '' ? ' aria-invalid="true"' : '' ?><?= $required ? ' required' : '' ?>><?= $e((string) ($props['value'] ?? '')) ?></textarea>
<?php if ($error !== ''): ?><p class="field-error" id="<?= $e($id) ?>-err" role="alert"><?= ui_icon('alert') ?><span class="visually-hidden">Erreur : </span><?= $e($error) ?></p>
<?php endif; ?>
</div>
