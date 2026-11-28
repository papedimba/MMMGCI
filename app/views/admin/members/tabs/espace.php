<?php declare(strict_types=1);
/**
 * Onglet « Espace adhérent » : état de l'accès et actions permises. Aucun code n'est jamais affiché ici (lettre = réponse du POST).
 * @var array $m @var array $espace @var string $csrfToken @var ?Closure $routeExists
 */
use App\Modules\Espace\AccountAdminController;
use App\Modules\Espace\EspaceSettingsService;

$base = '/admin/adherents/' . $m['id'] . '/espace';
$x = $espace;
$acc = $x['account'];
$state = match (true) {
    $acc !== null && $acc['reset_pending'] && $x['state'] === 'en_attente' => 'Nouveau mot de passe en attente : code valable jusqu\'au ' . date_fr($acc['expires']),
    $acc !== null && $acc['reset_pending'] => 'Nouveau mot de passe en attente : code expiré le ' . date_fr($acc['expires']),
    $x['state'] === 'en_attente' => 'En attente d\'activation : code valable jusqu\'au ' . date_fr($acc['expires']),
    $x['state'] === 'code_expire' => 'En attente d\'activation : code expiré' . ($acc['expires'] !== null ? ' le ' . date_fr($acc['expires']) : ''),
    $x['state'] === 'actif' => 'Actif — dernière connexion : ' . date_fr($acc['last_login_at'], 'jamais'),
    default => AccountAdminController::STATES[$x['state']],
};
$correction = $x['correction'] !== null && ($routeExists ?? null) !== null && $routeExists('/admin/espace/corrections/' . $x['correction'])
    ? '<a href="/admin/espace/corrections/' . e((string) $x['correction']) . '">Voir la demande de correction en cours</a>'
    : e($x['correction'] !== null ? 'Une demande en cours' : 'Aucune');
$details = '<dl class="detail-list detail-list-stack">'
    . '<dt>État</dt><dd>' . e($state) . '</dd>'
    . '<dt>Niveau d\'accès (selon le statut)</dt><dd>' . e(EspaceSettingsService::LEVEL_LABELS[$x['level']]) . '</dd>'
    . '<dt>Dernier blocage de connexion</dt><dd>' . e($x['lastBlocked'] === null ? 'Aucun' : date_fr((string) $x['lastBlocked'])) . '</dd>'
    . '<dt>Demande de correction</dt><dd>' . $correction . '</dd></dl>';
if ($x['pending']) {
    $details .= ui('alert', ['kind' => 'info', 'message' => 'Niveau d\'accès : paramètre en attente de validation par les instances. Le défaut technique s\'applique en attendant.']);
}
if ($x['blocker'] !== null) {
    $details .= ui('alert', ['kind' => 'warn', 'message' => $x['blocker']]);
}

$actions = '';
if ($x['form'] !== null) {
    [$path, $label, $intro] = match ($x['form']) {
        'open' => ['', 'Ouvrir l\'accès', 'Un code d\'activation à usage unique est délivré à l\'adhérent. Il ne sera plus jamais affiché ensuite.'],
        'code' => ['/code', 'Délivrer un nouveau code', 'Le code précédent ne fonctionnera plus. Le nouveau code ne sera affiché qu\'une fois.'],
        'reset' => ['/reinitialiser', 'Délivrer un nouveau code', 'À faire au guichet, adhérent présent : son mot de passe et toutes ses sessions sont fermés immédiatement.'],
    };
    $off = $x['emailOff'];
    $actions .= '<form method="post" action="' . e($base . $path) . '" id="espace-issue">'
        . '<input type="hidden" name="_csrf" value="' . e($csrfToken) . '"><input type="hidden" name="_once" value="' . e((string) $x['once']) . '">'
        . '<p>' . e($intro) . '</p>'
        . '<fieldset class="choice-group"><legend>Remise du code</legend>'
        . '<label class="check"><input type="radio" name="remise" value="lettre" checked><span>Lettre à imprimer</span></label>'
        . '<div class="check-item"><label class="check"><input type="radio" name="remise" value="email"' . ($off !== null ? ' disabled aria-describedby="espace-email-off"' : '') . '><span>E-mail à l\'adresse de la fiche</span></label>'
        . ($off !== null ? '<p class="field-hint" id="espace-email-off">' . e($off) . '</p>' : '') . '</div></fieldset>'
        . ($x['form'] === 'reset' ? '<label class="check"><input type="checkbox" name="identite_verifiee" value="1" required><span>J\'ai vérifié l\'identité de l\'adhérent sur une pièce officielle</span></label>' : '')
        . '<div class="form-actions"><button class="btn btn-primary" type="submit"' . ($x['form'] === 'reset' ? ' data-confirm-open="confirm-espace-reset"' : '') . '><span>' . e($label) . '</span></button></div>'
        . '</form>';
    if ($x['form'] === 'reset') {
        $actions .= ui('confirm-dialog', ['id' => 'confirm-espace-reset', 'form' => 'espace-issue', 'title' => 'Réinitialiser l\'accès de cet adhérent ?',
            'message' => 'Son mot de passe actuel cesse de fonctionner et ses sessions sont fermées. L\'opération est consignée au journal avec votre nom.', 'confirm_label' => 'Délivrer le code']);
    }
}
if ($acc !== null && $x['canManage']) {
    $disable = $acc['status'] !== 'desactive';
    $actions .= '<form method="post" action="' . e($base . ($disable ? '/desactiver' : '/reactiver')) . '" id="espace-toggle" class="form-actions">'
        . '<input type="hidden" name="_csrf" value="' . e($csrfToken) . '">'
        . ($disable
            ? '<button class="btn btn-secondary" type="submit" data-confirm-open="confirm-espace-disable"><span>Désactiver l\'accès</span></button>'
            : '<button class="btn btn-secondary" type="submit"><span>Réactiver l\'accès</span></button>')
        . '</form>';
    if ($disable) {
        $actions .= ui('confirm-dialog', ['id' => 'confirm-espace-disable', 'form' => 'espace-toggle', 'title' => 'Désactiver l\'accès à l\'Espace adhérent ?',
            'message' => 'L\'adhérent est déconnecté et ses codes ouverts ne fonctionnent plus. Un nouveau code devra être délivré après une réactivation sans mot de passe.', 'confirm_label' => 'Désactiver']);
    }
}
?>
<?= ui('panel', ['title' => 'Accès à l\'Espace adhérent'], $details) ?>
<?php if ($actions !== ''): ?><?= ui('panel', ['title' => 'Actions'], $actions) ?><?php endif; ?>
