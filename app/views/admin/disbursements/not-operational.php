<?php declare(strict_types=1);
/** @var Closure $can */
?>
<?= ui('page-header', ['title' => 'Circuit de décaissement inopérant', 'subtitle' => 'Les approbations et les paiements restent bloqués tant que le circuit n\'est pas configuré.',
    'breadcrumbs' => [['Décaissements', '/admin/decaissements'], ['Configuration requise', null]]], '') ?>
<?= ui('panel', ['title' => 'Ce que doit faire l\'installateur'],
    '<p>Un décaissement exige deux approbations par deux personnes distinctes, puis un paiement enregistré par une autre personne que l\'initiateur. '
    . 'Aucun rôle ne reçoit ces droits par défaut : le super administrateur les attribue.</p><ol>'
    . '<li>Attribuer la permission « Approuver un décaissement » à <strong>deux personnes distinctes</strong>, actives, qui ne sont pas celles qui initient les demandes.</li>'
    . '<li>Attribuer la permission « Payer un décaissement » à <strong>une troisième personne</strong> (le payeur ne peut pas être l\'initiateur).</li>'
    . '<li>Vérifier que les personnes qui préparent les demandes ont « Initier un décaissement ».</li></ol>'
    . '<p>En attendant, les brouillons peuvent être préparés et soumis ; ils seront approuvés dès que le circuit sera opérant.</p>'
    . ($can('roles.manage') ? '<p><a class="cell-link" href="/admin/roles">Gérer les rôles et leurs permissions</a></p>' : '<p>Adressez-vous au super administrateur (Administration &gt; Rôles).</p>')) ?>
