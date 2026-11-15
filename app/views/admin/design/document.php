<?php declare(strict_types=1);
/** Document d'exemple pour le gabarit d'impression : contenu fictif, aucune donnée réelle. */
?>
<p>Ceci est un document d'exemple destiné à vérifier la mise en page A4 (en-tête, pied de page, découpage des pages).</p>
<table>
<thead><tr><th scope="col">N°</th><th scope="col">Libellé (exemple)</th><th scope="col">Statut</th></tr></thead>
<tbody>
<?php for ($i = 1; $i <= 40; $i++): ?><tr><td><?= $i ?></td><td>Ligne d'exemple <?= $i ?> — texte fictif</td><td><?= $i % 3 === 0 ? 'En attente' : 'Validé' ?></td></tr>
<?php endfor; ?>
</tbody>
</table>
<h2>Carte de membre (squelette CR80, contenu fictif)</h2>
<div class="card-cr80">
<img class="card-logo" src="/assets/img/logo-complet@2x.jpg" alt="" width="118" height="104">
<p class="card-head">Mutuelle des Marins Marchands et Gens de Mer de Côte d'Ivoire</p>
<div class="card-photo" role="img" aria-label="Emplacement de la photo"></div>
<div class="card-id"><p class="card-name">« Nom du membre »</p><p class="card-number">« N° de membre »</p></div>
<div class="card-qr" role="img" aria-label="Emplacement du QR"></div>
</div>
<p>Emplacements photo et QR vides : les données et le QR viennent du lot L5.</p>
