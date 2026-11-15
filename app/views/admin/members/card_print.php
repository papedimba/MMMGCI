<?php declare(strict_types=1);
/** @var array $m @var array $card @var string $verifyUrl @var ?string $photoUrl */
?>
<div>
<div class="card-cr80">
<img class="card-logo" src="/assets/img/logo-complet@2x.jpg" alt="" width="118" height="104">
<p class="card-head">Mutuelle des Marins Marchands et Gens de Mer de Côte d'Ivoire</p>
<?php if ($photoUrl !== null): ?><img class="card-photo" src="<?= e($photoUrl) ?>" alt="Photo de <?= e($m['first_names'] . ' ' . $m['last_name']) ?>">
<?php else: ?><div class="card-photo" role="img" aria-label="Pas de photo"></div>
<?php endif; ?>
<div class="card-id"><p class="card-name"><?= e($m['last_name'] . ' ' . $m['first_names']) ?></p><p class="card-number">N° <?= e($m['member_number']) ?></p></div>
<div class="card-qr" data-qr-url="<?= e($verifyUrl) ?>" role="img" aria-label="QR de vérification de la carte"></div>
</div>
<p>Carte <?= e($card['card_number']) ?> · Vérification : <span class="card-url"><?= e($verifyUrl) ?></span></p>
</div>
<script src="/assets/js/qrcode.js"></script>
<script src="/assets/js/card-qr.js"></script>
