<?php declare(strict_types=1);
/** En-tête commun de l'écran de contrôle (lecture seule) : titre, bandeau, onglets, exports. @var string $tab @var string $heading @var string $subtitle @var ?string $dataset @var array $query */
$items = [['Écritures', '/admin/controle', $tab === 'ecritures'], ['Décaissements', '/admin/controle/decaissements', $tab === 'decaissements'],
    ['Contre-passations', '/admin/controle/contre-passations', $tab === 'contre-passations'], ['Annulations', '/admin/controle/annulations', $tab === 'annulations'],
    ['Soldes et intégrité', '/admin/controle/soldes', $tab === 'soldes']];
$exports = '';
if (($dataset ?? null) !== null) {
    $qs = $query === [] ? '' : '?' . http_build_query($query);
    $exports = ui('button', ['label' => 'Exporter en CSV', 'icon' => 'file', 'variant' => 'secondary', 'href' => '/admin/controle/export/' . $dataset . '.csv' . $qs])
        . ui('button', ['label' => 'Exporter en XLSX', 'icon' => 'file', 'variant' => 'secondary', 'href' => '/admin/controle/export/' . $dataset . '.xlsx' . $qs]);
}
echo ui('page-header', ['title' => $heading, 'subtitle' => $subtitle], $exports);
echo ui('alert', ['kind' => 'info', 'message' => 'Écran de contrôle en lecture seule : aucune écriture ni correction n\'est possible ici. Les contre-passations et approbations caduques sont affichées, jamais masquées.']);
echo ui('tabs', ['label' => 'Sections du contrôle', 'items' => $items]);
