# Lot L2 — décisions et points reportés

Branche `ccr-5416d63c-xuo00t`, commits a455524..63f96f1. Suite : 395 tests, `bin/check-contrast.php` : 32 paires conformes.

## Décisions prises pendant l'exécution
1. `layouts/base.php` devient le document minimal partagé des pages de connexion et d'erreur ; `public.php` et `admin.php` sont des documents séparés. Coût si faux : aucun.
2. Le contrôle « la route existe » du menu est partagé par un middleware global (`ShareLayoutData` + `View::share()`), pas par chaque contrôleur ; les données du contrôleur l'emportent. Coût : un état partagé à surveiller entre requêtes (testé).
3. Drapeau national et cordage : classes CSS / image SVG, jamais de `style=` (CSP).
4. Pas de CDN de polices à l'exécution ; polices OFL auto-hébergées (Marcellus, Source Sans 3).
5. Ajout du jeton `--control-border` (#7D8AA0) : `--line-strong` ne donne que 1,78:1 sur blanc, insuffisant pour les champs (3:1).
6. Menu mobile et bouton d'impression fonctionnent sans JS (classe `html.js` posée par `js-flag.js`).
7. Routes de la galerie non déclarées en production (404 même anonyme).
8. Rôle protégé en lecture seule : changement de vue seulement (`RoleService::mayEdit`), règles serveur inchangées.
9. Relectures de tâche faites par un relecteur unique de niveau intermédiaire ; relecture finale sur le modèle le plus capable.

## Points reportés (voir aussi TASKS.md)
- Logo transparent (vectoriel) : les JPEG laissent un léger rectangle sur papier/blanc.
- Pointillés de menu et emplacement photo : à construire en L4.
- `attrs` de `ui('field')` : liste noire (on*, style, formaction) ; passer en liste blanche si les appelants se multiplient ; ajouter `form`, `formtarget`, `formmethod`, `formenctype`.
- Test du code de sortie en échec de `bin/check-contrast.php` ; couleurs codées en dur dans `cordage.svg`.
- `confirm-dialog` sans JS : l'action passe sans confirmation (à revisiter pour les suppressions définitives).
- Cibles < 44 px à la souris (corrigées au tactile) ; points de rupture CSS non harmonisés ; `.sheet` a deux sens (admin/print) ; couleur de l'étape « En attente » à trancher en L6 ; pas de cache/versionnement des assets (`?v=`).
- Favicon : PNG sous l'extension .ico ; captures (4,1 Mo) non versionnées (ignorées).
