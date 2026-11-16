<?php
declare(strict_types=1);

namespace App\Modules\Cms;

use App\Core\Database;
use DomainException;

final class Slugs
{
    public const RESERVED = ['admin', 'verifier', 'espace', 'assets', 'media', 'sante', 'adhesion-en-ligne', 'sitemap.xml', 'robots.txt', 'favicon.ico'];
    /** Tables à slug unique (liste blanche : le nom est interpolé en SQL). */
    private const TABLES = ['cms_pages', 'cms_articles', 'cms_events', 'cms_categories', 'cms_tags', 'cms_galleries'];
    private const MAP = [
        'à' => 'a', 'â' => 'a', 'ä' => 'a', 'á' => 'a', 'ã' => 'a', 'å' => 'a', 'æ' => 'ae', 'ç' => 'c',
        'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e', 'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i', 'ñ' => 'n',
        'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'ö' => 'o', 'õ' => 'o', 'œ' => 'oe', 'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u',
        'ý' => 'y', 'ÿ' => 'y', 'ß' => 'ss',
    ];

    public function __construct(private Database $db)
    {
    }

    public function validate(string $slug, ?int $exceptId, string $table): string
    {
        if (!in_array($table, self::TABLES, true)) {
            throw new DomainException('Table de contenu inconnue.');
        }
        $slug = trim($slug);
        if (strlen($slug) < 1 || strlen($slug) > 80 || !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug)) {
            throw new DomainException('Adresse invalide : 1 à 80 caractères, minuscules, chiffres et tirets (sans tiret au début, à la fin ni doublé).');
        }
        if (in_array($slug, self::RESERVED, true)) {
            throw new DomainException("L'adresse « $slug » est réservée.");
        }
        $taken = $this->db->fetchValue("SELECT COUNT(*) FROM `$table` WHERE slug = ? AND id <> ?", [$slug, $exceptId ?? 0]);
        if ((int) $taken > 0) {
            throw new DomainException("L'adresse « $slug » est déjà utilisée.");
        }
        return $slug;
    }

    /**
     * Génère un slug valide : translittération sans intl, tout autre caractère devient « - », tirets
     * fusionnés, coupe à 80 sur une frontière de tiret. Vide -> « page » ; réservé -> suffixe « -page ».
     * L'unicité (suffixe numérique) reste à la charge de l'appelant via validate().
     */
    public static function fromTitle(string $title): string
    {
        $s = strtr(mb_strtolower($title, 'UTF-8'), self::MAP);
        $s = trim((string) preg_replace('/[^a-z0-9]+/', '-', $s), '-');
        if (strlen($s) > 80) {
            $cut = substr($s, 0, 80);
            $s = substr($s, 80, 1) === '-' ? $cut : (str_contains($cut, '-') ? substr($cut, 0, (int) strrpos($cut, '-')) : $cut);
        }
        if ($s === '') {
            return 'page';
        }
        return in_array($s, self::RESERVED, true) ? $s . '-page' : $s;
    }
}
