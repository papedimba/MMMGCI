<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Audit;
use App\Core\Gate;
use App\Core\Http\PermissionGuard;
use App\Core\Http\RequireAuth;
use App\Core\Http\Response;
use App\Modules\Espace\ActivationCode;
use App\Modules\Espace\EspaceSettingsService;
use DomainException;
use Tests\Support\AdminTestCase;
use Tests\Support\EspaceFixtures;

/** Écrans du personnel de l'Espace adhérent : onglet de la fiche, lettre d'activation, liste des accès, paramètres (L15 tâche 4). */
final class EspaceAdminScreensTest extends AdminTestCase
{
    use EspaceFixtures;

    private const CODE = '/\b[2-9A-HJKMNP-Z]{4}-[2-9A-HJKMNP-Z]{4}-[2-9A-HJKMNP-Z]{4}\b/';
    private const ROUTES = [
        'POST /admin/adherents/{id:\d+}/espace' => ['espace.accounts.manage', []],
        'POST /admin/adherents/{id:\d+}/espace/code' => ['espace.accounts.manage', []],
        'POST /admin/adherents/{id:\d+}/espace/reinitialiser' => ['espace.accounts.reset', []],
        'POST /admin/adherents/{id:\d+}/espace/desactiver' => ['espace.accounts.manage', []],
        'POST /admin/adherents/{id:\d+}/espace/reactiver' => ['espace.accounts.manage', []],
        'GET /admin/espace/comptes' => ['espace.accounts.manage', []],
        'GET /admin/espace/parametres' => ['espace.accounts.manage', ['settings.validate']],
        'POST /admin/espace/parametres/{key:[a-z0-9_]+}' => ['settings.validate', []],
    ];

