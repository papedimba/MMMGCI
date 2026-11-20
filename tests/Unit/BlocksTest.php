<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Cms\BlockInvalid;
use App\Modules\Cms\Blocks;
use PHPUnit\Framework\TestCase;

final class BlocksTest extends TestCase
{
    private function invalidPath(array $blocks): string
    {
        try {
            Blocks::validate($blocks);
        } catch (BlockInvalid $e) {
            return $e->path;
        }
        $this->fail('bloc accepté');
    }

    public function test_unknown_block_type_is_refused(): void
    {
        $this->assertSame('blocs[1].type', $this->invalidPath([['type' => 'texte', 'html' => '<p>a</p>'], ['type' => 'script', 'html' => 'x']]));
        $this->assertSame('blocs[0].type', $this->invalidPath(['pas un tableau']));
    }

    public function test_error_paths_point_to_the_field(): void
    {
        $faits = ['type' => 'faits', 'items' => [['label' => 'Adhérents', 'value' => '1 200'], ['label' => '', 'value' => '12']]];
        $this->assertSame('blocs[2].items[1].label', $this->invalidPath([['type' => 'titre', 'text' => 'A', 'level' => '2'], ['type' => 'liste', 'items' => [['text' => 'x']]], $faits]));
        $this->assertSame('blocs[0].items[0]', $this->invalidPath([['type' => 'liste', 'items' => [['text' => '  ']]]]), 'liste vide');
        $this->assertSame('blocs[0].title', $this->invalidPath([['type' => 'intro', 'title' => str_repeat('a', 201)]]));
        $this->assertSame('blocs[0].count', $this->invalidPath([['type' => 'articles', 'count' => '13']]));
        $this->assertSame('blocs[0].count', $this->invalidPath([['type' => 'evenements', 'count' => '0']]));
        $this->assertSame('blocs[0].form_key', $this->invalidPath([['type' => 'formulaire', 'form_key' => 'paiement']]));
        $this->assertSame('blocs[0].level', $this->invalidPath([['type' => 'titre', 'text' => 'A', 'level' => '4']]));
        $this->assertSame('blocs[0].media_id', $this->invalidPath([['type' => 'image', 'media_id' => 'abc']]));
        $this->assertSame('blocs[0].html', $this->invalidPath([['type' => 'texte', 'html' => '<script>x</script>']]), 'texte vide une fois nettoyé');
        $this->assertSame('blocs', $this->invalidPath(array_fill(0, 61, ['type' => 'articles', 'count' => 3])));
        $this->assertCount(60, Blocks::validate(array_fill(0, 60, ['type' => 'articles', 'count' => 3])));
    }

    public function test_normalised_shapes(): void
    {
        $out = Blocks::validate([
            ['type' => 'titre', 'text' => " Nos\tmissions\u{200B} ", 'level' => '3', 'extra' => 'ignoré'],
            ['type' => 'intro', 'title' => 'Bienvenue', 'note' => "Ligne 1\r\nLigne 2\u{0007}"],
            ['type' => 'faits', 'items' => [['label' => 'Marins', 'value' => '800'], ['label' => '', 'value' => '']]],
            ['type' => 'image', 'media_id' => '7', 'caption' => ''],
            ['type' => 'articles', 'count' => '3'],
            ['type' => 'appel', 'title' => 'Adhérer', 'text' => '', 'button_label' => 'Je m\'inscris', 'page_id' => '4', 'url' => ''],
        ]);
        $this->assertSame(['type' => 'titre', 'text' => 'Nos missions', 'level' => 3], $out[0]);
        $this->assertSame("Ligne 1\nLigne 2", $out[1]['note']);
        $this->assertSame([['label' => 'Marins', 'value' => '800']], $out[2]['items'], 'ligne vide ignorée');
        $this->assertSame(['type' => 'image', 'media_id' => 7, 'caption' => ''], $out[3]);
        $this->assertSame(3, $out[4]['count']);
        $this->assertSame(['type' => 'appel', 'title' => 'Adhérer', 'text' => '', 'button_label' => 'Je m\'inscris', 'page_id' => 4, 'url' => null], $out[5]);
        $this->assertSame(['media' => ['blocs[3].media_id' => 7], 'pages' => ['blocs[5].page_id' => 4]], Blocks::references($out));
    }

