<?php
declare(strict_types=1);

// Contrastes WCAG des paires de jetons : php bin/check-contrast.php (code 1 si une paire est sous son seuil).
require dirname(__DIR__) . '/app/autoload.php';

$css = (string) file_get_contents(dirname(__DIR__) . '/public/assets/css/tokens.css');
$bad = 0;
foreach (App\Core\ContrastChecker::check($css) as $r) {
    $bad += $r['ok'] ? 0 : 1;
    printf("%s %-16s sur %-16s %s (min %.1f) %s\n", $r['ok'] ? 'OK  ' : 'ECHEC', $r['fg'], $r['bg'], $r['ratio'] === null ? 'jeton introuvable' : sprintf('%5.2f:1', $r['ratio']), $r['min'], $r['use']);
}
echo $bad === 0 ? "Tous les contrastes sont conformes.\n" : "$bad paire(s) sous le seuil.\n";
exit($bad === 0 ? 0 : 1);
