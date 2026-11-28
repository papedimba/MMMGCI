<?php declare(strict_types=1);
/** Étape 3 : coordonnées et profession (référentiels actifs). @var array<string,mixed> $values @var array<string,string> $errors @var array<string,array<int,string>> $options */
$p = static fn (string $name, string $label, array $extra = []): array => ['name' => $name, 'label' => $label, 'value' => (string) ($values[$name] ?? ''), 'error' => $errors[$name] ?? ''] + $extra;
?>
<?= ui('field', $p('phone', 'Téléphone', ['type' => 'tel', 'autocomplete' => 'tel', 'inputmode' => 'tel', 'hint' => 'Avec « + » en tête pour un numéro international.', 'attrs' => ['maxlength' => 30]])) ?>
<?= ui('field', $p('email', 'Adresse e-mail (facultatif)', ['type' => 'email', 'autocomplete' => 'email', 'hint' => 'Si vous l\'indiquez, un accusé de réception avec votre référence peut vous être envoyé.', 'attrs' => ['maxlength' => 190]])) ?>
<?php if (($options['zone_id'] ?? []) !== []): ?><?= ui('field', $p('zone_id', 'Zone', ['type' => 'select', 'options' => ['' => 'Choisir…'] + $options['zone_id']])) ?>
<?php endif; ?>
<?php if (($options['profession_id'] ?? []) !== []): ?><?= ui('field', $p('profession_id', 'Profession', ['type' => 'select', 'options' => ['' => 'Choisir…'] + $options['profession_id']])) ?>
<?php endif; ?>
<?php if (($options['specialty_id'] ?? []) !== []): ?><?= ui('field', $p('specialty_id', 'Spécialité', ['type' => 'select', 'options' => ['' => 'Choisir…'] + $options['specialty_id']])) ?>
<?php endif; ?>
