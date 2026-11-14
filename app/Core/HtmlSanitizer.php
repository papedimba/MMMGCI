<?php
declare(strict_types=1);

namespace App\Core;

use DOMDocument;
use DOMElement;
use DOMNode;

/** Nettoyage du contenu riche par liste blanche (barrière XSS du CMS). */
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

    public static function clean(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }
        $previous = libxml_use_internal_errors(true);
        $doc = new DOMDocument();
        $doc->loadHTML(
            '<?xml encoding="UTF-8"?><div>' . $html . '</div>',
            LIBXML_NOERROR | LIBXML_NONET | LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $doc->getElementsByTagName('div')->item(0);
        if ($root === null) {
            return '';
        }
        self::sanitizeChildren($root);

        $out = '';
        foreach ($root->childNodes as $child) {
            $out .= $doc->saveHTML($child);
        }
        return $out;
    }

    private static function sanitizeChildren(DOMNode $parent): void
    {
        foreach (iterator_to_array($parent->childNodes) as $node) {
            if ($node->nodeType === XML_TEXT_NODE) {
                continue;
            }
            if (!$node instanceof DOMElement) { // commentaires, PI, CDATA...
                $parent->removeChild($node);
                continue;
            }
            $name = strtolower($node->nodeName);
            if (in_array($name, self::DROP_WITH_CONTENT, true)) {
                $parent->removeChild($node);
                continue;
            }
            self::sanitizeChildren($node);
            if (!isset(self::ALLOWED[$name])) { // élément inconnu : on garde le contenu
                while ($node->firstChild !== null) {
                    $parent->insertBefore($node->firstChild, $node);
                }
                $parent->removeChild($node);
                continue;
            }
            if (!self::cleanAttributes($node, $name)) {
                $parent->removeChild($node);
            }
        }
    }

    /** @return bool false si l'élément doit être supprimé (img sans src valide). */
    private static function cleanAttributes(DOMElement $el, string $name): bool
    {
        foreach (iterator_to_array($el->attributes) as $attr) {
            $attrName = strtolower($attr->nodeName);
            $value = $attr->value;
            if (!in_array($attrName, self::ALLOWED[$name], true)) {
                $el->removeAttribute($attr->nodeName);
            } elseif ($attrName === 'href' || $attrName === 'src') {
                $safe = self::safeUrl($value);
                $safe === null ? $el->removeAttribute($attr->nodeName) : $el->setAttribute($attrName, $safe);
            } elseif (($attrName === 'width' || $attrName === 'height') && !preg_match('/^\d{1,4}$/', $value)) {
                $el->removeAttribute($attr->nodeName);
            }
        }
        if ($name === 'img' && !$el->hasAttribute('src')) {
            return false;
        }
        if ($name === 'a' && preg_match('#^https?:#i', $el->getAttribute('href'))) {
            $el->setAttribute('rel', 'noopener noreferrer');
        }
        return true;
    }

    private static function safeUrl(string $url): ?string
    {
        $url = preg_replace('/[\x00-\x20\x7f]+/', '', html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '';
        if (preg_match('#^(https?|mailto|tel):#i', $url)) {
            return $url;
        }
        // '//' et '/\' sont interprétés comme protocole-relatif par les navigateurs
        return preg_match('#^/(?![/\\\\])#', $url) ? $url : null;
    }
}
