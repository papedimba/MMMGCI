<?php declare(strict_types=1);
/** @var array $m @var array $revealed @var bool $canDocuments @var callable $dl @var callable $val @var callable $fmt */
$pairs = ['Catégorie' => $val($m['category_name']), 'Profession' => $val($m['profession_name']), 'Spécialité' => $val($m['specialty_name']),
    'Situation' => $val($m['employment_status']), 'Employeur' => $val($m['employer']), 'Date d\'adhésion' => $fmt($m['joined_at'])];
if ($canDocuments) {
    $pairs['Livret maritime'] = isset($revealed['seaman_book_number']) ? (string) $m['seaman_book_number'] : '—';
    $pairs['Expiration du livret'] = $fmt($m['seaman_book_expiry']);
}
echo ui('panel', ['title' => 'Profession'], $dl($pairs));
if ($canDocuments && isset($revealed['seaman_book_number'])) {
    echo '<p class="count-line">La consultation de ce numéro est journalisée.</p>';
}
