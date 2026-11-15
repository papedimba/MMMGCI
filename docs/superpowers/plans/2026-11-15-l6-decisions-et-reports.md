# Lot L6 — décisions et points reportés

Commits 6d775bb..(dernier commit de la tâche 10). Suite : 637 tests, `bin/check-contrast.php` : toutes les paires conformes.

## Décisions prises pendant l'exécution (à confirmer par les instances)
1. **Politique de solde insuffisant** : non configurée, défaut technique « bloquer » (le plus sûr). Coût si les instances veulent seulement alerter : valider `finance.insufficient_balance_policy`.
2. **Payeur différent de l'initiateur** : appliqué par défaut (A6), paramétrable (`finance.payer_may_be_initiator`). Coût : un trésorier unique qui initie ne peut pas payer.
3. **Population d'un appel en lot** : non définie par le cahier des charges ; par défaut les adhérents au statut `actif`, avec bandeau « à valider ».
4. **Dépenses hors décaissement** : interdites. Toute sortie passe par le circuit ; le grand livre refuse une dépense sans source `disbursement`, aucune route ne la saisit, et la contre-passation d'une écriture issue d'un circuit est refusée depuis l'écran du grand livre.
5. **Aucune permission d'approuver ni de payer par défaut** : le module est inopérant tant que deux approbateurs distincts ne sont pas désignés ; un payeur tiers est requis.
6. **Aucun paiement sans appel** (pas d'avances : non défini). Génération automatique des appels désactivée tant que `contributions.auto_generate` n'est pas validé ; sans règle « à jour » validée, le mot n'apparaît jamais.
7. **Révisions** : modification substantielle (montant, bénéficiaire, compte, objet) = nouvelle révision, approbations caduques mais conservées ; `category_id` et la note interne ne sont pas substantiels.
8. **Fonds de solidarité** : lecture seule du grand livre ; un seul fonds actif (verrou nommé préfixé par la base + filet UNIQUE de la migration 0015) ; contre-passations jamais comptées comme décaissements.
9. **Contrôle** : lecture seule (routes GET uniquement) ; le contrôle « payé avec moins d'approbateurs que requis » applique la règle d'approbation **actuelle** (indiqué à l'écran).
10. **Modes de paiement** : une seule source (`PaymentService::METHODS`), partagée par les cotisations et les décaissements.
11. **Interblocage** : `DisbursementService` rejoue une fois l'action victime d'un 1213 quand il possède la transaction ; `DisbursementBusy` seulement dans ce cas. Dans la transaction d'un appelant, l'exception SQL brute est relancée (jamais d'état partiel validé).

## Correctifs parqués traités par la tâche 10
(a) pièces orphelines : l'action est vérifiée (permission, état, révision et montant vus) avant tout téléversement, testé ; (b) élisions « d'approuver », « d'annuler » (table par verbe), testé ; (c) pièce du paiement marquée obligatoire (attribut, astérisque, mention pour lecteurs d'écran) ; (d) source unique des modes de paiement ; (e) 1213/1205 bruts hors transaction propre, testé ; (f) migration 0015 (colonne générée STORED + UNIQUE, syntaxe MariaDB ≥ 10.2.7 et MySQL ≥ 8.0.16, échec explicite si deux fonds actifs existent déjà), verrou préfixé par `DATABASE()`, avertissement sur l'écran si plusieurs fonds actifs, commentaires corrigés, testé ; (g) la page GET de contre-passation refuse en 422 comme le POST, testé ; (h) mention « règle ACTUELLE » à l'écran de contrôle, testée.

## Points reportés (voir aussi TASKS.md)
- Budgets et engagements (T6.9) ; rapports financiers (lot L11).
- Exports en mémoire : mesure sur 50 000 écritures, CSV 7,2 s et 46 Mo, XLSX 9,0 s et 116 Mo ; à passer en flux au-delà (au-delà de ~100 000 lignes sur un hébergement à `memory_limit` 128 Mo, l'export échouerait).
- La contre-passation d'une ressource ou l'annulation d'un paiement peut rendre un solde négatif (aucun contrôle de solde).
- Approbation par un utilisateur désactivé ensuite : elle compte toujours (à trancher). Pas de rejet depuis `approuve_2` (à trancher).
- Filtre « À approuver par moi » : calcul en PHP sans pagination (≈ 8 requêtes par demande en cours).
- Fichier orphelin résiduel si une course change l'état entre le contrôle du contrôleur et le service ; pas de purge.
- Liste d'exclusion des fichiers d'un décaissement incomplète (photo d'adhérent, ayants droit, reçus) : risque faible, l'acteur ne peut citer que ses propres téléversements.
- Écritures d'origine multiples lignes et `opening_balance` non couverts par l'expression régulière du test d'immutabilité ; pas de test de concurrence à deux connexions pour la double contre-passation (filet UNIQUE testé seul).
- Idempotence du paiement depuis l'écran : elle repose sur le contrôleur (`paidWithKey`), chaque envoi téléversant un nouveau fichier.
- Un décaissement modifié (`update`) renvoie tous les champs : un compte ou une catégorie désactivé entre-temps empêche d'ajouter une pièce.
- Chaîne d'audit de la base de développement rompue (lignes 78, 81, 83 supprimées à la main) : ignorée, non réparée ; recréer la base de dev si besoin.

## Limites des preuves
- Les preuves de concurrence utilisent un verrou tenu par une seconde connexion PDO ou des processus enfants ; elles montrent l'absence de double paiement et de double approbation dans ces entrelacements précis, pas dans tous. Le témoin d'interblocage (`DisbursementConcurrencyTest`) dépend de la disposition des intervalles d'index : s'il n'est pas vu, le test est marqué « skipped » (visible), jamais vert par défaut.
- Le test de bout en bout (`FinanceEndToEndTest`) passe par les écrans et les rôles réels (trésorier général, contrôleur) et par deux rôles de test portant `disbursement.approve` et `disbursement.pay`. Les tentatives de contournement testées sont celles listées ; ce n'est pas un fuzz des routes.
- L'unicité du fonds actif en base (index UNIQUE) est testée par insertion directe ; l'avertissement « plusieurs fonds actifs » n'est plus atteignable que sur des données antérieures à la migration (non testé, faute de pouvoir créer l'état).
- Les mesures de performance sont des mesures ponctuelles sur la machine de développement, pas une garantie de charge.
