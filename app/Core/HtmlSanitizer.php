<?php
declare(strict_types=1);

namespace App\Core;

use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * Nettoyage du contenu riche par liste blanche (barrière XSS du CMS).
 * Chaque élément autorisé est reconstruit dans un document neuf : seuls les attributs
 * listés sont copiés (aucun xmlns / attribut à espace de noms ne peut survivre).
 * Limite : libxml tronque au-delà de 256 niveaux d'imbrication (échec fermé, contenu perdu).
 */
final class HtmlSanitizer
{
    private const ALLOWED = [
        'p' => [], 'br' => [], 'h2' => [], 'h3' => [], 'h4' => [], 'strong' => [], 'em' => [],
        'ul' => [], 'ol' => [], 'li' => [], 'blockquote' => [], 'table' => [], 'thead' => [],
        'tbody' => [], 'tr' => [], 'th' => [], 'td' => [],
        'a' => ['href', 'title'],
        'img' => ['src', 'alt', 'width', 'height'],
    ];

    private const DROP_WITH_CONTENT = [
        'script', 'style', 'iframe', 'object', 'embed', 'form', 'template', 'noscript', 'svg', 'math',
        'textarea', 'select', 'button', 'input', 'link', 'meta', 'base',
    ];

    /** @param array<string,string> $rename éléments renommés avant la liste blanche (ex. h1 => h2) */
    public static function clean(string $html, array $rename = []): string
    {
        $body = self::parse($html);
        if ($body === null) {
            return '';
        }
        $out = new DOMDocument('1.0', 'UTF-8');
        $root = $out->createElement('div');
        $out->appendChild($root);
        self::copyChildren($body, $root, $out, $rename);

        $result = '';
        foreach ($root->childNodes as $child) {
            $result .= $out->saveHTML($child);
        }
        return $result;
    }

    /** Analyse en UTF-8 imposé ; renvoie le <body> (null si vide). */
    public static function parse(string $html): ?DOMElement
    {
        if (trim($html) === '') {
            return null;
        }
        // UTF-8 imposé : tout octet non ASCII devient une entité numérique, donc ni <meta charset> ni octet invalide ne change l'encodage
        $html = mb_encode_numericentity(mb_scrub($html, 'UTF-8'), [0x80, 0x10FFFF, 0, 0x1FFFFF], 'UTF-8');

        // un </body> ou </html> parasite ferait ignorer la suite par libxml
        $html = preg_replace('#</\s*(?:body|html)\b[^>]*>#i', '', $html) ?? '';

        $previous = libxml_use_internal_errors(true);
        $in = new DOMDocument();
        $in->loadHTML('<?xml encoding="UTF-8"?><html><body>' . $html, LIBXML_NOERROR | LIBXML_NONET | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $body = $in->getElementsByTagName('body')->item(0);
        return $body instanceof DOMElement ? $body : null;
    }

    /** @param array<string,string> $rename */
    private static function copyChildren(DOMNode $from, DOMNode $to, DOMDocument $out, array $rename): void
    {
        foreach (iterator_to_array($from->childNodes) as $node) {
            if ($node->nodeType === XML_TEXT_NODE) {
                $to->appendChild($out->createTextNode($node->nodeValue ?? ''));
                continue;
            }
            if (!$node instanceof DOMElement) { // commentaires, PI, CDATA...
                continue;
            }
            $name = strtolower($node->nodeName);
            $name = $rename[$name] ?? $name;
            if (in_array($name, self::DROP_WITH_CONTENT, true)) {
                continue;
            }
            if (!isset(self::ALLOWED[$name])) { // élément inconnu : on garde le contenu
                self::copyChildren($node, $to, $out, $rename);
                continue;
            }
            $copy = $out->createElement($name);
            if (!self::copyAttributes($node, $copy, $name)) {
                continue;
            }
            self::copyChildren($node, $copy, $out, $rename);
            $to->appendChild($copy);
        }
    }

    /** @return bool false si l'élément doit être supprimé (img sans src valide). */
    private static function copyAttributes(DOMElement $src, DOMElement $dst, string $name): bool
    {
        foreach (self::ALLOWED[$name] as $attr) {
            if (!$src->hasAttribute($attr)) {
                continue;
            }
            $value = $src->getAttribute($attr);
            if ($attr === 'href' || $attr === 'src') {
                $value = self::safeUrl($value);
            } elseif (($attr === 'width' || $attr === 'height') && !preg_match('/^\d{1,4}$/', $value)) {
                $value = null;
            }
            if ($value !== null) {
                $dst->setAttribute($attr, $value);
            }
        }
        if ($name === 'img' && !$dst->hasAttribute('src')) {
            return false;
        }
        if ($name === 'a' && preg_match('#^https?:#i', $dst->getAttribute('href'))) {
            $dst->setAttribute('rel', 'noopener noreferrer');
        }
        return true;
    }

    /** Valeur déjà décodée par le DOM : pas de second décodage d'entités. */
    private static function safeUrl(string $url): ?string
    {
        $url = preg_replace('/^[\x00-\x20]+|[\x00-\x20]+$|[\t\r\n]+/', '', $url) ?? '';
        if (preg_match('#^(https?|mailto|tel):#i', $url)) {
            return $url;
        }
        // '//' et '/\' sont interprétés comme protocole-relatif par les navigateurs ; '#' = ancre
        return preg_match('#^(/(?![/\\\\])|\#)#', $url) ? $url : null;
    }
}
