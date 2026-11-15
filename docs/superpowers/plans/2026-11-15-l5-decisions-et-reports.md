# Lot L5 — décisions et points reportés

Branche `ccr-5416d63c-xuo00t`, commits e82c5bd..(dernier commit de la tâche 9). Suite : 496 tests, `bin/check-contrast.php` : toutes les paires conformes.

## Décisions prises pendant l'exécution
1. Numéro de membre : format technique `MMMGCI-AAAA-NNNNN` par défaut, affiché « à valider » ; si les instances en choisissent un autre, la renumérotation est à prévoir (non incluse).
2. Statuts `actif/suspendu/radie/decede/ancien_marin` : liste « proposée » ; aucune règle de transition ni conséquence automatique inventée. Changer un statut exige `members.status`, un motif et une référence de décision, et est historisé.
3. Périmètre des délégués : zone de rattachement de l'utilisateur (`users.zone_id`), sans sous-zones ; sans `members.scope.all` ni zone, personne n'est visible. Hors périmètre ou inconnu : 404 (pas de fuite d'existence).
4. Carte : jeton de vérification aléatoire de 64 caractères hexadécimaux, jamais écrit dans l'audit ; vérification publique neutre (« Carte non valide » pour inconnu, révoqué, expiré ou radié). Validité et conditions de délivrance non configurées : carte sans expiration, délivrance manuelle ; une condition validée mais vide est traitée comme non configurée.
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
- Exports en mémoire : à reprendre en flux si les volumes dépassent ~100 000 lignes.
- Impression physique de la carte CR80 non vérifiée ; la photo et le QR restent des emplacements pointillés tant que le gabarit n'est pas validé.
- Erreurs de validation du service d'adhérent : un message unique en alerte, pas par champ.
- Base de développement : la chaîne d'audit y est rompue depuis des lignes supprimées à la main pendant les tâches précédentes (la vérification signale l'id 79) ; recréer la base de dev (`bin/dev-db`) plutôt que de réécrire l'historique. Les tests utilisent `mmmgci_test`, non concernée.
- Détecteur Impeccable : une alerte résiduelle, le trait de liaison des étapes (`.step::before`) pris pour un « side-tab » ; c'est un connecteur de chronologie, ignoré avec raison dans `.impeccable/config.json`.
