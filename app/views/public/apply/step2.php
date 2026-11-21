<?php declare(strict_types=1);
/** Étape 2 : identité. @var array<string,mixed> $values @var array<string,string> $errors */
$p = static fn (string $name, string $label, array $extra = []): array => ['name' => $name, 'label' => $label, 'value' => (string) ($values[$name] ?? ''), 'error' => $errors[$name] ?? ''] + $extra;
$sex = (string) ($values['sex'] ?? '');
?>
<?= ui('field', $p('last_name', 'Nom', ['required' => true, 'autocomplete' => 'family-name', 'attrs' => ['maxlength' => 100]])) ?>
<?= ui('field', $p('first_names', 'Prénoms', ['required' => true, 'autocomplete' => 'given-name', 'attrs' => ['maxlength' => 100]])) ?>
<fieldset class="apply-group apply-inline"<?php if (isset($errors['sex'])): ?> aria-describedby="f-sex-err" aria-invalid="true"<?php endif; ?>>
<legend>Sexe</legend>
<?php foreach (\App\Modules\Site\ApplyFlow::SEXES as $i => $label): ?>
<label class="pub-check"><input type="radio" name="sex" id="f-sex-<?= (int) $i ?>" value="<?= e($label) ?>"<?= e($sex === $label ? ' checked' : '') ?>> <span><?= e($label) ?></span></label>
<?php endforeach; ?>
<?php if (isset($errors['sex'])): ?><p class="field-error" id="f-sex-err"><?= e($errors['sex']) ?></p>
<?php endif; ?>
</fieldset>
<?= ui('field', $p('birth_date', 'Date de naissance', ['type' => 'date', 'hint' => 'Format JJ/MM/AAAA si votre téléphone ne propose pas de calendrier.', 'autocomplete' => 'bday'])) ?>
<?= ui('field', $p('birth_place', 'Lieu de naissance', ['attrs' => ['maxlength' => 120]])) ?>
<?= ui('field', $p('nationality', 'Nationalité', ['attrs' => ['maxlength' => 80]])) ?>