    private int $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db->query('DELETE FROM login_attempts');
        $this->db->query("UPDATE settings SET status = 'non_configure', validated_by = NULL, validated_at = NULL, decision_ref = NULL WHERE `group` = 'espace'");
        $this->db->query("UPDATE settings SET `value` = ? WHERE `group` = 'espace' AND `key` = 'status_access'", ['{"actif":"complet","suspendu":"lecture","radie":"desactive","decede":"desactive"}']);
        $this->db->query("UPDATE settings SET `value` = '14' WHERE `group` = 'espace' AND `key` = 'activation_code_days'");
        $this->db->query("UPDATE settings SET `value` = NULL WHERE `group` = 'espace' AND `key` = 'depot_demande_en_ligne'");
        $this->manager = $this->createUser('gest-' . bin2hex(random_bytes(3)) . '@x.test', ['gest_adherents']);
    }

    private function tab(int $member): Response
    {
        return $this->request('GET', "/admin/adherents/$member?onglet=espace");
    }

    /** Jeton à usage unique du formulaire qui délivre un code (un seul par état de l'accès). */
    private function onceToken(int $member): string
    {
        $body = $this->tab($member)->body();
        $this->assertSame(1, preg_match('/name="_once" value="([a-f0-9]{64})"/', $body, $m), 'formulaire de délivrance attendu');
        return $m[1];
    }

    private function zone(): int
    {
        return $this->db->insert('zones', ['name' => 'Z ' . bin2hex(random_bytes(3)), 'type' => 'region']);
    }

    private function delegate(int $zone): int
    {
        $id = $this->createUser('del-' . bin2hex(random_bytes(3)) . '@x.test', ['delegue']);
        $this->db->query('UPDATE users SET zone_id = ? WHERE id = ?', [$zone, $id]);
        return $id;
    }

    private function codeRows(int $member): int
    {
        return (int) $this->db->fetchValue('SELECT COUNT(*) FROM member_activation_codes c JOIN member_accounts a ON a.id = c.member_account_id WHERE a.member_id = ?', [$member]);
    }

    public function test_routes_require_the_right_permission_each(): void
    {
        $this->app();
        $found = [];
        foreach ($this->services['router']->routes() as $r) {
            $key = "{$r['method']} {$r['pattern']}";
            if (!isset(self::ROUTES[$key])) {
                continue;
            }
            $guards = array_values(array_filter($r['middleware'], static fn ($m) => $m instanceof PermissionGuard));
            $this->assertCount(1, $guards, $key);
            $found[$key] = [$guards[0]->permission, $guards[0]->alternatives];
        }
        ksort($found);
        $expected = self::ROUTES;
        ksort($expected);
        $this->assertSame($expected, $found);

        // Délégué : ouvre l'accès mais ne réinitialise pas, ne valide aucun paramètre ; lecteur de la fiche seul : rien.
        $member = $this->memberForAccess();
        $this->actingAs($this->createUser('com-' . bin2hex(random_bytes(3)) . '@x.test', ['resp_commission']));
        foreach (['espace', 'espace/code', 'espace/reinitialiser', 'espace/desactiver', 'espace/reactiver'] as $p) {
            $this->assertSame(403, $this->request('POST', "/admin/adherents/$member/$p", ['remise' => 'lettre'])->status(), $p);
        }
        $this->assertSame(403, $this->request('GET', '/admin/espace/comptes')->status());
        $this->assertSame(403, $this->request('GET', '/admin/espace/parametres')->status());
        $this->assertSame(403, $this->request('POST', '/admin/espace/parametres/activation_code_days', ['value_activation_code_days' => '7', 'decision_ref_activation_code_days' => 'PV-1'])->status());
        $this->assertSame(0, $this->codeRows($member));

        $this->actingAs($this->manager);
        $this->assertSame(200, $this->request('GET', '/admin/espace/parametres')->status());
        $this->assertSame(403, $this->request('POST', '/admin/espace/parametres/activation_code_days', ['value_activation_code_days' => '7', 'decision_ref_activation_code_days' => 'PV-1'])->status());
        $this->assertFalse($this->services['gate']()->allows($this->manager, 'settings.validate'));
    }

    public function test_admin_route_sweep_still_green(): void
    {
        $this->app();
        $checked = 0;
        foreach ($this->services['router']->routes() as $r) {
            if (!isset(self::ROUTES["{$r['method']} {$r['pattern']}"])) {
                continue;
            }
            $this->assertInstanceOf(RequireAuth::class, $r['middleware'][0] instanceof \Closure ? ($r['middleware'][0])() : $r['middleware'][0], $r['pattern']);
            $path = preg_replace('/\{\w+(?::(?:[^{}]|\{\d+(?:,\d*)?\})+)?\}/', '1', $r['pattern']);
            $res = $this->request($r['method'], $path);
            $this->assertSame(302, $res->status(), "invité : {$r['method']} {$r['pattern']}");
            $this->assertStringStartsWith('/admin/connexion', $res->headers()['Location']);
            $checked++;
        }
        $this->assertSame(count(self::ROUTES), $checked);

        // Compte du personnel désactivé : aucune délivrance, renvoyé vers la connexion.
        $member = $this->memberForAccess();
        $inactive = $this->createUser('inactif-' . bin2hex(random_bytes(3)) . '@x.test', ['gest_adherents'], active: false);
        $this->actingAs($inactive);
        $res = $this->request('POST', "/admin/adherents/$member/espace", ['remise' => 'lettre']);
        $this->assertSame(302, $res->status());
        $this->assertStringStartsWith('/admin/connexion', $res->headers()['Location']);
        $this->assertSame(0, $this->codeRows($member));
    }

    public function test_tab_visible_only_with_espace_permissions(): void
    {
        $member = $this->memberForAccess();
        $this->actingAs($this->createUser('com-' . bin2hex(random_bytes(3)) . '@x.test', ['resp_commission']));
        $show = $this->request('GET', "/admin/adherents/$member");
        $this->assertSame(200, $show->status());
        $this->assertStringNotContainsString('onglet=espace', $show->body());
        $this->assertStringNotContainsString('Ouvrir l&#039;accès', $this->tab($member)->body(), 'onglet inconnu : identité affichée');

        $this->actingAs($this->manager);
        $this->assertStringContainsString('onglet=espace', $this->request('GET', "/admin/adherents/$member")->body());
        $body = $this->tab($member)->body();
        $this->assertStringContainsString('Aucun accès', $body);
        $this->assertStringContainsString('paramètre en attente de validation par les instances', $body);
        $this->assertStringNotContainsString('style=', $body);
        $this->assertDoesNotMatchRegularExpression('/<script(?![^>]*\bsrc=)/', $body);
    }

    public function test_letter_shows_code_once_and_reload_is_refused(): void
    {
        $member = $this->memberForAccess();
        $this->actingAs($this->manager);
        $token = $this->onceToken($member);
        $letter = $this->request('POST', "/admin/adherents/$member/espace", ['remise' => 'lettre', '_once' => $token]);
        $this->assertSame(200, $letter->status());
        $this->assertSame(1, preg_match(self::CODE, $letter->body(), $m));
        $code = $m[0];
        $hash = ActivationCode::hash($code, (string) self::config()->get('APP_KEY'));
        $this->assertSame(1, (int) $this->db->fetchValue('SELECT COUNT(*) FROM member_activation_codes WHERE code_hash = ? AND used_at IS NULL', [$hash]));

        $reload = $this->request('POST', "/admin/adherents/$member/espace", ['remise' => 'lettre', '_once' => $token]);
        $this->assertSame(302, $reload->status());
        $this->assertStringNotContainsString($code, $reload->body());
        $this->assertSame(1, $this->codeRows($member), 'aucun nouveau code');
        $tab = $this->tab($member)->body();
        $this->assertStringContainsString(e('Code déjà délivré : délivrez-en un nouveau si la lettre est perdue.'), $tab);
        $this->assertStringNotContainsString($code, $tab);
        $this->assertStringContainsString('En attente d', $tab);
        $this->assertStringNotContainsString(ActivationCode::normalize($code), json_encode($this->db->fetchAll('SELECT * FROM audit_logs')));

        // Nouveau code (accès en attente) : nouvelle lettre, l'ancien est fermé ; sans jeton à usage unique : refusé.
        $this->assertSame(302, $this->request('POST', "/admin/adherents/$member/espace/code", ['remise' => 'lettre'])->status());
        $this->assertSame(1, $this->codeRows($member));
        $again = $this->request('POST', "/admin/adherents/$member/espace/code", ['remise' => 'lettre', '_once' => $this->onceToken($member)]);
        $this->assertSame(200, $again->status());
        $this->assertSame(1, preg_match(self::CODE, $again->body(), $m2));
        $this->assertNotSame($code, $m2[0]);
        $this->assertNotNull($this->db->fetchValue('SELECT used_at FROM member_activation_codes WHERE code_hash = ?', [$hash]));
    }

    public function test_letter_is_no_store_no_referrer_and_print_layout(): void
    {
        $member = $this->memberForAccess(['last_name' => 'Yao', 'first_names' => 'Koffi Serge']);
        $this->actingAs($this->manager);
        $res = $this->request('POST', "/admin/adherents/$member/espace", ['remise' => 'lettre', '_once' => $this->onceToken($member)]);
        $this->assertSame(200, $res->status());
        $this->assertStringContainsString('no-store', $res->headers()['Cache-Control']);
        $this->assertSame('no-referrer', $res->headers()['Referrer-Policy']);
        $body = $res->body();
        $this->assertStringContainsString('<body class="print">', $body);
        $this->assertStringContainsString('Yao Koffi Serge', $body);
        $this->assertStringContainsString($this->memberNumber($member), $body);
        $this->assertStringContainsString(rtrim((string) self::config()->get('APP_URL'), '/') . '/espace/activer', $body);
        $this->assertStringContainsString("Ce code ne sert qu'une fois. Ne le communiquez à personne.", $body);
        $this->assertSame(4, substr_count($body, '<li>'));
        $expires = (string) $this->db->fetchValue('SELECT c.expires_at FROM member_activation_codes c JOIN member_accounts a ON a.id = c.member_account_id WHERE a.member_id = ?', [$member]);
        $this->assertStringContainsString(substr(date_fr($expires), 0, 10), $body);
        $this->assertStringNotContainsString('style=', $body);
        $this->assertDoesNotMatchRegularExpression('/<script(?![^>]*\bsrc=)/', $body);
        $this->assertDoesNotMatchRegularExpression('/\son[a-z]+=/', $body);
    }

    public function test_email_delivery_never_shows_code_to_staff(): void
    {
        $member = $this->memberForAccess(['email' => 'mer@x.test']);
        $this->actingAs($this->manager);
        $res = $this->request('POST', "/admin/adherents/$member/espace", ['remise' => 'email', '_once' => $this->onceToken($member)]);
        $this->assertSame(302, $res->status());
        $this->assertSame("/admin/adherents/$member?onglet=espace", $res->headers()['Location']);
        $this->assertDoesNotMatchRegularExpression(self::CODE, $res->body());
        $mails = $this->mails();
        $this->assertCount(1, $mails);
        $this->assertSame(1, preg_match(self::CODE, $mails[0]['text'], $m));
        $tab = $this->tab($member)->body();
        $this->assertStringContainsString('Code envoyé à l&#039;adresse de la fiche.', $tab);
        $this->assertStringNotContainsString($m[0], $tab);
        $this->assertDoesNotMatchRegularExpression(self::CODE, $tab);
    }

    public function test_email_option_disabled_without_address_or_mailer(): void
    {
        $radio = '/<input type="radio" name="remise" value="email"([^>]*)>/';
        $noMail = $this->memberForAccess();
        $withMail = $this->memberForAccess(['email' => 'adresse@x.test']);
        $this->actingAs($this->manager);
        preg_match($radio, $this->tab($noMail)->body(), $a);
        $this->assertStringContainsString('disabled', $a[1]);
        $this->assertStringContainsString('Aucune adresse e-mail dans la fiche', $this->tab($noMail)->body());
        preg_match($radio, $this->tab($withMail)->body(), $b);
        $this->assertStringNotContainsString('disabled', $b[1]);

        $this->assertSame(302, $this->request('POST', "/admin/adherents/$noMail/espace", ['remise' => 'email', '_once' => $this->onceToken($noMail)])->status());
        $this->assertStringContainsString('Envoi par e-mail impossible', $this->tab($noMail)->body());
        $this->assertNull($this->db->fetchValue('SELECT id FROM member_accounts WHERE member_id = ?', [$noMail]));

        $this->env = ['APP_URL' => ''];
        $this->forgetApp();
        $previous = ini_set('error_log', '/dev/null');
        try {
            $this->actingAs($this->manager);
            $body = $this->tab($withMail)->body();
        } finally {
            ini_set('error_log', (string) $previous);
        }
        preg_match($radio, $body, $c);
        $this->assertStringContainsString('disabled', $c[1]);
        $this->assertStringContainsString('envoi d&#039;e-mails n&#039;est pas configuré', $body);
    }

    public function test_reset_needs_checkbox_server_side(): void
    {
        ['member' => $member] = $this->memberWithAccount(['birth_date' => self::BIRTH_DATE, 'status' => 'actif']);
        $this->actingAs($this->manager);
        $tab = $this->tab($member)->body();
        $this->assertStringContainsString("J'ai vérifié l'identité de l'adhérent sur une pièce officielle", $tab);
        $this->assertStringContainsString('data-confirm-open="confirm-espace-reset"', $tab);

        $res = $this->request('POST', "/admin/adherents/$member/espace/reinitialiser", ['remise' => 'lettre', '_once' => $this->onceToken($member)]);
        $this->assertSame(302, $res->status());
        $this->assertSame(0, $this->codeRows($member));
        $this->assertNotNull($this->db->fetchValue('SELECT password FROM member_accounts WHERE member_id = ?', [$member]));
        $this->assertStringContainsString('Confirmez avoir vérifié l&#039;identité', $this->tab($member)->body());

        $ok = $this->request('POST', "/admin/adherents/$member/espace/reinitialiser", ['remise' => 'lettre', 'identite_verifiee' => '1', '_once' => $this->onceToken($member)]);
        $this->assertSame(200, $ok->status());
        $this->assertMatchesRegularExpression(self::CODE, $ok->body());
        $this->assertNull($this->db->fetchValue('SELECT password FROM member_accounts WHERE member_id = ?', [$member]));
        $row = $this->db->fetch("SELECT user_id, new_values FROM audit_logs WHERE action = 'espace.reset_issued' ORDER BY id DESC LIMIT 1");
        $this->assertSame($this->manager, (int) $row['user_id']);
        $this->assertTrue(json_decode($row['new_values'], true)['identity_checked']);
    }

    public function test_delegate_can_open_access_and_print_letter_in_zone_only(): void
    {
        [$zoneA, $zoneB] = [$this->zone(), $this->zone()];
        $inside = $this->memberForAccess(['zone_id' => $zoneA]);
        $outside = $this->memberForAccess(['zone_id' => $zoneB]);
        $this->actingAs($this->delegate($zoneA));
        $letter = $this->request('POST', "/admin/adherents/$inside/espace", ['remise' => 'lettre', '_once' => $this->onceToken($inside)]);
        $this->assertSame(200, $letter->status());
        $this->assertMatchesRegularExpression(self::CODE, $letter->body());

        $this->assertSame(404, $this->tab($outside)->status());
        foreach (['espace', 'espace/code', 'espace/desactiver', 'espace/reactiver'] as $p) {
            $this->assertSame(404, $this->request('POST', "/admin/adherents/$outside/$p", ['remise' => 'lettre', '_once' => str_repeat('a', 64)])->status(), $p);
        }
        $this->assertSame(0, $this->codeRows($outside));
    }

    public function test_delegate_cannot_reset_an_active_account(): void
    {
        $zone = $this->zone();
        ['member' => $member] = $this->memberWithAccount(['birth_date' => self::BIRTH_DATE, 'status' => 'actif', 'zone_id' => $zone]);
        $this->actingAs($this->delegate($zone));
        $tab = $this->tab($member)->body();
        $this->assertStringNotContainsString('/espace/reinitialiser', $tab);
        $this->assertStringContainsString('/espace/desactiver', $tab);
        $this->assertSame(403, $this->request('POST', "/admin/adherents/$member/espace/reinitialiser", ['remise' => 'lettre', 'identite_verifiee' => '1'])->status());
        $this->assertSame(0, $this->codeRows($member));
        $this->assertNotNull($this->db->fetchValue('SELECT password FROM member_accounts WHERE member_id = ?', [$member]));

        // Désactiver puis réactiver (droit du délégué), dans sa zone.
        $this->assertSame(302, $this->request('POST', "/admin/adherents/$member/espace/desactiver")->status());
        $this->assertSame('desactive', $this->db->fetchValue('SELECT status FROM member_accounts WHERE member_id = ?', [$member]));
        $this->assertStringContainsString('/espace/reactiver', $this->tab($member)->body());
        $this->assertSame(302, $this->request('POST', "/admin/adherents/$member/espace/reactiver")->status());
        $this->assertSame('actif', $this->db->fetchValue('SELECT status FROM member_accounts WHERE member_id = ?', [$member]));
    }

    public function test_accounts_list_limited_to_scope_and_filters(): void
    {
        [$zoneA, $zoneB] = [$this->zone(), $this->zone()];
        $tag = bin2hex(random_bytes(3));
        $none = $this->memberForAccess(['zone_id' => $zoneA, 'last_name' => "Sansacces$tag"]);
        $pending = $this->memberForAccess(['zone_id' => $zoneA, 'last_name' => "Attente$tag"]);
        $this->accounts()->create($pending, 'lettre', $this->manager, null);
        $expired = $this->memberForAccess(['zone_id' => $zoneA, 'last_name' => "Expire$tag"]);
        $this->accounts()->create($expired, 'lettre', $this->manager, null);
        $this->db->query('UPDATE member_activation_codes c JOIN member_accounts a ON a.id = c.member_account_id SET c.expires_at = ? WHERE a.member_id = ?', [gmdate('Y-m-d H:i:s', time() - 60), $expired]);
        ['member' => $active, 'account' => $activeAccount] = $this->memberWithAccount(['zone_id' => $zoneA, 'last_name' => "Actif$tag", 'birth_date' => self::BIRTH_DATE]);
        $this->db->query('UPDATE member_accounts SET last_login_at = ? WHERE id = ?', ['2026-11-20 10:00:00', $activeAccount]);
        ['member' => $disabled] = $this->memberWithAccount(['zone_id' => $zoneA, 'last_name' => "Desactive$tag"], 'desactive');
        $elsewhere = $this->memberForAccess(['zone_id' => $zoneB, 'last_name' => "Ailleurs$tag"]);

        $this->actingAs($this->delegate($zoneA));
        $all = $this->request('GET', '/admin/espace/comptes')->body();
        foreach (['Sansacces', 'Attente', 'Expire', 'Actif', 'Desactive'] as $n) {
            $this->assertStringContainsString($n . $tag, $all);
        }
        $this->assertStringNotContainsString("Ailleurs$tag", $all);
        $this->assertStringNotContainsString('name="zone"', $all, 'filtre de zone réservé au périmètre complet');
        $this->assertStringContainsString('20/11/2026', $all);
        $this->assertDoesNotMatchRegularExpression(self::CODE, $all);
        $this->assertStringNotContainsString('$argon', $all);
        $this->assertStringNotContainsString(self::BIRTH_DATE, $all);
        $this->assertStringNotContainsString(date('d/m/Y', strtotime(self::BIRTH_DATE)), $all);

        $expect = ['sans_acces' => 'Sansacces', 'en_attente' => 'Attente', 'code_expire' => 'Expire', 'actif' => 'Actif', 'desactive' => 'Desactive'];
        foreach ($expect as $state => $name) {
            $body = $this->request('GET', "/admin/espace/comptes?etat=$state")->body();
            foreach ($expect as $other) {
                $other === $name ? $this->assertStringContainsString($other . $tag, $body, $state) : $this->assertStringNotContainsString($other . $tag . ' ', $body, "$state / $other");
            }
        }

        $this->actingAs($this->manager);
        $full = $this->request('GET', "/admin/espace/comptes?zone=$zoneB")->body();
        $this->assertStringContainsString('name="zone"', $full);
        $this->assertStringContainsString("Ailleurs$tag", $full);
        $this->assertStringNotContainsString("Actif$tag", $full);
        $this->assertSame(200, $this->request('GET', '/admin/espace/comptes?etat=inconnu&page=abc')->status());
        unset($none, $active, $disabled, $elsewhere);
    }

    public function test_settings_screen_shows_non_configure_and_validates_with_decision_ref(): void
    {
        $validator = $this->createUser('pca-' . bin2hex(random_bytes(3)) . '@x.test', ['pca']);
        $this->actingAs($this->manager);
        $page = $this->request('GET', '/admin/espace/parametres')->body();
        $this->assertSame(3, substr_count($page, 'Non configuré'));
        $this->assertStringContainsString('paramètre en attente de validation par les instances', $page);
        $this->assertStringContainsString('14 jours', $page);
        $this->assertStringContainsString('Fermé', $page);
        $this->assertStringNotContainsString('<form method="post" action="/admin/espace/parametres/', $page, 'sans settings.validate : lecture seule');

        $this->actingAs($validator);
        $page = $this->request('GET', '/admin/espace/parametres')->body();
        $this->assertStringContainsString('action="/admin/espace/parametres/status_access"', $page);
        $missingRef = $this->request('POST', '/admin/espace/parametres/activation_code_days', ['value_activation_code_days' => '21', 'decision_ref_activation_code_days' => ' ']);
        $this->assertSame(422, $missingRef->status());
        $this->assertStringContainsString('La référence de la décision est obligatoire', $missingRef->body());
        $this->assertSame('non_configure', $this->db->fetchValue("SELECT status FROM settings WHERE `group` = 'espace' AND `key` = 'activation_code_days'"));

        $this->assertSame(302, $this->request('POST', '/admin/espace/parametres/activation_code_days', ['value_activation_code_days' => '21', 'decision_ref_activation_code_days' => 'PV-CA-2026-14'])->status());
        $row = $this->db->fetch("SELECT `value`, status, decision_ref, validated_by FROM settings WHERE `group` = 'espace' AND `key` = 'activation_code_days'");
        $this->assertSame(['21', 'valide', 'PV-CA-2026-14', $validator], [$row['value'], $row['status'], $row['decision_ref'], (int) $row['validated_by']]);
        $this->assertSame(['key' => 'activation_code_days', 'decision_ref' => 'PV-CA-2026-14'],
            json_decode((string) $this->db->fetchValue("SELECT new_values FROM audit_logs WHERE action = 'espace.setting_validated' ORDER BY id DESC LIMIT 1"), true));

        $map = ['actif' => 'complet', 'suspendu' => 'lecture', 'radie' => 'desactive', 'decede' => 'desactive', 'ancien_marin' => 'lecture'];
        $this->assertSame(302, $this->request('POST', '/admin/espace/parametres/status_access', ['status_access' => $map, 'decision_ref_status_access' => 'PV-2'])->status());
        $this->assertEquals($map, json_decode((string) $this->db->fetchValue("SELECT `value` FROM settings WHERE `group` = 'espace' AND `key` = 'status_access'"), true));
        $this->assertSame(302, $this->request('POST', '/admin/espace/parametres/depot_demande_en_ligne', ['value_depot_demande_en_ligne' => 'non', 'decision_ref_depot_demande_en_ligne' => 'PV-3'])->status());
        $this->assertFalse(json_decode((string) $this->db->fetchValue("SELECT `value` FROM settings WHERE `group` = 'espace' AND `key` = 'depot_demande_en_ligne'"), true));

        $after = $this->request('GET', '/admin/espace/parametres')->body();
        $this->assertSame(0, substr_count($after, 'Non configuré'));
        $this->assertStringContainsString('21 jours', $after);
        $this->assertStringContainsString('PV-CA-2026-14', $after);
        $this->assertSame(404, $this->request('POST', '/admin/espace/parametres/inconnu', ['value_inconnu' => 'x', 'decision_ref_inconnu' => 'PV'])->status());
    }

    public function test_status_access_validation_rejects_unknown_level_or_status(): void
    {
        $validator = $this->createUser('pca-' . bin2hex(random_bytes(3)) . '@x.test', ['pca']);
        $svc = new EspaceSettingsService($this->db, new Audit($this->db, (string) self::config()->get('APP_KEY')), new Gate($this->db));
        $good = ['actif' => 'complet', 'suspendu' => 'lecture', 'radie' => 'desactive', 'decede' => 'desactive', 'ancien_marin' => 'lecture'];
        $bad = [
            'vide' => '',
            'liste' => '[]',
            'objet vide' => '{}',
            'non JSON' => 'actif=complet',
            'radie manquant' => json_encode(array_diff_key($good, ['radie' => 1])),
            'decede manquant' => json_encode(array_diff_key($good, ['decede' => 1])),
            'statut actif manquant' => json_encode(array_diff_key($good, ['ancien_marin' => 1])),
            'niveau inconnu' => json_encode(['actif' => 'total'] + $good),
            'niveau vide' => json_encode(['radie' => ''] + $good),
            'statut inconnu' => json_encode($good + ['pirate' => 'complet']),
            'niveau non texte' => json_encode(['actif' => 1] + $good),
        ];
        foreach ($bad as $label => $raw) {
            try {
                $svc->validate('status_access', (string) $raw, 'PV-1', $validator);
                $this->fail("refus attendu : $label");
            } catch (DomainException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
        $this->assertSame('non_configure', $this->db->fetchValue("SELECT status FROM settings WHERE `group` = 'espace' AND `key` = 'status_access'"));
        foreach (['activation_code_days' => ['0', '91', '-3', '1.5', 'abc', ''], 'depot_demande_en_ligne' => ['peut-être', '1', '']] as $key => $values) {
            foreach ($values as $v) {
                try {
                    $svc->validate($key, $v, 'PV-1', $validator);
                    $this->fail("refus attendu : $key = $v");
                } catch (DomainException) {
                    $this->addToAssertionCount(1);
                }
            }
        }
        try {
            $svc->validate('activation_code_days', '30', 'PV-1', $this->manager);
            $this->fail('settings.validate exigé');
        } catch (DomainException) {
            $this->addToAssertionCount(1);
        }
        $svc->validate('status_access', json_encode($good), 'PV-1', $validator);
        $this->assertSame('valide', $this->db->fetchValue("SELECT status FROM settings WHERE `group` = 'espace' AND `key` = 'status_access'"));
    }

    public function test_hostile_member_names_are_escaped_in_letter_and_list(): void
    {
        $hostile = '<script>alert(1)</script>"><img src=x onerror=alert(2)>';
        $member = $this->memberForAccess(['last_name' => $hostile, 'first_names' => "O'Neil <b>"]);
        $this->actingAs($this->manager);
        $tab = $this->tab($member)->body();
        $letter = $this->request('POST', "/admin/adherents/$member/espace", ['remise' => 'lettre', '_once' => $this->onceToken($member)])->body();
        $list = $this->request('GET', '/admin/espace/comptes?etat=en_attente')->body();
        foreach (['onglet' => $tab, 'lettre' => $letter, 'liste' => $list] as $where => $body) {
            $this->assertStringNotContainsString('<script>alert(1)', $body, $where);
            $this->assertStringNotContainsString('<img src=x', $body, $where);
            $this->assertStringNotContainsString('<b>', $body, $where);
        }
        $this->assertStringContainsString(e($hostile), $letter);
        $this->assertStringContainsString(e($hostile), $list);
    }
}
