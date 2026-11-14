<?php
declare(strict_types=1);

namespace Tests\Support;

use App\Core\App;
use App\Core\Config;
use App\Core\Env;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Mail\ArrayTransport;
use App\Core\Mailer;
use App\Modules\Auth\PasswordPolicy;
use Tests\DatabaseTestCase;

/** Application complète sur la base de test, sans session HTTP : le store de session est celui de l'app. */
abstract class AdminTestCase extends DatabaseTestCase
{
    /** @var array<string,mixed> services exposés par app/kernel.php */
    protected array $services = [];
    private ?App $app = null;
    /** e-mails envoyés par l'application (corps encodé en base64) */
    protected ArrayTransport $mail;
    /** @var array<string,mixed> surcharges du noyau (ex. 'defer') à poser avant le premier app() */
    protected array $overrides = [];
    /** @var array<string,string> valeurs de .env remplacées pour ce test, à poser avant le premier app() */
    protected array $env = [];

    protected function app(): App
    {
        if ($this->app === null) {
            $this->mail = new ArrayTransport();
            $mailer = new Mailer($this->mail, 'no-reply@exemple.ci');
            $config = new Config($this->env + Env::load(dirname(__DIR__, 2) . '/.env'));
            $overrides = $this->overrides + ['db' => $this->db, 'mailer' => $mailer, 'defer' => static fn (callable $job) => $job()]; // travail différé exécuté tout de suite
            $this->app = (require dirname(__DIR__, 2) . '/app/kernel.php')($config, false, $overrides, $this->services);
        }
        return $this->app;
    }

    /** @param list<string> $roleNames */
    protected function createUser(string $email, array $roleNames, string $password = 'phrase secrète longue 2026', bool $active = true): int
    {
        $id = $this->db->insert('users', [
            'name' => 'Utilisateur ' . explode('@', $email)[0],
            'email' => mb_strtolower(trim($email)),
            'password' => PasswordPolicy::hash($password),
            'is_active' => $active ? 1 : 0,
        ]);
        foreach ($roleNames as $role) {
            $this->db->query('INSERT INTO user_roles (user_id, role_id) SELECT ?, id FROM roles WHERE name = ?', [$id, $role]);
        }
        return $id;
    }

    protected function actingAs(int $userId): void
    {
        $this->app();
        $this->services['session']->set('auth.user_id', $userId);
        $this->services['session']->set('auth.session_version', (int) $this->db->fetchValue('SELECT session_version FROM users WHERE id = ?', [$userId]));
    }

    /** @param array<string,mixed> $post */
    protected function request(string $method, string $path, array $post = []): Response
    {
        $app = $this->app();
        [$p, $qs] = explode('?', $path, 2) + [1 => ''];
        parse_str($qs, $query);
        if (strtoupper($method) !== 'GET') {
            $post += ['_csrf' => $this->services['csrf']->token()];
        }
        return $app->handle(new Request($method, $p, $query, $post, [], ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_USER_AGENT' => 'PHPUnit', 'QUERY_STRING' => $qs]));
    }
}
