# Lot L4 (site public et adhésion en ligne) — décisions et points reportés

Base 36fc570 (L3 terminé) ; six tâches (8883505, 60a1a8b, e977e11/763bca3, 28b0a25/b8efcdb/44a578d, 2becb26/0573dff, puis le contrôle de qualité). Suite complète verte en fin de lot, `bin/check-contrast.php` conforme. Plan : `2026-11-16-l4-site-public.md`.

## Décisions de conception (à confirmer par la Mutuelle)
1. **Adhésion en ligne fermée par défaut** : elle s'ouvre seulement quand les instances valident **deux** réglages, le texte de consentement (`site.consent_text`, fourni par la Mutuelle) puis l'ouverture (`site.adhesion_open`). Fermée : aucune session, aucune écriture, page « Les adhésions en ligne ouvriront prochainement. ».
2. **Texte du site issu du cahier des charges, non adopté à ce jour : tout est semé en brouillon.** Un humain relit et publie ; le script de semis refuse `--publish`. Les rubriques sans source (mot du Président, historique, mentions légales, confidentialité, galerie) ne sont pas créées.
3. **Pas de photo** : dessins originaux du métier, toujours légendés « Illustration » ; les vraies photos arrivent par la médiathèque.
4. **Cookies limités aux formulaires** : session seulement sur `/contact` et `/partenariat` (page hôte en ligne, adresse exacte) et sur `/adhesion-en-ligne` (ouverte). Le reste du site n'envoie aucun cookie.
5. **Aucun CAPTCHA tiers** : champ piège, délai minimal signé (8 s), limites par IP (`App\Core\IpKey`, IPv6 par /64).

