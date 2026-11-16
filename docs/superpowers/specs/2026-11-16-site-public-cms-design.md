# Site public et CMS (lots L3 et L4) — conception

> Décidé le 16/11/2026. Le commanditaire a demandé de s'inspirer de sites comparables et du cahier des charges, puis de lancer sans questions avec les choix par défaut ci-dessous. Chaque choix est mon choix, à corriger si besoin ; aucun n'a été validé par la Mutuelle.

## Intention

Donner à la Mutuelle un site public où un marin, le plus souvent sur son téléphone, comprend en un coup d'œil ce qu'est la Mutuelle, ce qu'elle peut faire pour lui et comment adhérer ; et un espace d'administration où le personnel désigné publie sans développeur. Le recensement des adhérents est la priorité n° 1 du plan d'action 2026-2027 (cahier des charges, chapitre X) : le site doit amener un marin à déposer sa demande d'adhésion.

## Objectifs, non-objectifs, anti-objectifs

- **Objectifs** : adhésion en ligne qui alimente le circuit L5 ; information claire sur les aides (art. 9-10) ; emploi et formation ; transparence (documents officiels) ; contact ; administration sans code ; rapide sur connexion moyenne (accueil < 200 Ko hors photos).
- **Non-objectifs (V0.2)** : espace adhérent (L15) ; paiement en ligne ; forum, commentaires ; multilinguisme ; application mobile.
- **Anti-objectifs** : contenu inventé ; texte juridique inventé ; un site qui promet une aide automatique (art. 9-10 : « sous réserve des ressources », « après examen du dossier ») ; une page vide présentée comme finie ; une photo d'illustration présentée comme photo de la Mutuelle ; un brouillon visible du public.

## Références étudiées

**État honnête : l'étude de terrain n'a pas pu être faite.** Les sites comparables (Nautilus Welfare Fund, AMOSUP, ISWAN, Seafarers Trust, ENIM, ITF) sont hors du réseau autorisé de la session ; seuls les résultats d'une recherche web ont été lus (titres et extraits), sans ouvrir les pages. Aucun site officiel d'association ou de syndicat de marins ivoirien n'a été trouvé (seulement des articles de presse sur le SYMAPECI et le SY.M.I.COM). Voir `docs/conception/etude-sites-comparables.md`.

Les patterns ci-dessous sont donc des **hypothèses de conception** tirées du cahier des charges et de pratiques courantes, à confirmer quand l'étude sera possible : dire d'abord à qui s'adresse l'aide, lister les aides concrètes avec leurs réserves (art. 9-10), montrer la démarche en étapes, afficher la gouvernance et les rapports, un contact visible partout ; éviter les fenêtres surgissantes, le jargon et les pages lourdes.

## Approches considérées

| Approche | Avantage | Limite | Verdict |
|---|---|---|---|
| A. Pages en blocs structurés (types de blocs fixes, rendus par gabarits PHP) + éditeur riche minimal dans le bloc « texte » | Mise en page cohérente garantie, sécurité (aucun HTML libre hors bloc texte nettoyé), simple sur cPanel | Moins de liberté qu'un constructeur de pages | **Retenue** |
| B. Constructeur visuel libre (glisser-déposer) | Liberté totale | JavaScript lourd, risque XSS, rendu incohérent, hors du cadre « sans Node » | Écartée |
| C. Pages écrites en code | Rapide à faire | Le personnel dépend d'un développeur | Écartée (contredit l'objectif) |

## Choix par défaut (mes choix)

1. **Première action de l'accueil** : « Demander mon adhésion » ; seconde : « Voir les aides » ; troisième : « Offres d'emploi ».
2. **Contenu** : les textes institutionnels viennent **uniquement** du cahier des charges (objectifs art. 1-2, organes art. 3, conditions d'adhésion art. 5, pièces art. 6, carte art. 7, domaines d'aide art. 9, fonds art. 10, mobilisation art. 11, partenariats art. 12, transparence art. 13-15, obligations art. 17). Le document n'étant pas encore adopté par les instances (art. 21-23), ces pages sont créées **en brouillon** : un humain les relit et les publie. Rien n'est publié automatiquement.
3. **Pages sans source** (mot du Président, historique, coordonnées, partenaires signataires, actualités, photos) : absentes ou masquées avec un état vide honnête tant que la Mutuelle ne les fournit pas.
4. **Images** : aucune photo n'est inventée ni téléchargée sans crédit. À défaut, dessins SVG d'objets du métier (livret, ancre, cordage) créés pour le site ; la médiathèque accepte les vraies photos (alt obligatoire, crédit).
5. **Adhésion en ligne** : formulaire en étapes avec pièces (art. 6) qui crée une **demande** (jamais un membre) via `ApplicationService::submit` avec la source `en_ligne`. Il n'accepte les envois que lorsque deux paramètres sont **validés** par les instances : le texte de consentement (protection des données, loi n° 2013-450 : formulation à faire valider, non inventée) et l'ouverture des adhésions en ligne. Sinon la page affiche la liste des pièces et « le formulaire en ligne ouvrira prochainement », ainsi que le contact s'il existe.
6. **Anti-spam** : champ piège, limite par adresse IP, taille et type des pièces contrôlés, aucun CAPTCHA tiers.
7. **Menu principal** : 7 entrées au plus (La Mutuelle, Adhésion, Actions sociales, Emploi, Formation, Actualités, Contact) ; Documents et Partenaires en pied de page ; piloté par le menu du CMS.
8. **« Préparer mon dossier »** : la chose interactive de la direction « La page est l'objet » : le visiteur coche sa situation et obtient la liste des pièces de l'art. 6 ; sans JavaScript, la liste complète reste affichée ; aucune donnée n'est envoyée.
9. **Workflow éditorial** : brouillon → en relecture → publié (ou programmé) → archivé ; le rédacteur (`cms.pages.manage`) ne peut que soumettre ; `cms.publish` publie ; la publication programmée est faite par le cron.
10. **Témoignages** désactivés par défaut, consentement écrit obligatoire ; **coordonnées et réseaux** sont des réglages (`settings`, groupe `site`) vides par défaut.
11. **Visiteur sans JavaScript** : le contenu et l'adhésion fonctionnent (formulaire d'étapes en pages successives côté serveur) ; JavaScript n'ajoute que le confort.

## Découpage

- **L3 — CMS** (plan `2026-11-16-l3-cms.md`) : tables, workflow, médiathèque, éditeur, pages à blocs, menus, articles, événements/documents/galeries/bannières/témoignages, réglages, boîte des formulaires reçus.
- **L4 — Site public et adhésion en ligne** (plan `2026-11-16-l4-site-public.md`) : rendu public des contenus, accueil et 18 pages, adhésion en ligne, formulaires contact/partenariat, SEO, performance.

## Reste au constructeur

Détails visuels fins (conformes à `docs/conception/05-design-system.md` §7 et aux règles Taste), noms de classes CSS, découpage des gabarits, vocabulaire exact des libellés d'interface non fixés ci-dessus.
