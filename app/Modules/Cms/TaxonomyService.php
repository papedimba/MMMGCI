<?php
declare(strict_types=1);

namespace App\Modules\Cms;

use App\Core\Audit;
use App\Core\Database;
use App\Core\Gate;
use App\Core\NotFound;
use DomainException;
use PDOException;

/** Catégories et étiquettes des articles (même forme : nom + adresse uniques). */
final class TaxonomyService
{
    /** kind => [table, libellé singulier, préfixe d'action du journal] */
    private const KINDS = [
        'categories' => ['cms_categories', 'catégorie', 'category'],
        'etiquettes' => ['cms_tags', 'étiquette', 'tag'],
    ];

    public function __construct(private Database $db, private Audit $audit, private Gate $gate)
    {
    }

    public static function valid(string $kind): bool
    {
        return isset(self::KINDS[$kind]);
    }

    /** @return list<array<string,mixed>> lignes avec le nombre d'articles qui l'utilisent */
    public function all(string $kind): array
    {
        [$table] = self::KINDS[$kind];
        $join = $kind === 'categories' ? 'LEFT JOIN cms_articles a ON a.category_id = t.id' : 'LEFT JOIN cms_article_tag a ON a.tag_id = t.id';
        $count = $kind === 'categories' ? 'a.id' : 'a.article_id';
        return $this->db->fetchAll("SELECT t.id, t.name, t.slug, COUNT($count) AS used FROM `$table` t $join GROUP BY t.id, t.name, t.slug ORDER BY t.name");
    }

    public function create(string $kind, string $name, string $slug, int $actor): int
    {
        return $this->save($kind, null, $name, $slug, $actor, 'cms.' . self::KINDS[$kind][2] . '_created');
    }

    public function update(string $kind, int $id, string $name, string $slug, int $actor): void
    {
        $old = $this->get($kind, $id);
        if (($old['name'] !== trim($name) || $old['slug'] !== trim($slug)) && !$this->gate->allows($actor, 'cms.publish') && $this->usedByLiveArticle($kind, $id)) {
            throw new PageInvalid(['name' => 'Cette ' . self::KINDS[$kind][1] . ' est utilisée par un article en ligne : seul un publicateur peut changer son nom ou son adresse.']);
        }
        $this->save($kind, $id, $name, $slug, $actor, 'cms.' . self::KINDS[$kind][2] . '_updated');
    }

    /** Un article qui utilise le terme a une version en ligne (même règle que la lecture publique). */
    private function usedByLiveArticle(string $kind, int $id): bool
    {
        $ids = $kind === 'categories'
            ? $this->db->fetchAll('SELECT id FROM cms_articles WHERE category_id = ?', [$id])
            : $this->db->fetchAll('SELECT article_id AS id FROM cms_article_tag WHERE tag_id = ?', [$id]);
        $articles = new ArticleService($this->db, $this->audit, $this->gate);
        foreach ($ids as $r) {
            if ($articles->liveVersion((int) $r['id']) !== null) {
                return true;
            }
        }
        return false;
    }

    public function delete(string $kind, int $id, int $actor): void
    {
        [$table, $label, $prefix] = self::KINDS[$kind];
        $this->db->atomic(function () use ($kind, $id, $actor, $table, $label, $prefix): void {
            $row = $this->db->fetch("SELECT id, name, slug FROM `$table` WHERE id = ? FOR UPDATE", [$id]) ?? throw new NotFound('Introuvable.');
            $used = (int) ($kind === 'categories'
                ? $this->db->fetchValue('SELECT COUNT(*) FROM cms_articles WHERE category_id = ?', [$id])
                : $this->db->fetchValue('SELECT COUNT(*) FROM cms_article_tag WHERE tag_id = ?', [$id]));
            if ($used > 0) {
                throw new DomainException(ucfirst($label) . " « {$row['name']} » est utilisée par $used article" . ($used > 1 ? 's' : '') . ' : retirez-la de ces articles d\'abord.');
            }
            try {
                $this->db->query("DELETE FROM `$table` WHERE id = ?", [$id]);
            } catch (PDOException $e) { // affectation concurrente entre le contrôle et la suppression
                if ((int) ($e->errorInfo[1] ?? 0) !== 1451) {
                    throw $e;
                }
                throw new DomainException(ucfirst($label) . " « {$row['name']} » vient d'être utilisée par un article : retirez-la de ces articles d'abord.");
            }
            $this->audit->log($actor, "cms.{$prefix}_deleted", $table, $id, ['slug' => $row['slug']], null);
        });
    }

    /** @return array<string,mixed> */
    public function get(string $kind, int $id): array
    {
        $table = self::KINDS[$kind][0];
        return $this->db->fetch("SELECT id, name, slug FROM `$table` WHERE id = ?", [$id]) ?? throw new NotFound('Introuvable.');
    }

    private function save(string $kind, ?int $id, string $name, string $slug, int $actor, string $action): int
    {
        [$table] = self::KINDS[$kind];
        $name = Blocks::text($name, false);
        $errors = [];
        if ($name === '' || mb_strlen($name) > 120) {
            $errors['name'] = 'Le nom est obligatoire (120 caractères au maximum).';
        } elseif ((int) $this->db->fetchValue("SELECT COUNT(*) FROM `$table` WHERE name = ? AND id <> ?", [$name, $id ?? 0]) > 0) {
            $errors['name'] = "Le nom « $name » est déjà utilisé.";
        }
        $slugs = new Slugs($this->db);
        try {
            $slug = Blocks::text($slug, false) === '' ? $slugs->validate(Slugs::fromTitle($name), $id, $table) : $slugs->validate($slug, $id, $table);
        } catch (DomainException $e) {
            $errors['slug'] = $e->getMessage();
        }
        if ($errors !== []) {
            throw new PageInvalid($errors);
        }
        try {
            $this->db->atomic(function () use ($table, &$id, $name, $slug, $actor, $action): void {
                if ($id === null) {
                    $id = $this->db->insert($table, ['name' => $name, 'slug' => $slug]);
                } else {
                    $this->db->update($table, ['name' => $name, 'slug' => $slug], ['id' => $id]);
                }
                $this->audit->log($actor, $action, $table, $id, null, ['slug' => $slug]);
            });
        } catch (PDOException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
                throw new PageInvalid(['slug' => 'Ce nom ou cette adresse vient d\'être pris : choisissez-en un autre.']);
            }
            throw $e;
        }
        return $id;
    }
}
