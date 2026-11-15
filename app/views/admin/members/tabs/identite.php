<?php declare(strict_types=1);
/** @var array $m @var array $revealed @var bool $canDocuments @var callable $dl @var callable $val @var callable $fmt */
$pairs = ['Sexe' => $val($m['sex']), 'Date de naissance' => $fmt($m['birth_date']), 'Lieu de naissance' => $val($m['birth_place']), 'Nationalité' => $val($m['nationality']),
    'Téléphone' => $val($m['phone']), 'Autre téléphone' => $val($m['phone_alt']), 'E-mail' => $val($m['email']), 'Adresse' => $val($m['address']), 'Zone' => $val($m['zone_name'])];
if ($canDocuments) {
    $pairs['Pièce d\'identité'] = $val($m['id_document_type']);
    $pairs['Numéro de pièce'] = isset($revealed['id_document_number']) ? (string) $m['id_document_number'] : '—';
}
echo ui('panel', ['title' => 'Identité'], $dl($pairs));
if ($canDocuments && isset($revealed['id_document_number'])) {
    echo '<p class="count-line">La consultation de ce numéro est journalisée.</p>';
}
