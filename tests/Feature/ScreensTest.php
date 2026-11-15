<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Roles\ModuleLabels;
use Tests\Support\AdminTestCase;

/** Écrans existants habillés avec le système L2 : accessibilité de base, textes, échappement, lecture seule. */
final class ScreensTest extends AdminTestCase
{
    private int $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db->query('DELETE FROM users');
        $this->admin = $this->createUser('admin@x.test', ['super_admin']);
    }

    public function test_login_error_is_announced_and_linked_to_the_field(): void
    {
        $res = $this->request('POST', '/admin/connexion', ['email' => 'inconnu@x.test', 'password' => 'pas le bon mot de passe']);
        $html = $res->body();

        $this->assertStringContainsString('Identifiants incorrects.', $html);
        $this->assertMatchesRegularExpression('#<p class="field-error" id="f-password-err" role="alert">#', $html);
        $this->assertMatchesRegularExpression('#<input type="password"[^>]*aria-describedby="f-password-err"[^>]*aria-invalid="true"#', $html);
        $this->assertMatchesRegularExpression('#<label for="f-password">Mot de passe#', $html);
        $this->assertStringContainsString('href="/admin/mot-de-passe-oublie">Mot de passe oublié ?', $html);
        $this->assertStringNotContainsString('f-password-err', $this->request('GET', '/admin/connexion')->body(), 'pas d\'erreur avant toute tentative');
    }

    public function test_every_screen_has_one_h1_and_a_page_title(): void
    {
        $role = (int) $this->db->fetchValue("SELECT id FROM roles WHERE name = 'gest_cms'");
        $guest = ['/admin/connexion', '/admin/mot-de-passe-oublie', '/admin/reinitialiser/' . str_repeat('a', 64), '/introuvable'];
        $signedIn = ['/admin', '/admin/utilisateurs', '/admin/utilisateurs?q=zzz', '/admin/utilisateurs/nouveau', "/admin/utilisateurs/{$this->admin}",
            '/admin/roles', '/admin/roles/nouveau', "/admin/roles/$role", '/admin/journal', '/admin/journal?onglet=sensibles', '/admin/journal?du=pas-une-date',
            '/admin/mon-compte/mot-de-passe'];
        foreach ([...$guest, ...$signedIn] as $url) {
            if ($url === $signedIn[0]) {
                $this->actingAs($this->admin);
            }
            $html = $this->request('GET', $url)->body();
            $this->assertSame(1, substr_count($html, '<h1'), "$url : un seul h1");
            $this->assertMatchesRegularExpression('#<title>[^<]+</title>#', $html, "$url : titre de page");
            $this->assertStringNotContainsString(' style=', $html, "$url : aucun style en ligne");
            $this->assertStringContainsString('/assets/css/tokens.css', $html, $url);
        }
        $views = dirname(__DIR__, 2) . '/app/views/errors/';
        foreach (['400', '403', '404', '405', '419', '500'] as $code) {
            $html = (string) file_get_contents($views . "$code.php");
            $this->assertSame(1, substr_count($html, '<h1'), $code);
            $this->assertStringContainsString('<a href="', $html, "$code : lien de retour");
        }
    }

    public function test_roles_form_shows_french_module_names_and_unknown_module_falls_back_to_its_code(): void
    {
        $this->db->insert('permissions', ['name' => 'zzmod.view', 'label' => 'Voir le module inconnu', 'module' => 'zzmod']);
        $this->actingAs($this->admin);
        $role = $this->db->insert('roles', ['name' => 'test_lecteur', 'label' => 'Lecteur']);
        $form = $this->request('GET', "/admin/roles/$role")->body();

        $this->assertStringContainsString('<legend>Finances</legend>', $form);
        $this->assertStringContainsString("<legend>Demandes d&#039;assistance</legend>", $form);
        $this->assertStringContainsString('<legend>zzmod</legend>', $form);
        $this->assertStringNotContainsString('<legend>finance</legend>', $form);
        $this->assertSame('Fonds de solidarité', ModuleLabels::label('solidarity'));
        $this->assertSame('inconnu', ModuleLabels::label('inconnu'));
        $this->assertSame('Délégués et points focaux', ModuleLabels::label('delegates'));
    }

    public function test_protected_role_is_read_only_for_an_actor_without_the_right_and_has_no_save_button(): void
    {
        $protected = (int) $this->db->fetchValue("SELECT id FROM roles WHERE name = 'gest_social'");
        $this->actingAs($this->createUser('ag@x.test', ['admin_general']));
        $html = $this->request('GET', "/admin/roles/$protected")->body();

        $this->assertStringContainsString('Ce rôle est protégé : seul un super administrateur peut le modifier.', $html);
        $this->assertStringNotContainsString('<span>Enregistrer</span>', $html);
        $this->assertStringNotContainsString('Supprimer ce rôle', $html);
        $this->assertDoesNotMatchRegularExpression('#<input type="checkbox"(?![^>]* disabled)#', $html);
        $this->assertMatchesRegularExpression('#<input type="text"[^>]*name="label"[^>]*disabled|<input[^>]*name="label"[^>]*disabled#', $html);
        // Les règles serveur ne changent pas : l'écriture reste refusée.
        $this->assertSame(422, $this->request('POST', "/admin/roles/$protected", ['label' => 'X'])->status());

        // Un super administrateur modifie ce même rôle : formulaire complet.
        $this->actingAs($this->admin);
        $html = $this->request('GET', "/admin/roles/$protected")->body();
        $this->assertStringNotContainsString('Ce rôle est protégé', $html);
        $this->assertStringContainsString('<span>Enregistrer</span>', $html);
    }

    public function test_users_list_renders_hostile_names_escaped(): void
    {
        $long = str_repeat('W', 60);
        $this->db->insert('users', ['name' => '<b>X</b>', 'email' => 'hostile@x.test', 'password' => 'x']);
        $this->db->insert('users', ['name' => $long, 'email' => 'long@x.test', 'password' => 'x']);
        $this->actingAs($this->admin);
        $html = $this->request('GET', '/admin/utilisateurs')->body();

        $this->assertStringContainsString('<strong>&lt;b&gt;X&lt;/b&gt;</strong>', $html);
        $this->assertStringNotContainsString('<b>X</b>', $html);
        $this->assertStringContainsString("<strong>$long</strong>", $html);
        $this->assertStringContainsString('Actif', $html);
        $this->assertStringContainsString('Nouvel utilisateur', $html);
        $form = $this->request('GET', '/admin/utilisateurs/' . (int) $this->db->fetchValue("SELECT id FROM users WHERE email = 'hostile@x.test'"))->body();
        $this->assertStringNotContainsString('<b>X</b>', $form);
        $this->assertStringContainsString('data-confirm-open="confirm-desactivation"', $form);
        $this->assertMatchesRegularExpression('#<dialog[^>]*id="confirm-desactivation".*?name="active" value="0"#s', $form);
    }

    public function test_audit_values_remain_escaped_after_restyle(): void
    {
        $this->db->insert('users', ['name' => '<i>Hostile</i>', 'email' => 'h@x.test', 'password' => 'x']);
        $uid = (int) $this->db->fetchValue("SELECT id FROM users WHERE email = 'h@x.test'");
        $this->services['audit'] ?? $this->app();
        $this->services['audit']()->log($uid, 'test.hostile', 'obj<script>', 7, ['k' => '<script>alert(1)</script>'], ['n' => '<b>gras</b>'], 'ref<u>1</u>');
        $this->actingAs($this->admin);
        $html = $this->request('GET', '/admin/journal?action=test.hostile')->body();

        foreach (['<script>alert(1)</script>', '<b>gras</b>', '<i>Hostile</i>', '<u>1</u>', 'obj<script>'] as $raw) {
            $this->assertStringNotContainsString($raw, $html, $raw);
        }
        foreach (['&lt;script&gt;alert(1)&lt;/script&gt;', '&lt;b&gt;gras&lt;/b&gt;', '&lt;i&gt;Hostile&lt;/i&gt;', 'ref&lt;u&gt;1&lt;/u&gt;'] as $esc) {
            $this->assertStringContainsString($esc, $html, $esc);
        }
        $this->assertStringContainsString('<details><summary>Détails</summary>', $html);
        $this->assertStringContainsString('aria-label="Sections du journal"', $html);
    }

    public function test_dashboard_has_no_numeric_indicator_and_shows_honest_empty_state(): void
    {
        $this->actingAs($this->admin);
        $html = $this->request('GET', '/admin')->body();
        $this->assertStringContainsString('Rien à traiter pour le moment', $html);
        $main = substr($html, (int) strpos($html, '<main class="sheet"'));
        $this->assertDoesNotMatchRegularExpression('#\d#', html_entity_decode(strip_tags(preg_replace('#<svg.*?</svg>#s', '', $main) ?? '')), 'aucun chiffre affiché sur le tableau de bord');

        $this->actingAs($this->createUser('ro@x.test', ['lecture_seule']));
        $html = $this->request('GET', '/admin')->body();
        foreach (['/admin/utilisateurs', '/admin/roles', '/admin/journal'] as $href) {
            $this->assertStringNotContainsString('class="shortcut" href="' . $href, $html, 'raccourci réservé à ceux qui ont le droit');
        }
        $this->actingAs($this->admin);
        $this->assertStringContainsString('class="shortcut" href="/admin/roles"', $this->request('GET', '/admin')->body());
    }
}
