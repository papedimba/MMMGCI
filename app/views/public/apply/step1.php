<?php declare(strict_types=1);
/** Étape 1 : situation (catégorie de l'art. 5, référentiel actif ; indications de l'art. 6 gardées dans le brouillon). @var array<string,mixed> $values @var list<string> $flags @var array<string,string> $errors @var array<string,array<int,string>> $options */
$current = (string) ($values['category_id'] ?? '');
$cats = $options['category_id'] ?? [];
$i = 0;
?>
<?php if ($cats !== []): ?>
<fieldset class="apply-group"<?php if (isset($errors['category_id'])): ?> aria-describedby="f-category_id-err" aria-invalid="true"<?php endif; ?>>
<legend>Votre catégorie</legend>
<p class="field-hint">Facultatif : choisissez la catégorie qui vous correspond.</p>
<?php foreach ($cats as $id => $name): ?>
<label class="pub-check"><input type="radio" name="category_id" id="f-category_id-<?= (int) $i++ ?>" value="<?= (int) $id ?>"<?= e($current === (string) $id ? ' checked' : '') ?>> <span><?= e($name) ?></span></label>
<?php endforeach; ?>
<label class="pub-check"><input type="radio" name="category_id" value=""<?= e($current === '' ? ' checked' : '') ?>> <span>Je ne sais pas</span></label>
<?php if (isset($errors['category_id'])): ?><p class="field-error" id="f-category_id-err"><?= e($errors['category_id']) ?></p>
<?php endif; ?>
</fieldset>
<?php endif; ?>
<fieldset class="apply-group">
<legend>Votre dossier</legend>
<p class="field-hint">Ces indications servent à préparer la liste de vos pièces ; elles ne sont pas envoyées avec la demande.</p>
<?php foreach (\App\Modules\Site\ApplyFlow::FLAGS as $key => $label): ?>
<label class="pub-check"><input type="checkbox" name="<?= e($key) ?>" value="1"<?= e(in_array($key, $flags, true) ? ' checked' : '') ?>> <span><?= e($label) ?></span></label>
<?php endforeach; ?>
</fieldset>
