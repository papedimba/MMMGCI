<?php
declare(strict_types=1);

namespace App\Modules\Cms;

use DomainException;

/**
 * Blocs d'une page (JSON de cms_pages.blocks / published_blocks) : description, validation, opérations de l'éditeur sans JS.
 * Contrat médias : un bloc ne référence un média que par « media_id » (entier) ou « media_ids » (liste d'entiers) ;
 * le texte riche, par chemin /media/… (MediaService::usages() cherche exactement ces formes).
 */
final class Blocks
{
    public const MAX_BLOCKS = 60;
    public const MAX_ITEMS = 50;
    /** Champs de blocs par envoi : avec la vingtaine de champs de la page, reste sous max_input_vars = 1000 (défaut PHP / cPanel). */
    public const MAX_FIELDS = 800;
    public const TOO_LONG = 'Le formulaire est trop long : enregistrez moins de blocs ou de lignes à la fois.';

    public const TYPES = [
        'texte' => 'Texte', 'titre' => 'Titre de section', 'intro' => 'Introduction', 'faits' => 'Chiffres clés',
        'domaines' => "Domaines d'intervention", 'liste' => 'Liste', 'appel' => "Appel à l'action", 'image' => 'Image',
        'documents' => 'Documents', 'articles' => 'Dernières actualités', 'evenements' => 'Prochains évènements', 'formulaire' => 'Formulaire',
        'dossier' => 'Préparer mon dossier',
    ];

    public const FORMS = ['contact' => 'Contact', 'partenariat' => 'Partenariat', 'adhesion' => 'Adhésion'];

    /**
     * type => champ => [nature, libellé, obligatoire, paramètre] ; nature : line (une ligne), text (plusieurs lignes), html (riche),
     * media (id cms_media), page (id cms_pages), url, int ([min, max]), enum (options), items (sous-champs de chaque élément),
     * key (identifiant : minuscules, chiffres, tirets). Paramètre : longueur maximale pour line/text/url/key.
     */
    public const FIELDS = [
        'texte' => ['html' => ['html', 'Texte', true, null]],
        'titre' => ['text' => ['line', 'Texte du titre', true, 200], 'level' => ['enum', 'Niveau', true, [2 => 'Titre (niveau 2)', 3 => 'Sous-titre (niveau 3)']]],
        'intro' => ['title' => ['line', 'Titre', true, 200], 'note' => ['text', 'Note', false, 1000]],
        'faits' => ['items' => ['items', 'Chiffres', true, ['label' => ['line', 'Libellé', true, 120], 'value' => ['line', 'Valeur', true, 60]]]],
        'domaines' => ['items' => ['items', 'Domaines', true, [
            'name' => ['line', 'Nom', true, 160], 'description' => ['text', 'Description', false, 1000], 'nature' => ['line', 'Nature', false, 120]]]],
        'liste' => ['items' => ['items', 'Éléments', true, ['text' => ['line', 'Élément', true, 300]]]],
        'appel' => ['title' => ['line', 'Titre', true, 200], 'text' => ['text', 'Texte', false, 1000], 'button_label' => ['line', 'Libellé du bouton', true, 80],
            'page_id' => ['page', 'Page du site', false, null], 'url' => ['url', 'Ou adresse', false, 255]],
        'image' => ['media_id' => ['media', 'Image', true, null], 'caption' => ['line', 'Légende', false, 255]],
        'documents' => ['category' => ['line', 'Catégorie (vide = toutes)', false, 80]],
        'articles' => ['count' => ['int', 'Nombre', true, [1, 12]]],
        'evenements' => ['count' => ['int', 'Nombre', true, [1, 12]]],
        'formulaire' => ['form_key' => ['enum', 'Formulaire', true, self::FORMS]],
        // « Préparer mon dossier » : le visiteur coche sa situation, la liste des pièces se filtre (site.js) ; rien n'est envoyé.
        'dossier' => ['title' => ['line', 'Titre', true, 200], 'intro' => ['text', 'Introduction', false, 1000],
            'situations' => ['items', 'Situations', false, ['key' => ['key', 'Clé (minuscules et tirets)', true, 40], 'label' => ['line', 'Libellé', true, 160]]],
            'pieces' => ['items', 'Pièces', true, ['label' => ['line', 'Pièce', true, 300],
                'situations' => ['line', 'Clés des situations concernées, séparées par des virgules (vide : toujours)', false, 400]]]],
    ];

