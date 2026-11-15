<?php
declare(strict_types=1);

namespace Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class UiTest extends TestCase
{
    private const XSS = '<script>x</script>';
    private const XSS2 = '"><img src=x onerror=a>';

    /** @return array<string,array<string,mixed>> props de chaque composant, toutes les saisies étant hostiles (sauf noms d'icône, liste blanche) */
    private function fixtures(string $x): array
    {
        $btn = ['label' => $x, 'href' => $x, 'icon' => 'plus'];
        return [
            'button' => $btn + ['variant' => $x, 'type' => $x, 'size' => $x],
            'field' => ['name' => $x, 'label' => $x, 'type' => $x, 'value' => $x, 'error' => $x, 'hint' => $x, 'autocomplete' => $x, 'inputmode' => $x, 'options' => [$x => $x], 'required' => true],
            'alert' => ['kind' => $x, 'title' => $x, 'message' => $x],
            'badge' => ['label' => $x, 'kind' => $x],
            'panel' => ['title' => $x],
            'page-header' => ['title' => $x, 'subtitle' => $x, 'breadcrumbs' => [[$x, $x], [$x]]],
            'tabs' => ['label' => $x, 'items' => [[$x, $x, true]]],
            'table' => ['caption' => $x, 'columns' => [[$x, $x, $x, true]], 'rows' => [[$x => $x]]],
            'pagination' => ['page' => 2, 'pages' => 5, 'base' => $x, 'query' => [$x => $x]],
            'empty-state' => ['title' => $x, 'message' => $x, 'action' => $btn],
            'confirm-dialog' => ['id' => $x, 'title' => $x, 'message' => $x, 'confirm_label' => $x, 'form_action' => $x, 'csrf' => $x],
            'validation-steps' => ['steps' => [['label' => $x, 'who' => $x, 'when' => $x, 'state' => $x]]],
            'file-input' => ['name' => $x, 'label' => $x, 'accept' => $x, 'hint' => $x, 'error' => $x, 'max_mb' => 5],
            'breadcrumbs' => ['items' => [[$x, $x], [$x]]],
        ];
    }

    public function test_every_prop_is_escaped(): void
    {
        $files = array_map(static fn (string $f): string => basename($f, '.php'), glob(dirname(__DIR__, 2) . '/app/views/components/*.php'));
        $this->assertEqualsCanonicalizing($files, array_keys($this->fixtures('a')), 'chaque composant a un jeu de test');
        foreach ([self::XSS, self::XSS2] as $x) {
            foreach ($this->fixtures($x) as $name => $props) {
                $html = ui($name, $props);
                $this->assertNotSame('', $html, $name);
                $this->assertStringNotContainsString('<script>', $html, $name);
                $this->assertStringNotContainsString('<img', $html, $name);
                $this->assertStringNotContainsString('style=', $html, $name);
            }
        }
    }

    public function test_slot_is_injected_unescaped_and_only_there(): void
    {
        $html = ui('panel', ['title' => '<b>t</b>'], '<p class="ok">dedans</p>');
        $this->assertStringContainsString('<p class="ok">dedans</p>', $html);
        $this->assertStringContainsString('&lt;b&gt;t&lt;/b&gt;', $html);
        $raw = '<i>brut</i>';
        $this->assertStringContainsString($raw, ui('panel', ['title' => 'T', 'actions' => $raw]));
        $this->assertStringNotContainsString($raw, ui('panel', ['title' => $raw, 'actions' => '']));
        $this->assertStringNotContainsString($raw, ui('table', ['columns' => [['n', $raw]], 'rows' => [['n' => $raw]]]));
        $this->assertStringContainsString($raw, ui('table', ['columns' => [['n', 'N', 'left', false, static fn (array $r): string => $raw]], 'rows' => [['n' => 'x']],
            'row_actions' => static fn (array $r): string => '<b>act</b>']));
        $this->assertStringContainsString('<a href="#">Ok</a>', ui('page-header', ['title' => 'T'], '<a href="#">Ok</a>'));
    }

    public function test_confirm_dialog_requires_csrf(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ui('confirm-dialog', ['id' => 'd', 'title' => 'T', 'message' => 'M', 'form_action' => '/z']);
    }

