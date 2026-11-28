-- Filet de base de données : au plus un compte « solidarity_fund » actif (AccountService le contrôle déjà sous verrou nommé).
-- Colonne générée STORED + UNIQUE : les NULL (tout autre compte, ou fonds inactif) peuvent se répéter. Syntaxe valable sur MariaDB >= 10.2.7 et MySQL >= 8.0.16.
-- Si la base contient déjà deux fonds actifs, la création de l'index échoue (« Duplicate entry '1' for key 'uq_fa_one_active_fund' ») :
-- désactiver alors les fonds en trop (écran des comptes) avant de relancer bin/migrate.php.
ALTER TABLE financial_accounts
    ADD COLUMN solidarity_active TINYINT GENERATED ALWAYS AS (IF(type = 'solidarity_fund' AND is_active = 1, 1, NULL)) STORED,
    ADD UNIQUE KEY uq_fa_one_active_fund (solidarity_active);