    /**
     * @return list<array<string,mixed>> blocs normalisés
     * @throws BlockInvalid toutes les erreurs dans ->errors (chemin => message), la première en path/message
     */
    public static function validate(array $blocks): array
    {
        if (count($blocks) > self::MAX_BLOCKS) {
            throw new BlockInvalid('blocs', 'Une page compte ' . self::MAX_BLOCKS . ' blocs au maximum.');
        }
        if (self::fieldCount($blocks) > self::MAX_FIELDS) {
            throw new BlockInvalid('blocs', self::TOO_LONG);
        }
        $out = [];
        $errors = [];
        foreach (array_values($blocks) as $i => $block) {
            $path = "blocs[$i]";
            $type = is_array($block) && is_string($block['type'] ?? null) ? $block['type'] : '';
            if (!isset(self::FIELDS[$type])) {
                $errors["$path.type"] = 'Type de bloc inconnu.';
                continue;
            }
            $clean = ['type' => $type];
            foreach (self::FIELDS[$type] as $name => $spec) {
                try {
                    $clean[$name] = self::field($spec, $block[$name] ?? null, "$path.$name", $errors);
                } catch (BlockInvalid $e) {
                    $errors[$e->path] = $e->getMessage();
                    $clean[$name] = null;
                }
            }
            if ($type === 'dossier') {
                self::checkDossier($clean, $path, $errors);
            }
            if ($type === 'appel' && !isset($errors["$path.url"]) && !isset($errors["$path.page_id"]) && ($clean['page_id'] === null) === ($clean['url'] === null)) {
                $errors["$path.url"] = 'Choisissez une page du site ou saisissez une adresse (l\'une ou l\'autre).';
            }
            $out[] = $clean;
        }
        if ($errors !== []) {
            throw new BlockInvalid((string) array_key_first($errors), (string) reset($errors), $errors);
        }
        return $out;
    }

    /**
     * Dossier : clés de situation uniques ; chaque pièce ne cite que des clés déclarées, normalisées en « a,b ».
     * @param array<string,mixed> $clean @param array<string,string> $errors
     */
    private static function checkDossier(array &$clean, string $path, array &$errors): void
    {
        $keys = [];
        foreach ($clean['situations'] ?? [] as $j => $s) {
            $k = $s['key'] ?? null;
            if (!is_string($k)) {
                continue;
            }
            if (isset($keys[$k])) {
                $errors["$path.situations[$j].key"] = 'Clé déjà utilisée.';
            }
            $keys[$k] = true;
        }
        foreach ($clean['pieces'] ?? [] as $j => $p) {
            $cited = array_values(array_unique(array_filter(array_map('trim', explode(',', (string) ($p['situations'] ?? ''))), 'strlen')));
            $unknown = array_diff($cited, array_keys($keys));
            if ($unknown !== []) {
                $errors["$path.pieces[$j].situations"] = 'Situation inconnue : ' . implode(', ', $unknown) . '.';
            }
            $clean['pieces'][$j]['situations'] = implode(',', $cited);
        }
    }

    /**
     * Nombre de champs que le formulaire envoie pour ces blocs (type + champs + lignes × sous-champs), pour rester sous max_input_vars.
     * @param array<mixed> $blocks
     */
    public static function fieldCount(array $blocks): int
    {
        $n = 0;
        foreach ($blocks as $b) {
            $n++;
            $type = is_array($b) && is_string($b['type'] ?? null) ? $b['type'] : '';
            foreach (self::FIELDS[$type] ?? [] as $name => [$kind, , , $param]) {
                $n += $kind === 'items' ? (is_array($b[$name] ?? null) ? count($b[$name]) : 0) * count($param) : 1;
            }
        }
        return $n;
    }

