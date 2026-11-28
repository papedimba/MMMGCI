<?php declare(strict_types=1);
/**
 * Lettre remise à l'adhérent : seul endroit où le code apparaît, une seule fois (réponse du POST, non mise en cache).
 * @var array $m @var string $code @var bool $reset @var string $url
 */
?>
<p>À l'attention de <strong><?= e($m['last_name'] . ' ' . $m['first_names']) ?></strong>, membre n° <strong><?= e($m['member_number']) ?></strong>.</p>
<p><?= $reset
    ? 'Votre accès à l\'Espace adhérent a été réinitialisé au guichet : votre ancien mot de passe ne fonctionne plus. Utilisez le code ci-dessous pour en choisir un nouveau.'
    : 'Un accès à l\'Espace adhérent de la Mutuelle a été ouvert pour vous. Il vous permet de consulter votre fiche, vos cotisations et vos demandes d\'assistance.' ?></p>
<h2>Votre code personnel</h2>
<p class="activation-code"><?= e($code) ?></p>
<p>Code valable jusqu'au <strong><?= e(date_fr($m['expires'])) ?></strong>.</p>
<h2>Marche à suivre</h2>
<ol class="letter-steps">
<li>Ouvrez l'adresse <strong class="card-url"><?= e($url) ?></strong> sur un téléphone ou un ordinateur.</li>
<li>Saisissez votre numéro de membre (<?= e($m['member_number']) ?>) et votre date de naissance.</li>
<li>Saisissez le code ci-dessus (les tirets sont facultatifs).</li>
<li>Choisissez votre mot de passe (12 caractères au moins) : vous vous connecterez ensuite avec votre numéro de membre et ce mot de passe.</li>
</ol>
<p class="letter-warning"><strong>Ce code ne sert qu'une fois. Ne le communiquez à personne.</strong> La Mutuelle ne vous le demandera jamais.</p>
