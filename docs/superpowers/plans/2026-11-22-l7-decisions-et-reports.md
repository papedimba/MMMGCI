# Lot L7 (demandes sociales) — décisions et points reportés

Commits a9110f2 (plan) .. c62db9a (tâche 7), puis la documentation (tâche 8). Sept tâches de code, chacune relue ; quatre ont eu un tour de correction (tâches 2, 3, 5, 6). Dernière suite complète : 1071 tests, 13 849 assertions, verte (tâche 7). Plan : `2026-11-22-l7-demandes-sociales.md`. Le contrôle visuel Playwright (1440, 768, 390 px) a été fait pour les tâches 6 et 7 ; il n'a **pas** été fait pour la tâche 2 (types et réglages) ; 360 px n'a pas été mesuré pour la tâche 2.

## Rulings pris pendant l'exécution (progress.md)
1. **Plan approuvé implicitement** par la demande « Faire l15 et l7 » : exécution sans validation séparée du plan. Coût : reprise du plan si l'utilisateur désapprouve un choix de conception.
2. **Le nom du bénéficiaire d'un décaissement lié reste modifiable** (l'adhérent et le montant sont figés) : le brief permet de corriger le nom pour un ayant droit. Coût : un payeur pourrait voir un nom différent de celui du membre ; la double approbation du circuit L6 relit toujours le décaissement.
3. **Migration 0027 ajoutée** (le plan ne prévoyait que 0025 et 0026) : 0025 et 0026 sont déjà appliquées sur des bases de développement et le `Migrator` refuse une migration modifiée après application. Coût : une migration de plus. Mineur noté : l'en-tête de 0027 devait citer l'erreur MariaDB 1834 ; non corrigé (voir plus bas).
4. Reports à la tâche 6 (tous traités) : notifier branché ; routes d'écriture filtrées par permission et `lockForWrite` exigeant `social.view` ; liste des pièces seulement à l'ouverture de l'onglet ; `context()` après le contrôle de périmètre ; longueurs maximales affichées.