## Rulings pris pendant l'exécution
1. **Adresses plates** : on suit le schéma de L3 (`/{slug}`) ; la forme `/parent/enfant` du plan est remplacée (PublishedContent redirige déjà).
2. **Auteur d'un article** : seul le libellé d'auteur explicite est affiché, jamais le nom réel du membre du personnel (vie privée) ; certains articles n'ont donc pas d'auteur.
3. **Bloc `dossier`** (« Préparer mon dossier ») ajouté au registre de blocs de L3, éditeur d'administration compris.
4. **Listes fixes** : `/actualites`, `/evenements`, `/documents`, `/galeries` sont des routes, pas des pages ; les éléments de menu correspondants sont semés **inactifs** (à activer quand la Mutuelle publie).
5. **Accueil** (tâche 6) : titre semé = devise « Un nouveau jour se lève » (cahier, page de garde) ; le nom complet passe en titre SEO. Le nom complet (11 mots) en h1 occupait 7 lignes à 1440 px (Taste : FAIL).
6. **Bouton principal de l'accueil** (tâche 6) : « Demander mon adhésion » vers `/adhesion-en-ligne` quand l'adhésion est ouverte, sinon « Comment adhérer » vers la page d'information `adhesion` (si elle est en ligne). L'accueil est revalidé à chaque visite (`no-cache` + ETag), comme les pages à bloc formulaire, pour que le bouton suive l'ouverture.
7. **Fil d'Ariane** (tâche 6) : supprimé sur les pages de premier niveau (standard, listes, contact) ; réduit à « Accueil », il se lisait comme un petit label au-dessus du titre, ce que la direction interdit ; le logo ramène à l'accueil. Conservé à deux niveaux (article, évènement, galerie).
8. **Pages Emploi, Formation, Partenariats** (tâche 6) : un seul objectif de l'art. 2 y est cité, d'où le titre au singulier « Objectif spécifique » (ajouté à la liste blanche du test de fidélité, comme singulier du titre de l'article) ; sur Emploi et Formation, un seul domaine de l'art. 9 : la réserve devient la réserve du domaine (« Sous réserve des ressources disponibles, des textes applicables et des critères adoptés », sous-chaîne exacte de l'art. 9), au lieu de « …les domaines d'intervention suivants : » au pluriel devant un seul domaine. Le semis ne remplace jamais une page existante : une base déjà semée garde l'ancienne formulation.
9. **Accusé de contact** (tâche 6) : « Message envoyé » seulement juste après un vrai envoi (marque à usage unique dans la session du formulaire) ; `/contact?envoye=1` sans envoi affiche le formulaire.
10. **Ancienne adresse non normalisée** (tâche 6) : `/Ancien-slug/` va en une seule 301 vers la nouvelle adresse (au lieu de deux).
11. **Un seul rayon** (tâche 6) : carte de vérification et aperçu d'administration passés à `--r-sm` ; résumé d'erreurs de l'adhésion encadré (plus de filet latéral, signalé par `impeccable detect`).

## Contrôle de qualité (tâche 6)
- Parcours de bout en bout (`tests/Feature/SiteEndToEndTest.php`) : semis en brouillon → 404 → publication → page, menu et plan du site → adhésion fermée → deux réglages validés → demande en cinq étapes avec pièce → demande « En ligne » dans `/admin/adherents/demandes` → message dans la boîte de réception → page programmée publiée par la tâche cron réelle. Balayage des routes publiques : CSP et `nosniff` partout, aucune session hors formulaires, PUT/DELETE/PATCH → 405.
- Taste `lint.mjs` : 0 FAIL ; `inspect.mjs` à 1440/768/390 (accueil, page intérieure, Emploi, adhésion fermée et ouverte, contact, 404) : 0 FAIL, avec `--allow few-pictures,no-real-pictures,no-footage` (aucune photo par contrat). WARN restants assumés : palette crème (papier de la direction), corps 15 px sur les pages intérieures, titre « nom seul » (Emploi).
- Playwright : 1440, 768 tactile, 390 et 360 mobile sur 6 pages et les 5 étapes de l'adhésion : une h1, aucun défilement horizontal, aucune cible tactile sous 44 px (hors liens dans le texte).
- Mesures (390 px, « 3G rapide » simulée 1,5 Mbit/s et 300 ms, cache vide, **sans compression** sous `php -S`) : accueil 15 requêtes, 153 Ko, premier affichage ~1 s, chargement ~1,8 s ; pages intérieures ~148 Ko.
- Contrôle visuel sur une base jetable (`mmmgci_qa`, supprimée ensuite) : la base de développement et son journal d'audit n'ont pas été touchés.

## Points reportés ou acceptés
- **Étude de sites comparables : non réalisée**, l'accès réseau était bloqué (voir `docs/conception/etude-sites-comparables.md`).
- **À fournir par la Mutuelle** : relecture et publication des pages semées, texte de consentement, coordonnées, mot du Président, historique, mentions légales, confidentialité, photos et galerie ; logo transparent (le JPEG laisse un cadre clair).
- Cache public de 5 min : un retrait urgent reste visible jusqu'à 5 min chez les intermédiaires (pages sans formulaire).
- Bascule de l'ouverture entre le prédicat de session et la garde : page sans session puis 419 au POST suivant, sans écriture.
- Plafond global de 200 Mo des pièces de brouillon saturable exprès (plus de téléversement pendant au plus 6 h, envoi sans pièce possible) ; une pièce de plus de 6 h peut être purgée entre le récapitulatif et l'envoi.
- POST sur `/contact` quand la page hôte est hors ligne : 419 en production (aucune session), rien n'est enregistré.
- Limite par IP partagée derrière un proxy ou un CDN (`REMOTE_ADDR`) ; elle compte aussi les envois invalides des formulaires.
- `.htaccess` : `X-Content-Type-Options` envoyé deux fois sur les pages PHP ; une 404 PHP sous `/media/` hérite du cache d'un jour ; `<If>` exige Apache 2.4 (à vérifier sur l'hébergement, LiteSpeed partiel).
- Variantes d'image créées à la demande pour les médias antérieurs (coût GD unique ; répété si le disque public n'est pas inscriptible) ; assets de l'administration non versionnés (revalidés).
- Garde de fidélité du semis par sous-chaîne : une citation tronquée ou un titre court déjà présent ailleurs passerait ; la liste blanche contient trois libellés qui ne sont pas des phrases du cahier (« Livret maritime disponible », « Ayants droit à déclarer », « Objectif spécifique »).
- `SmokeTest` : la partie sur un vrai serveur (`php -S`) lit toujours `.env`, donc la base de développement, mais n'y vérifie que les cookies et en-têtes, jamais le contenu.