    /**
     * Champ normalisé ; une valeur simple invalide lève BlockInvalid, les erreurs des lignes d'une liste vont dans $errors.
     * @param array{0:string,1:string,2:bool,3:mixed} $spec @param array<string,string> $errors
     */
    private static function field(array $spec, mixed $raw, string $path, array &$errors = []): mixed
    {
        [$kind, , $required, $param] = $spec;
        if ($kind === 'items') {
            $rows = [];
            foreach (is_array($raw) ? array_values($raw) : [] as $j => $row) {
                $row = is_array($row) ? $row : [];
                $strings = array_map(static fn ($k): string => is_scalar($row[$k] ?? null) ? trim((string) $row[$k]) : '', array_keys($param));
                if (implode('', $strings) === '') {
                    continue; // ligne laissée vide : ignorée
                }
                if (count($rows) >= self::MAX_ITEMS) {
                    $errors["{$path}[$j]"] = self::MAX_ITEMS . ' éléments au maximum par bloc.';
                    break;
                }
                $clean = [];
                foreach ($param as $name => $sub) {
                    try {
                        $clean[$name] = self::field($sub, $row[$name] ?? null, "{$path}[$j].$name"); // indice saisi : l'erreur se place sur la bonne ligne du formulaire
                    } catch (BlockInvalid $e) {
                        $errors[$e->path] = $e->getMessage();
                    }
                }
                $rows[] = $clean;
            }
            if ($required && $rows === []) {
                throw new BlockInvalid("{$path}[0]", 'Ajoutez au moins un élément.');
            }
            return $rows;
        }
        if ($raw !== null && !is_scalar($raw)) {
            throw new BlockInvalid($path, 'Valeur invalide.');
        }
        $s = trim((string) $raw);
        if ($kind === 'html') {
            try {
                $html = RichText::fromRequestField($s);
            } catch (DomainException $e) {
                throw new BlockInvalid($path, $e->getMessage());
            }
            if ($required && RichText::toText($html, 1) === '' && !str_contains($html, '<img')) {
                throw new BlockInvalid($path, 'Obligatoire.');
            }
            return $html;
        }
        if ($kind === 'line' || $kind === 'text' || $kind === 'url' || $kind === 'key') {
            $s = self::text($s, $kind === 'text');
        }
        if ($s === '') {
            if ($required) {
                throw new BlockInvalid($path, 'Obligatoire.');
            }
            return $kind === 'line' || $kind === 'text' ? '' : null;
        }
        if (in_array($kind, ['line', 'text', 'url', 'key'], true) && mb_strlen($s) > $param) {
            throw new BlockInvalid($path, "$param caractères au maximum.");
        }
        return match ($kind) {
            'line', 'text' => $s,
            'key' => preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $s) === 1 ? $s : throw new BlockInvalid($path, 'Minuscules, chiffres et tirets seulement.'),
            'url' => self::isSafeUrl($s) ? $s : throw new BlockInvalid($path, 'Adresse refusée (https://…, mailto:, tel: ou chemin du site commençant par /).'),
            'media', 'page' => ctype_digit($s) && (int) $s > 0 && strlen($s) < 19 ? (int) $s : throw new BlockInvalid($path, 'Choix invalide.'),
            'int' => ctype_digit($s) && (int) $s >= $param[0] && (int) $s <= $param[1] ? (int) $s
                : throw new BlockInvalid($path, "Entre {$param[0]} et {$param[1]}."),
            'enum' => self::option($param, $s) ?? throw new BlockInvalid($path, 'Choix invalide.'),
        };
    }

    /** Une clé de $options dont la forme texte vaut $s (clé entière gardée entière). @param array<int|string,string> $options */
    private static function option(array $options, string $s): int|string|null
    {
        foreach (array_keys($options) as $k) {
            if ((string) $k === $s) {
                return $k;
            }
        }
        return null;
    }

    /** Texte saisi : UTF-8 assaini, caractères de contrôle et de format retirés (sauf saut de ligne en multi-ligne). */
    public static function text(string $s, bool $multiline): string
    {
        $s = str_replace(["\r\n", "\r", "\t"], ["\n", "\n", ' '], mb_scrub($s, 'UTF-8'));
        $s = (string) preg_replace('/(?!\n)[\p{Cc}\p{Cf}]/u', '', $multiline ? $s : str_replace("\n", ' ', $s));
        return trim($multiline ? (string) preg_replace("/\n{3,}/", "\n\n", $s) : (string) preg_replace('/ {2,}/', ' ', $s));
    }

    /** http(s) absolu, mailto:, tel: ou chemin du site ; jamais javascript:, data:, ni //hôte. */
    public static function isSafeUrl(string $url): bool
    {
        return preg_match('~^(?:https?://[^\s/?#]+[^\s]*|mailto:[^\s]+|tel:[+0-9 ().-]+|/(?![/\\\\])[^\s]*)$~iD', $url) === 1;
    }

    /** Médias et pages cités, par chemin. @param list<array<string,mixed>> $blocks normalisés @return array{media:array<string,int>,pages:array<string,int>} */
    public static function references(array $blocks): array
    {
        $out = ['media' => [], 'pages' => []];
        foreach ($blocks as $i => $b) {
            foreach (self::FIELDS[$b['type']] ?? [] as $name => [$kind]) {
                if (($kind === 'media' || $kind === 'page') && is_int($b[$name] ?? null)) {
                    $out[$kind === 'media' ? 'media' : 'pages']["blocs[$i].$name"] = $b[$name];
                }
            }
        }
        return $out;
    }

    /**
     * Blocs tels que saisis, pour réafficher le formulaire : types connus, champs connus, valeurs en texte (rien n'est validé ici).
     * @return list<array<string,mixed>>
     */
    public static function fromRequest(mixed $raw): array
    {
        $out = [];
        foreach (is_array($raw) ? array_values($raw) : [] as $block) {
            $type = is_array($block) && is_string($block['type'] ?? null) ? $block['type'] : '';
            if (!isset(self::FIELDS[$type])) {
                continue;
            }
            $b = ['type' => $type];
            foreach (self::FIELDS[$type] as $name => [$kind, , , $param]) {
                if ($kind === 'items') {
                    $b[$name] = [];
                    foreach (is_array($block[$name] ?? null) ? array_values($block[$name]) : [] as $row) {
                        $b[$name][] = array_map(static fn ($k): string => is_array($row) && is_scalar($row[$k] ?? null) ? (string) $row[$k] : '', array_combine(array_keys($param), array_keys($param)));
                    }
                } else {
                    $b[$name] = is_scalar($block[$name] ?? null) ? (string) $block[$name] : '';
                }
            }
            $out[] = $b;
        }
        return $out;
    }

    /** Bloc vide d'un type (les listes commencent avec une ligne). @return array<string,mixed> */
    public static function blank(string $type): array
    {
        $b = ['type' => $type];
        foreach (self::FIELDS[$type] as $name => [$kind, , , $param]) {
            $b[$name] = $kind === 'items' ? [array_fill_keys(array_keys($param), '')] : match ($kind) {
                'int' => (string) $param[0], 'enum' => (string) array_key_first($param), default => '',
            };
        }
        return $b;
    }

    /**
     * Applique une opération de l'éditeur sans JS : up:i, down:i, del:i, add (type $addType), item-add:i[:champ], item-del:i:j[:champ]
     * (champ : liste visée quand le bloc en a plusieurs ; par défaut la première).
     * @param list<array<string,mixed>> $blocks saisis (fromRequest) @return array{0:list<array<string,mixed>>,1:?string} blocs, erreur
     */
    public static function apply(array $blocks, string $op, string $addType = ''): array
    {
        $parts = explode(':', $op);
        $i = isset($parts[1]) && ctype_digit($parts[1]) ? (int) $parts[1] : -1;
        $n = count($blocks);
        switch ($parts[0]) {
            case 'add':
                if (!isset(self::FIELDS[$addType])) {
                    return [$blocks, 'Choisissez le type de bloc à ajouter.'];
                }
                if ($n >= self::MAX_BLOCKS) {
                    return [$blocks, 'Une page compte ' . self::MAX_BLOCKS . ' blocs au maximum.'];
                }
                if (self::fieldCount([...$blocks, self::blank($addType)]) > self::MAX_FIELDS) {
                    return [$blocks, self::TOO_LONG];
                }
                $blocks[] = self::blank($addType);
                return [$blocks, null];
            case 'up':
            case 'down':
                $j = $parts[0] === 'up' ? $i - 1 : $i + 1;
                if ($i >= 0 && $i < $n && $j >= 0 && $j < $n) {
                    [$blocks[$i], $blocks[$j]] = [$blocks[$j], $blocks[$i]];
                    return [$blocks, null];
                }
                return [$blocks, null];
            case 'del':
                if ($i >= 0 && $i < $n) {
                    array_splice($blocks, $i, 1);
                    return [$blocks, null];
                }
                return [$blocks, null];
            case 'item-add':
            case 'item-del':
                $named = $parts[0] === 'item-add' ? ($parts[2] ?? null) : ($parts[3] ?? null);
                $field = $i >= 0 && $i < $n ? self::itemsField((string) $blocks[$i]['type'], $named) : null;
                if ($field === null) {
                    return [$blocks, null];
                }
                if ($parts[0] === 'item-add') {
                    $row = array_fill_keys(array_keys(self::FIELDS[$blocks[$i]['type']][$field][3]), '');
                    if (count($blocks[$i][$field]) >= self::MAX_ITEMS || self::fieldCount($blocks) + count($row) > self::MAX_FIELDS) {
                        return [$blocks, count($blocks[$i][$field]) >= self::MAX_ITEMS ? self::MAX_ITEMS . ' éléments au maximum par bloc.' : self::TOO_LONG];
                    }
                    $blocks[$i][$field][] = $row;
                } elseif (isset($parts[2]) && ctype_digit($parts[2]) && isset($blocks[$i][$field][(int) $parts[2]])) {
                    array_splice($blocks[$i][$field], (int) $parts[2], 1);
                }
                return [$blocks, null];
        }
        return [$blocks, null];
    }

    /** Liste (champ « items ») d'un type : celle nommée, sinon la première. */
    public static function itemsField(string $type, ?string $name = null): ?string
    {
        foreach (self::FIELDS[$type] ?? [] as $field => [$kind]) {
            if ($kind === 'items' && ($name === null || $name === $field)) {
                return $field;
            }
        }
        return null;
    }

    /** « blocs[2].items[1].label » -> « Bloc 3 (Chiffres clés), élément 2, Libellé ». @param list<array<string,mixed>> $blocks */
    public static function describePath(string $path, array $blocks): string
    {
        if (preg_match('/^blocs\[(\d+)\](?:\.(\w+))?(?:\[(\d+)\](?:\.(\w+))?)?$/', $path, $m) !== 1) {
            return $path === 'blocs' ? 'Blocs' : $path;
        }
        $type = (string) ($blocks[(int) $m[1]]['type'] ?? '');
        $out = 'Bloc ' . ((int) $m[1] + 1) . (isset(self::TYPES[$type]) ? ' (' . self::TYPES[$type] . ')' : '');
        $spec = self::FIELDS[$type][$m[2] ?? ''] ?? null;
        if (isset($m[3]) && $m[3] !== '') {
            $out .= ', élément ' . ((int) $m[3] + 1);
            $sub = $spec[3][$m[4] ?? ''] ?? null;
            return $out . ($sub !== null ? ', ' . $sub[1] : '');
        }
        return $out . ($spec !== null ? ', ' . $spec[1] : '');
    }
}
