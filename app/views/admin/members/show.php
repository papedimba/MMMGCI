<?php declare(strict_types=1);
/** @var array $m @var string $tab @var array $tabs @var bool $canDocuments @var array $revealed @var array $rows */
$base = '/admin/adherents/' . $m['id'];
$items = [];
foreach ($tabs as $key => $label) {
    $items[] = [$label, $base . ($key === 'identite' ? '' : '?onglet=' . $key), $key === $tab];
}
$kinds = ['actif' => 'ok', 'suspendu' => 'warn', 'radie' => 'danger', 'decede' => 'neutral', 'ancien_marin' => 'info'];
$fmt = static fn (?string $d): string => $d === null || $d === '' ? '—' : date('d/m/Y', strtotime($d));
$val = static fn (mixed $v): string => $v === null || $v === '' ? '—' : (string) $v;
/** Liste de définitions : valeurs échappées ici, jamais de HTML saisi. @param array<string,string> $pairs */
$dl = static function (array $pairs): string {
    $out = '<dl class="detail-list">';
    foreach ($pairs as $k => $v) {
        $out .= '<dt>' . e($k) . '</dt><dd>' . e($v) . '</dd>';
    }
    return $out . '</dl>';
};
?>
<?= ui('page-header', ['title' => $m['last_name'] . ' ' . $m['first_names'], 'subtitle' => 'N° ' . $m['member_number'],
    'breadcrumbs' => [['Adhérents', '/admin/adherents'], [$m['last_name'] . ' ' . $m['first_names'], null]]],
    ui('badge', ['label' => $m['status_label'] ?? $m['status'], 'kind' => $kinds[$m['status']] ?? 'neutral'])) ?>
<?= ui('tabs', ['label' => 'Sections de la fiche', 'items' => $items]) ?>
<?php require __DIR__ . '/tabs/' . str_replace('-', '_', $tab) . '.php'; ?>