    public function test_table_marks_primary_and_status_cells_and_keeps_roles(): void
    {
        $html = ui('table', ['columns' => [['n', 'Nom'], ['s', 'Statut', 'left', false, null, 'status'], ['r', 'Rôle']], 'rows' => [['n' => 'A', 's' => 'ok', 'r' => 'x']]]);
        $this->assertStringContainsString('cell-primary"', $html);
        $this->assertStringContainsString('cell-status"', $html);
        $this->assertStringContainsString('cell-meta"', $html);
        $this->assertStringContainsString('role="row"', $html);
        $this->assertStringContainsString('role="columnheader"', $html);
    }

    public function test_unknown_component_throws_a_clear_exception(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Composant inconnu');
        ui('nope');
    }

    public function test_path_traversal_component_name_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ui('../layouts/base');
    }

    public function test_icon_name_outside_whitelist_throws(): void
    {
        $this->assertStringContainsString('aria-hidden="true"', ui_icon('home'));
        $this->assertStringContainsString('role="img" aria-label="Accueil"', ui_icon('home', 'Accueil'));
        $this->assertStringContainsString('icons.svg#home', ui_icon('home'));
        $this->expectException(InvalidArgumentException::class);
        ui_icon('"><script>');
    }

    public function test_every_icon_of_the_whitelist_exists_in_the_sprite(): void
    {
        $svg = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/img/icons.svg');
        foreach (\App\Core\Ui::ICONS as $name) {
            $this->assertStringContainsString('<symbol id="' . $name . '" ', $svg, $name);
        }
    }

    public function test_field_links_label_error_and_hint_with_ids(): void
    {
        $html = ui('field', ['name' => 'email', 'label' => 'E-mail', 'hint' => 'Aide', 'error' => 'Invalide', 'required' => true]);
        $this->assertStringContainsString('<label for="f-email">', $html);
        $this->assertStringContainsString('id="f-email"', $html);
        $this->assertStringContainsString('aria-describedby="f-email-hint f-email-err"', $html);
        $this->assertStringContainsString('id="f-email-hint"', $html);
        $this->assertStringContainsString('id="f-email-err" role="alert"', $html);
        $this->assertStringContainsString('aria-invalid="true"', $html);
        $this->assertStringContainsString(' required', $html);
        $plain = ui('field', ['name' => 'a', 'label' => 'A']);
        $this->assertStringNotContainsString('aria-invalid', $plain);
        $this->assertStringNotContainsString('aria-describedby', $plain);
    }

    public function test_password_field_never_echoes_its_value(): void
    {
        $this->assertStringNotContainsString('secret', ui('field', ['name' => 'p', 'label' => 'P', 'type' => 'password', 'value' => 'secret']));
    }

    public function test_alert_danger_has_role_alert_and_ok_has_role_status(): void
    {
        $this->assertStringContainsString('role="alert"', ui('alert', ['kind' => 'danger', 'message' => 'm']));
        foreach (['ok', 'warn', 'info'] as $k) {
            $this->assertStringContainsString('role="status"', ui('alert', ['kind' => $k, 'message' => 'm']));
        }
    }

    public function test_badge_always_contains_text(): void
    {
        foreach (['ok', 'warn', 'danger', 'info', 'neutral'] as $k) {
            $this->assertMatchesRegularExpression('~>Libellé</span>~', ui('badge', ['kind' => $k, 'label' => 'Libellé']));
        }
    }

    public function test_button_states(): void
    {
        $this->assertStringContainsString('<button class="btn btn-primary" type="button" disabled>', ui('button', ['label' => 'x', 'disabled' => true]));
        $busy = ui('button', ['label' => 'x', 'busy' => true, 'type' => 'submit']);
        $this->assertStringContainsString('aria-busy="true"', $busy);
        $this->assertStringContainsString('type="submit"', $busy);
        $link = ui('button', ['label' => 'x', 'href' => '/a?b=1&c=2']);
        $this->assertStringContainsString('<a class="btn btn-primary" href="/a?b=1&amp;c=2">', $link);
        $this->assertStringNotContainsString('href', ui('button', ['label' => 'x', 'href' => '/a', 'disabled' => true]));
    }

    public function test_table_renders_empty_state_and_right_aligned_numbers(): void
    {
        $cols = [['n', 'Nom'], ['amt', 'Montant', 'right', true]];
        $empty = ui('table', ['columns' => $cols, 'rows' => [], 'empty' => ['title' => 'Rien ici']]);
        $this->assertStringContainsString('Rien ici', $empty);
        $this->assertStringNotContainsString('<table', $empty);
        $html = ui('table', ['columns' => $cols, 'rows' => [['n' => 'A', 'amt' => '1 000']], 'caption' => 'Cap',
            'row_actions' => static fn (array $r): string => '<i>act</i>']);
        $this->assertStringContainsString('<td role="cell" class="num cell-meta">1 000</td>', $html);
        $this->assertStringContainsString('<th role="columnheader" scope="col" class="num">Montant</th>', $html);
        $this->assertStringContainsString('<caption>Cap</caption>', $html);
        $this->assertStringContainsString('<i>act</i>', $html);
    }

    public function test_pagination_hides_when_one_page_and_builds_links_with_query(): void
    {
        $this->assertSame('', trim(ui('pagination', ['page' => 1, 'pages' => 1, 'base' => '/x'])));
        $html = ui('pagination', ['page' => 3, 'pages' => 9, 'base' => '/x', 'query' => ['q' => 'a b', 'page' => 99]]);
        $this->assertStringContainsString('aria-label="Pagination"', $html);
        $this->assertStringContainsString('href="/x?q=a+b&amp;page=2" rel="prev"', $html);
        $this->assertStringContainsString('href="/x?q=a+b&amp;page=4" rel="next"', $html);
        $this->assertStringContainsString('<a href="/x?q=a+b&amp;page=3" aria-current="page" aria-label="Page 3">3</a>', $html);
        $this->assertStringContainsString('…', $html);
        $this->assertStringContainsString('aria-disabled="true">Précédent', ui('pagination', ['page' => 1, 'pages' => 3, 'base' => '/x']));
    }

    public function test_validation_steps_marks_current_step_and_exposes_state_as_text(): void
    {
        $html = ui('validation-steps', ['steps' => [
            ['label' => 'A', 'state' => 'done'], ['label' => 'B', 'state' => 'current', 'who' => 'Yao', 'when' => '1/2'],
            ['label' => 'C', 'state' => 'todo'], ['label' => 'D', 'state' => 'rejected'],
        ]]);
        $this->assertSame(1, substr_count($html, 'aria-current="step"'));
        foreach (['Validé', 'En attente', 'À venir', 'Refusé'] as $text) {
            $this->assertStringContainsString('<span class="step-state">' . $text . '</span>', $html);
        }
        $this->assertStringContainsString('Yao · 1/2', $html);
    }

    public function test_page_header_has_the_only_h1_and_breadcrumbs_mark_current_page(): void
    {
        $html = ui('page-header', ['title' => 'T', 'breadcrumbs' => [['Admin', '/admin'], ['Ici']]]);
        $this->assertSame(1, substr_count($html, '<h1>'));
        $this->assertStringContainsString('<a href="/admin">Admin</a>', $html);
        $this->assertStringContainsString('<span aria-current="page">Ici</span>', $html);
    }

    public function test_tabs_and_confirm_dialog_and_file_input_attributes(): void
    {
        $tabs = ui('tabs', ['label' => 'Filtre', 'items' => [['A', '/a', true], ['B', '/b', false]]]);
        $this->assertSame(1, substr_count($tabs, 'aria-current="page"'));
        $dlg = ui('confirm-dialog', ['id' => 'd1', 'title' => 'T', 'message' => 'M', 'form_action' => '/z', 'csrf' => 'tok']);
        $this->assertStringContainsString('<dialog class="dialog" id="d1" aria-labelledby="d1-title" aria-describedby="d1-msg">', $dlg);
        $this->assertStringContainsString('name="_csrf" value="tok"', $dlg);
        $file = ui('file-input', ['name' => 'doc', 'label' => 'Doc', 'max_mb' => 5, 'accept' => '.pdf']);
        $this->assertStringContainsString('type="file"', $file);
        $this->assertStringContainsString('Taille maximale : 5 Mo.', $file);
        $this->assertStringContainsString('data-file-input', $file);
    }
}
