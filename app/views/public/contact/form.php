<?php declare(strict_types=1);
/**
 * Formulaire public (contact, information, partenariat) : POST simple sans JavaScript, champ piège « website », erreurs reliées aux champs.
 * Coordonnées : validées seulement ($contacts, SiteController::site). Texte de consentement : seulement s'il est validé.
 * @var string $path @var string $key @var list<string> $keys @var array<string,array{0:string,1:int,2:bool,3:string}> $fields
 * @var array<string,mixed> $values @var array<string,string> $errors @var string $csrfToken @var array<string,string> $contacts @var string $body @var string $consent
 */
$types = ['email' => 'email', 'phone' => 'tel', 'area' => 'textarea', 'text' => 'text'];
$auto = ['name' => 'name', 'email' => 'email', 'phone' => 'tel', 'organisation' => 'organization'];
$names = array_map(static fn (array $f): string => $f[0], \App\Modules\Cms\FormInbox::FORMS);
?>
<article class="pub-page">
<header class="pub-head">
<h1><?= e($title) ?></h1>
</header>
<div class="pub-body">
<?= $body ?>
<?php if ($contacts !== []): ?>
<section class="pub-block contact-details" aria-labelledby="contact-details-title">
<h2 id="contact-details-title">Nos coordonnées</h2>
<address>
<?php if (isset($contacts['address'])): ?><p><?= e_lines($contacts['address']) ?></p>
<?php endif; ?>
<?php if (isset($contacts['contact_phone'])): ?><p><a href="<?= e('tel:' . preg_replace('~[^+0-9]~', '', $contacts['contact_phone'])) ?>"><?= e($contacts['contact_phone']) ?></a></p>
<?php endif; ?>
<?php if (isset($contacts['contact_email'])): ?><p><a href="<?= e('mailto:' . $contacts['contact_email']) ?>"><?= e($contacts['contact_email']) ?></a></p>
<?php endif; ?>
</address>
</section>
<?php endif; ?>
<?php if ($path === '/partenariat'): ?><p class="pub-block pub-form-link"><a class="btn btn-secondary" href="/partenaires"><span>Nos partenariats</span></a></p>
<?php endif; ?>
<div class="apply-card apply pub-block">
<div class="cordage" aria-hidden="true"></div>
<div class="apply-in">
<?php if (count($keys) > 1): ?>
<nav class="contact-switch" aria-label="Type de demande">
<?php foreach ($keys as $k): ?>
<a href="<?= e($path . ($k === $keys[0] ? '' : '?demande=' . $k)) ?>"<?php if ($k === $key): ?> aria-current="page"<?php endif; ?>><?= e($names[$k]) ?></a>
<?php endforeach; ?>
</nav>
<?php endif; ?>
<?php if ($errors !== []): ?>
<div class="apply-errors" role="alert" tabindex="-1" id="contact-errors">
<h2>Vérifiez les points suivants</h2>
<ul>
<?php foreach ($errors as $field => $msg): ?>
<li><a href="<?= e(isset($fields[$field]) ? '#f-' . $field : '#contact-form') ?>"><?= e($msg) ?></a></li>
<?php endforeach; ?>
</ul>
</div>
<?php endif; ?>
<form method="post" action="<?= e($path) ?>" id="contact-form" class="apply-form" novalidate>
<input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">
<?php if (count($keys) > 1): ?><input type="hidden" name="demande" value="<?= e($key) ?>">
<?php endif; ?>
<?php foreach ($fields as $name => [$label, $max, $required, $type]): ?>
<?= ui('field', ['name' => $name, 'label' => $label, 'type' => $types[$type] ?? 'text', 'required' => $required, 'value' => plain($values[$name] ?? ''), 'error' => $errors[$name] ?? '',
    'autocomplete' => $auto[$name] ?? null, 'attrs' => ['maxlength' => $max]]) ?>
<?php endforeach; ?>
<div class="apply-trap" aria-hidden="true"><label for="f-website">Ne pas remplir ce champ</label><input type="text" id="f-website" name="website" value="" tabindex="-1" autocomplete="off"></div>
<?php if ($consent !== ''): ?><div class="apply-consent"><?= e_lines($consent) ?></div>
<?php endif; ?>
<div class="apply-nav">
<button type="submit" class="btn btn-primary"><span>Envoyer</span></button>
</div>
</form>
</div>
</div>
</div>
</article>
