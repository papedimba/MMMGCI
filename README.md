# MMMGCI — Plateforme numérique

Plateforme institutionnelle et de gestion de la **Mutuelle des Marins Marchands et Gens de Mer de Côte d'Ivoire** — « Un nouveau jour se lève ».

État : **phase 1 (conception) — en attente de validation**. Aucun code applicatif n'est encore écrit.

- Conception : [`docs/conception/`](docs/conception/README.md)
- Contexte produit : [`PRODUCT.md`](PRODUCT.md)
- Suivi des tâches : [`TASKS.md`](TASKS.md)

Cible technique : PHP 8.2+, MySQL/MariaDB, hébergement mutualisé cPanel.

## E-mail (vérification manuelle SMTP)

`MAIL_DRIVER=mail` utilise `mail()` ; `MAIL_DRIVER=smtp` utilise `SMTP_HOST/PORT/USER/PASS` (STARTTLS auto sur le port 587).
Le transport SMTP est testé automatiquement contre un faux serveur local (`tests/Support/fake_smtp_server.php`) ; STARTTLS n'est vérifié que manuellement :
Utiliser par ex. `python3 -m aiosmtpd -n -l 127.0.0.1:1025` (ou Mailpit), régler `SMTP_HOST=127.0.0.1 SMTP_PORT=1025`, puis `Mailer::fromConfig($config)->send('moi@exemple.ci','Test','Bonjour')`.