## Décisions de conception (à confirmer par les instances)
1. **Aucune règle inventée** : plafond, carence, organe, sortes d'aide autorisées, critères, délai, conservation et règle d'éligibilité sont des paramètres « non configuré ». Plafond, sortes d'aide et organe ne s'imposent que si `params_decision_ref` du type est renseigné. Aucun refus n'est fondé sur le statut du membre, les impayés ou la carence, même validés ; le contexte d'examen est seulement affiché.
2. **Jamais de paiement direct** : la décision ne crée qu'un brouillon par `DisbursementService::create`. Aucun rôle ne reçoit `disbursement.approve` ni `disbursement.pay`.
3. **Ordre des verrous** : `social_requests` → `social_decisions` → ce que prend `DisbursementService::create` (compte, séquences) → `audit_chain`. Statut et événement sont écrits avant l'appel qui audite ; `Audit::log` est le dernier appel de chaque transaction.
4. **Filet « un décaissement vivant par décision »** : contrôle sous verrou de la décision **et** index unique sur une colonne générée (0027), parce qu'une lecture simple sous verrou peut être périmée si `create` est appelé directement. Le contrôleur L6 ignore `social_decision_id` forgé.
5. **Compte désactivé = hors périmètre** : `SocialAccess::visible` traite tout compte inactif comme absent (404), localement, sans toucher `MemberScope` (code L5 partagé).
6. **Affecté dans le périmètre** : l'affecté doit être actif, détenir `social.manage` et pouvoir accéder à l'adhérent (art. 8). Réaffecter à la même personne est refusé.
7. **`social.create` seul** ne dépose une pièce que sur une demande `recue` (lecture de « dépôt à l'enregistrement »).
8. **Aucun texte libre dans l'audit** (description, motif, message, note, avis, libellés, organe, note de clôture). L'audit ne porte que des identifiants, statuts, drapeaux, montants et la référence `AS-`. Exception assumée : `decision_ref`, saisi librement (60 caractères), figure dans l'audit et dans l'objet du décaissement.
9. **Lecture médicale** : une ligne `donnees_medicales` à chaque clic d'affichage, à chaque téléchargement et chaque fois qu'un libellé de pièce médicale est révélé ; la lecture journalise même s'il n'y a pas encore de donnée.
10. **Un justificatif de décaissement ne peut pas être une pièce sociale** (`assertOwnFile`).
11. **`confirm-dialog` gagne deux propriétés facultatives** (`extra_html`, `form`) et `app.js` valide le formulaire avant d'ouvrir la boîte et verrouille aussi les boutons reliés par l'attribut `form`. Les autres appelants (L3 à L6) sont inchangés.
12. **`social.reference_format`** n'est qu'un bandeau « à valider » : la référence reste `Sequence::next('social_request', 'AS', 4)`. Le brancher sur le réglage validé n'est pas fait.
13. **CHECK en base** sur canal (`guichet`, `delegue`, `telephone`, `courrier`, `email`), priorité, statut, avis, sorte d'aide et nature de pièce : listes techniques, évolution par migration.
14. **Recherche** : filtre statut, type, priorité, responsable (moi ou non affecté), zone (seulement avec `members.scope.all`), période, texte ; tri urgente d'abord ; 50 par page ; aucune colonne médicale ni description. **Export** : 12 colonnes fixes, neutralisation des formules, audit `social.exported` (texte cherché enregistré seulement comme `true`).

## Questions ouvertes pour les instances
- Séparer l'instructeur et le décideur : le cahier des charges ne le prévoit pas ; rien ne l'impose aujourd'hui (une même personne peut détenir `social.manage` et `social.decide`).
- Renvoyer une demande soumise à décision vers l'instruction (absent de A5.2) ; désistement de l'adhérent ; correction d'une décision immuable.
- Format de référence (V11), délais de traitement, durée de conservation, règle d'éligibilité, contenu des paramètres de chaque type.
- Qui décide : `pca` n'a `social.decide` qu'à titre de suggestion (0005).
- Désigner les approbateurs et le payeur (D3) : sans eux, une aide financière accordée ne peut pas être payée.
- C38 : dépôt en ligne de demandes par l'adhérent, oui ou non.

## Contrat de la vue adhérent (consommé par L15) : `SocialMemberView`
Seule source autorisée pour l'Espace adhérent ; **ne pas réutiliser** `_timeline`, les steps d'avancement ni les vues d'administration (ils montrent les auteurs). `requestsOf($memberId)` renvoie, par demande, une liste blanche épinglée par `SocialMemberViewTest` :
- toujours : `reference`, `type`, `received_at`, `status` (libellé simplifié) ;
- `documents_requested` : seulement quand la demande est `en_attente_pieces` ;
- `decision` (« Accordée » ou « Refusée »), `message` (message à l'adhérent) : une fois la demande décidée ;
- `amount_granted` (entier, centimes) : seulement pour une aide financière accordée.

Table des statuts : `recue` → Reçue ; `en_instruction` et `soumise_decision` → En cours d'examen ; `en_attente_pieces` → Pièces demandées ; `accordee`, `refusee` et `en_paiement` → Décision rendue ; `cloturee` → Clôturée. Un statut inconnu lève une exception dont le message ne contient pas le code interne. **Jamais** : notes, avis, noms des instructeurs, décideurs et approbateurs, motif interne, organe, référence de décision, circuit de décaissement, descriptions, données médicales, données d'un autre membre. Le canal `espace_adhérent` et le dépôt en ligne (C38) exigeraient une migration (CHECK du canal) et un contrôle du périmètre « l'adhérent est le membre connecté ».

## Points reportés (voir aussi TASKS.md)
- **L10** : clés étrangères `governing_body_id` et `meeting_id` (organe et réunion de la décision) ; l'organe est aujourd'hui un texte libre (160 caractères).
- **L15** : « Mes demandes » (T15.7), nouvelle demande en ligne (T15.8, C38), dépôt de pièces par l'adhérent ; notification de la décision à l'adhérent.
- **L11** : indicateurs « actions sociales » (art. 19 et 20).
- **L13** : purge ou anonymisation après `social.retention_months` ; installation, sauvegarde de `storage/uploads/private/`.
- **Purge des fichiers orphelins** (report de L6) : elle doit **exclure les fichiers référencés par `social_request_documents`** (et ceux de `apply_draft_files`) ; ces fichiers ont un `uploaded_by` et ne sont pas orphelins.

## Mineurs parqués des revues (honnêtement : non faits)
**Tâche 1** : les CHECK figent des listes « à valider » (évolution par ALTER, à garder en tête pour L15) ; pas de CHECK sur `action` et les états des événements ; pas de CHECK « refus ⇒ sorte et montant NULL » (porté par le service) ; pas de test explicite de la mise à niveau depuis 0024 (seule la base neuve est testée) ; écart d'apostrophe droite dans les `action_text`.

**Tâche 2** : une seule référence de décision couvre les 5 paramètres d'un type (un nouveau PV qui ne change que le plafond ré-estampille les autres) ; le test de désactivation ne prouve pas « conservé sur les anciennes demandes » ; une redirection vers `/types` exige `social.view` (un porteur de `settings.validate` seul tombe sur un 403) ; XSS testé seulement sur `name` et `action_text` ; aucun test de rejet de la carence (« abc », négatif) ni du plafond 0 ; **contrôle visuel de la tâche 2 non fait** ; identifiant `decision_ref` dupliqué sur la page des paramètres d'un type (`label for` ambigu) ; sans JavaScript, la désactivation d'un type actif répond « référence obligatoire » sans guider ; après une erreur de validation d'un réglage, l'alerte est en tête de page ; revalider un réglage déjà validé est permis (audit `settings` avec id NULL).

**Tâche 3** : M1 `decision_ref` libre (aide ajoutée à l'écran en tâche 6) ; M2 plafond, sortes d'aide et organe lus sans verrou pendant `decide` (une validation simultanée peut laisser passer une décision sous l'ancien régime, sans conséquence financière directe) ; M5 refus de réaffecter à la même personne (garde-fou) ; M6 `test_audit_is_the_last_lock` compte les accolades sans ignorer les chaînes (fragile si un message en contient) ; `visible()` fait une requête de plus par appel. (M3 longueurs et M4 `context()` : traités en tâche 6.)

**Tâche 4** : M1 `assign` met l'e-mail en file même si la transaction appartient à l'appelant qui annule ensuite (aucun appelant ne le fait) ; M3 pas de reprise sur interblocage dans les services de pièces, notes et santé (1213 ou 1205 remontent en erreur SQL brute, risque faible) ; M4 `Uploads::store` (ré-encodage d'image) tourne sous le verrou de la demande ; M6 un enregistreur peut déposer une pièce de nature `decision` sur une demande `recue` ; M7 `Gate::forget` à chaque appel (rechargements de permissions, coûteux mais correct). (M2 et M5 : traités en tâche 6.)

**Tâche 5** : `create` ne contrôle pas le statut de la demande sociale (un appel direct hors `prepare` laisse « accordée » avec un brouillon vivant ; aucun appelant, aucun risque d'argent) ; `decision_ref` libre recopié dans l'objet ; l'objet reste modifiable par `revise` ; 0027 : l'en-tête annonce « Duplicate entry » alors que MariaDB 10.11 répond 1834 (documenté dans le README, fichier non modifié car appliqué) ; contrôle visuel de la tâche 5 non fait (une ligne et un fragment de texte seulement).

**Tâche 6** : M1 fenêtre de concurrence (rôle retiré entre le chargement et le verrou : « Action refusée », pas un oracle) ; M3 le test d'échappement ne couvre pas les champs de la décision, les libellés de pièce, la note de clôture ni les pièces demandées (échappés, vérifié par sonde) ; M5 un `social.view` seul voit les noms des auteurs dans la chronologie (conforme au brief, mais à ne pas reprendre pour L15) ; M6 `test_buttons_match_allowed_actions` ne rend jamais les boutons `prepare` et `paymentFailed` (couverts par un autre test) ; M7 `confirm-dialog` en mode `form` non couvert par UiTest ; M8 le statut de l'adhérent s'affiche en code brut (`actif`) dans l'onglet Examen ; M9 les liens d'onglet font environ 41 px (composant existant) ; M10 `assignees()` interroge le gate pour chaque compte actif (`shortcut:` commenté) ; N1 le test d'`app.js` cherche des motifs dans le source, pas le comportement.

**Tâche 7** : M1 la branche « compte désactivé » de `SocialSearch` n'a pas de test direct ; M2 la neutralisation des formules n'est testée que sur le nom de zone ; M3 l'export n'exige que `social.export` (pas aussi `social.view`) ; M4 le lien d'export recopie `q` dans l'URL (journaux du serveur web) ; M5 la recherche de référence fonctionne par préfixe ; M6 `"criteria":[]` au lieu de `{}` dans l'audit d'export ; M7 le libellé du type (par exemple « Maladie ») apparaît dans la liste, l'onglet et l'export : à garder en tête pour la validation V11 et la confidentialité.

**Transversal** : liens de tableau et fil d'Ariane sous 44 px au pointeur fin (conception existante, 44 px au pointeur tactile) ; MySQL 8.0.16 non essayé (MariaDB 10.11 seulement) ; plusieurs tests de concurrence reposent sur des processus enfants et peuvent, très rarement, être instables (comme en L6).