    public function test_block_html_is_sanitised(): void
    {
        $out = Blocks::validate([['type' => 'texte', 'html' => '<p onclick="x()">Bonjour <script>alert(1)</script><a href="javascript:alert(1)">lien</a></p>']]);
        $this->assertSame('<p>Bonjour lien</p>', $out[0]['html']);
    }

    public function test_call_to_action_target_rules(): void
    {
        $cta = static fn (array $t): array => [['type' => 'appel', 'title' => 'T', 'button_label' => 'B'] + $t];
        foreach (['https://exemple.ci/a?b=1', 'http://exemple.ci', 'mailto:contact@exemple.ci', 'tel:+225 01 02 03', '/contact', '/'] as $ok) {
            $this->assertSame($ok, Blocks::validate($cta(['url' => $ok]))[0]['url']);
        }
        foreach (['javascript:alert(1)', 'JaVaScRiPt:alert(1)', 'data:text/html,x', '//evil.test', '/\\evil.test', 'ftp://x', 'exemple.ci', 'https://', "https://a.ci/\nx"] as $bad) {
            $this->assertSame('blocs[0].url', $this->invalidPath($cta(['url' => $bad])), $bad);
        }
        $this->assertSame('blocs[0].url', $this->invalidPath($cta([])), 'cible obligatoire');
        $this->assertSame('blocs[0].url', $this->invalidPath($cta(['url' => '/a', 'page_id' => '3'])), 'pas les deux');
    }

    public function test_editor_operations_move_delete_add_rows(): void
    {
        $blocks = Blocks::fromRequest([
            ['type' => 'titre', 'text' => 'Un', 'level' => '2'],
            ['type' => 'liste', 'items' => [['text' => 'a'], ['text' => 'b']]],
            ['type' => 'inconnu', 'x' => 'y'],
            ['type' => 'intro', 'title' => 'Trois', 'note' => '<b>non enregistré</b>'],
        ]);
        $this->assertCount(3, $blocks, 'type inconnu écarté du réaffichage');
        [$up] = Blocks::apply($blocks, 'up:2');
        $this->assertSame(['titre', 'intro', 'liste'], array_column($up, 'type'));
        $this->assertSame('<b>non enregistré</b>', $up[1]['note'], 'saisie conservée telle quelle');
        [$down] = Blocks::apply($blocks, 'down:0');
        $this->assertSame(['liste', 'titre', 'intro'], array_column($down, 'type'));
        $this->assertSame($blocks, Blocks::apply($blocks, 'up:0')[0], 'le premier ne monte pas');
        $this->assertSame($blocks, Blocks::apply($blocks, 'down:2')[0]);
        [$del] = Blocks::apply($blocks, 'del:1');
        $this->assertSame(['titre', 'intro'], array_column($del, 'type'));
        [$add, $err] = Blocks::apply($blocks, 'add', 'faits');
        $this->assertNull($err);
        $this->assertSame(['type' => 'faits', 'items' => [['label' => '', 'value' => '']]], $add[3]);
        $this->assertNotNull(Blocks::apply($blocks, 'add', 'script')[1]);
        [$rows] = Blocks::apply($blocks, 'item-add:1');
        $this->assertSame([['text' => 'a'], ['text' => 'b'], ['text' => '']], $rows[1]['items']);
        [$rows] = Blocks::apply($blocks, 'item-del:1:0');
        $this->assertSame([['text' => 'b']], $rows[1]['items']);
        $this->assertSame($blocks, Blocks::apply($blocks, 'item-add:0')[0], 'un titre n\'a pas de lignes');
        $this->assertNotNull(Blocks::apply(array_fill(0, 60, Blocks::blank('articles')), 'add', 'articles')[1], '60 blocs au maximum');
        $this->assertSame('Bloc 2 (Liste), élément 1, Élément', Blocks::describePath('blocs[1].items[0].text', $blocks));
    }

    public function test_media_reference_contract(): void
    {
        // Contrat avec MediaService::usages() : un champ média s'appelle media_id (entier) ou media_ids (liste).
        $found = 0;
        foreach (Blocks::FIELDS as $type => $fields) {
            $this->assertArrayHasKey($type, Blocks::TYPES);
            foreach ($fields as $name => [$kind]) {
                if ($kind === 'media') {
                    $this->assertContains($name, ['media_id', 'media_ids'], "$type.$name");
                    $found++;
                }
            }
        }
        $this->assertGreaterThan(0, $found);
    }
}
