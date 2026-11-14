<?php
declare(strict_types=1);

namespace App\Modules\Users;

use App\Core\Database;
use App\Modules\Auth\PasswordPolicy;
use DomainException;

/** Logique de bin/create-admin.php : premier super administrateur, créé via UserService (acteur null = système). */
final class FirstAdmin
{
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';

    public function __construct(private UserService $users, private Database $db)
    {
    }

    /** @param list<string> $args email, nom complet @return array{int, string} code de sortie, message (le mot de passe n'y figure qu'en cas de succès) */
    public function run(array $args): array
    {
        if (count($args) !== 2 || trim($args[1]) === '') {
            return [1, 'Usage : php bin/create-admin.php <email> "<Nom complet>" — indiquez l\'adresse e-mail puis le nom complet, entre guillemets.'];
        }
        if ($this->users->hasActiveAdmin()) {
            return [1, 'Un administrateur existe déjà : utilisez l\'écran Utilisateurs pour créer d\'autres comptes.'];
        }
        [$email, $name] = [trim($args[0]), trim($args[1])];
        $password = $this->generatePassword($email, $name);
        try {
            $this->db->atomic(function () use ($email, $name, $password): void {
                $id = $this->users->create(['name' => $name, 'email' => $email, 'roles' => ['super_admin', 'admin_general']], null);
                $this->db->update('users', ['password' => PasswordPolicy::hash($password)], ['id' => $id]); // jamais journalisé
            });
        } catch (DomainException $e) {
            return [1, $e->getMessage()];
        }
        return [0, "Administrateur créé : $email\nMot de passe (affiché une seule fois, notez-le maintenant) : $password\n"
            . 'Changez-le dès la première connexion : /admin/mon-compte/mot-de-passe'];
    }

    private function generatePassword(string $email, string $name): string
    {
        do {
            $p = '';
            for ($i = 0; $i < 20; $i++) {
                $p .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            }
        } while (PasswordPolicy::check($p, $email, $name) !== null);
        return $p;
    }
}
