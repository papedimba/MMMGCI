<?php declare(strict_types=1);
/**
 * Étape 5 : récapitulatif (tout échappé) et consentement : texte lu du réglage validé site.consent_text uniquement.
 * @var array<string,mixed> $values @var list<string> $flags @var array<string,string> $errors @var array<string,array<int,string>> $options
 * @var list<array{file_id:int,type:string,size:int}> $files @var array<string,string> $types @var string $consent
 */
use App\Modules\Site\ApplyFlow;

$show = static function (string $k) use ($values, $options): string {
    $v = (string) ($values[$k] ?? '');
    if (isset(ApplyFlow::REFS[$k])) {
        return $v === '' ? '' : (string) ($options[$k][(int) $v] ?? '');
    }
    return $k === 'birth_date' && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $v, $m) === 1 ? "$m[3]/$m[2]/$m[1]" : $v;
};
?>
<h2 class="apply-sub">Récapitulatif</h2>
<?php foreach ([1, 2, 3] as $step): ?>
<section class="apply-recap">
<h3><?= e(ApplyFlow::STEPS[$step]) ?> <a class="apply-edit" href="/adhesion-en-ligne/etape/<?= (int) $step ?>">Modifier<span class="visually-hidden"> : <?= e(ApplyFlow::STEPS[$step]) ?></span></a></h3>
<dl class="pub-facts-inline">
<?php foreach (ApplyFlow::STEP_FIELDS[$step] as $k): ?>
<div><dt><?= e(ApplyFlow::LABELS[$k]) ?></dt><dd><?= e($show($k) !== '' ? $show($k) : 'Non renseigné') ?></dd></div>
<?php endforeach; ?>
<?php if ($step === 1): ?>
<?php foreach (ApplyFlow::FLAGS as $key => $label): ?><div><dt><?= e($label) ?></dt><dd><?= e(in_array($key, $flags, true) ? 'Oui' : 'Non') ?></dd></div>
<?php endforeach; ?>
<?php endif; ?>
</dl>
</section>
<?php endforeach; ?>
<section class="apply-recap">
<h3><?= e(ApplyFlow::STEPS[4]) ?> <a class="apply-edit" href="/adhesion-en-ligne/etape/4">Modifier<span class="visually-hidden"> : <?= e(ApplyFlow::STEPS[4]) ?></span></a></h3>
<?php if ($files === []): ?><p>Aucune pièce jointe.</p>
<?php else: ?><ul class="apply-files">
<?php foreach ($files as $file): ?><li><?= e($types[$file['type']] ?? $file['type']) ?></li>
<?php endforeach; ?>
</ul>
<?php endif; ?>
</section>
<h2 class="apply-sub">Consentement</h2>
<div class="apply-consent" id="apply-consent"><p><?= e_lines($consent) ?></p></div>
<div class="field<?= e(isset($errors['consent']) ? ' has-error' : '') ?>">
<label class="pub-check apply-accept"><input type="checkbox" name="consent" id="f-consent" value="1" required aria-describedby="apply-consent<?= e(isset($errors['consent']) ? ' f-consent-err' : '') ?>"<?php if (isset($errors['consent'])): ?> aria-invalid="true"<?php endif; ?>> <span>J'ai lu et j'accepte</span></label>
<?php if (isset($errors['consent'])): ?><p class="field-error" id="f-consent-err"><?= e($errors['consent']) ?></p>
<?php endif; ?>
</div>
