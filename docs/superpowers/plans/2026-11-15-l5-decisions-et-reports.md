# Lot L5 — décisions et points reportés

Branche `ccr-5416d63c-xuo00t`, commits e82c5bd..(dernier commit de la tâche 9). Suite : 500 tests, `bin/check-contrast.php` : toutes les paires conformes.

## Décisions prises pendant l'exécution
1. Numéro de membre : format technique `MMMGCI-AAAA-NNNNN` par défaut, signalé « à valider » par un bandeau sur les formulaires de création (adhérent et demande) tant que le paramètre n'est pas validé ; si les instances en choisissent un autre, la renumérotation est à prévoir (non incluse).
2. Statuts `actif/suspendu/radie/decede/ancien_marin` : liste « proposée » ; le statut initial `actif` est le défaut du schéma et du service, pas une règle du cahier des charges ; aucune règle de transition ni conséquence automatique inventée. Changer un statut exige `members.status`, un motif et une référence de décision, et est historisé.
3. Périmètre des délégués : zone de rattachement de l'utilisateur (`users.zone_id`), sans sous-zones ; sans `members.scope.all` ni zone, personne n'est visible. Hors périmètre ou inconnu : 404 (pas de fuite d'existence).
4. Carte : jeton de vérification aléatoire de 64 caractères hexadécimaux, jamais écrit dans l'audit ; vérification publique neutre (« Carte non valide » pour inconnu, révoqué, expiré, radié ou décédé). Le traitement de radié et décédé comme invalides vient du plan L5 §147, pas du cahier des charges. Validité et conditions de délivrance non configurées : carte sans expiration, délivrance manuelle ; une condition validée mais vide est traitée comme non configurée.
5. « Dossier complet » non défini : la liste des pièces est une aide, pas un blocage de la validation.
6. Validation d'une demande : transaction unique (un membre, un numéro), homonymes à confirmer explicitement ; le rejet exige un motif **et** la case « Je confirme le rejet » (contrôlée côté serveur).
7. Exports : colonnes fixes (jamais de pièce, photo ni numéro d'identité), neutralisation des formules (`=`, `+`, `-`, `@`) en CSV et XLSX, audit des seuls critères appliqués (le texte de recherche n'est pas conservé), lecture SQL par lots de 500.
8. Liste imprimable : résumé des filtres appliqués (échappé) à la place de « liste filtrée ».
9. Compatibilité base : le filtre de zone des demandes utilise `JSON_UNQUOTE(JSON_EXTRACT(...))` (MariaDB ≥ 10.2.3, MySQL ≥ 5.7) et non `JSON_VALUE` (MySQL ≥ 8.0.21) ; README aligné.
10. Repères de `validation-steps` : fond identique pour tous, état porté par l'icône et la couleur (règle `icon-chips` de Taste).

## Points reportés (voir aussi TASKS.md)
- Instruction des demandes en ligne : lot L4 ; Espace adhérent : lot L15.
- Anonymisation et purge après `members.retention_months` : colonne prévue, rien d'exécuté tant que la durée n'est pas validée.
- Droits de modification des délégués (le rôle `delegue` n'a ni `members.update` ni `members.documents.view`) : à décider par les instances.
- Suppression de pièce ou de photo, purge des fichiers orphelins (l'ancienne photo est conservée au remplacement).
- Sous-zones dans le périmètre (`zones.parent_id`).
- Exports **et liste imprimable** en mémoire : à reprendre en flux si les volumes dépassent ~100 000 lignes.
- La carte s'imprime sur A4 ; le format physique CR80 (page nommée `cr80`, inutilisée) n'est pas vérifié. La photo et le QR sont dessinés sur la carte.
- Erreurs de validation du service d'adhérent : un message unique en alerte, pas par champ.
- Base de développement : la chaîne d'audit y est rompue depuis des lignes supprimées à la main pendant les tâches précédentes (la vérification signale l'id 79) ; recréer la base de dev (`bin/dev-db`) plutôt que de réécrire l'historique. Les tests utilisent `mmmgci_test`, non concernée.
- Détecteur Impeccable : une alerte résiduelle, le trait de liaison des étapes (`.step::before`) pris pour un « side-tab » ; c'est un connecteur de chronologie, ignoré avec raison dans `.impeccable/config.json`.

## Ajouts de la passe finale
- Machine d'états des demandes plus souple que le diagramme A5.1 : `recue` peut passer à validée, rejetée ou compléments demandés, et `complements_demandes` à rejetée (le plan §113 autorise la validation dès la réception).
- Ligne initiale de `member_status_history` écrite par `MemberService::create` dans les deux chemins (création directe : « Création de la fiche » ; validation d'une demande : « Adhésion validée » avec la référence de la demande), sans doublon.
- Dernière note « compléments demandés » conservée dans `membership_applications.complements_note` (migration 0009) et affichée sur la page de la demande ; l'historique complet reste dans l'audit.
- `APP_URL` obligatoire en production pour le QR de la carte (impression refusée sinon).
- **Délégués** : `delegue` et `point_focal` peuvent créer directement un adhérent actif et numéroté (`members.create`) sans circuit de validation ; décision des instances à obtenir, rôles inchangés.
- Une demande validée reste visible selon la zone saisie dans la demande : si l'adhérent change de zone, les utilisateurs de l'ancienne zone ayant `members.documents.view` voient encore les fichiers de la demande ; à trancher par les instances.
- `admin_general`, `secretaire_general` et `super_admin` détiennent `members.validate` sans `members.documents.view` : ils valident sans pouvoir consulter les pièces ; déposer une pièce n'exige que `members.documents.view` ; question de matrice pour les instances.
- Vérification publique : limitation par `REMOTE_ADDR` (30 requêtes par minute) ; derrière un proxy ou un CDN, la limite est partagée par tous les visiteurs.
- Minimum de base : MariaDB 10.2.7 (colonnes JSON depuis 0001), MySQL 8.0.16.
- Jeton `--muted-bg` (#EDF0F5) à la place de la valeur recopiée ; paires des repères d'étapes déclarées dans `ContrastChecker::PAIRS`.
