<?php
declare(strict_types=1);

namespace App\Modules\Cms;

use App\Core\HtmlSanitizer;
use DOMDocument;
use DOMElement;
use DOMNode;
use DomainException;

/**
 * Contenu riche du site public : HtmlSanitizer::clean (liste blanche générale), puis seconde reconstruction
 * dans un document neuf avec les règles du CMS (titres h2–h4, liens http(s)/mailto/tel/relatifs, images /media/ avec alt).
 */
final class RichText
{
    private const ELEMENTS = ['h2', 'h3', 'h4', 'p', 'strong', 'em', 'ul', 'ol', 'li', 'blockquote', 'br', 'a', 'img'];
    private const RENAME = ['h1' => 'h2', 'h5' => 'h4', 'h6' => 'h4', 'b' => 'strong', 'i' => 'em'];
    // Segments commençant par [A-Za-z0-9_-] : ni « . », ni « .. », ni fichier caché ; pas de requête ni d'encodage.
    private const MEDIA_SRC = '~^/media/(?:[A-Za-z0-9_-][A-Za-z0-9_.-]*/)*[A-Za-z0-9_-][A-Za-z0-9_.-]*\.(?:jpg|jpeg|png|webp)$~D';

    public static function sanitize(string $html): string
    {
        $body = HtmlSanitizer::parse(HtmlSanitizer::clean(str_replace("\0", ' ', $html), self::RENAME)); // NUL → espace : libxml tronquerait l'attribut
        if ($body === null) {
            return '';
        }
        $out = new DOMDocument('1.0', 'UTF-8');
        $root = $out->createElement('div');
        $out->appendChild($root);
        self::copy($body, $root, $out);
        return self::serialize($root);
    }

    /** Sérialisation canonique : attributs toujours entre guillemets doubles, « " ' & < > » échappés (libxml choisirait parfois des apostrophes). */
    private static function serialize(DOMNode $node): string
    {
        $html = '';
        foreach ($node->childNodes as $child) {
            if (!$child instanceof DOMElement) {
                $html .= htmlspecialchars($child->nodeValue ?? '', ENT_NOQUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
                continue;
            }
            $html .= '<' . $child->nodeName;
            foreach ($child->attributes ?? [] as $attr) {
                $html .= ' ' . $attr->nodeName . '="' . htmlspecialchars($attr->nodeValue ?? '', ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8') . '"';
            }
            $html .= '>';
            if ($child->nodeName !== 'br' && $child->nodeName !== 'img') {
                $html .= self::serialize($child) . '</' . $child->nodeName . '>';
            }
        }
        return $html;
    }

    /** Texte brut (extraits, SEO) : blocs séparés, espaces réduits, coupe à un mot avec « … » seulement si tronqué. */
    public static function toText(string $html, int $max): string
    {
        $html = preg_replace('~<(?:br|/p|/h[2-4]|/li|/blockquote)\b[^>]*>~i', '$0 ', self::sanitize($html)) ?? '';
        $text = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');
        if ($max < 1) {
            return '';
        }
        if (mb_strlen($text) <= $max) {
            return $text;
        }
        $cut = mb_substr($text, 0, $max - 1);
        $space = mb_strrpos($cut, ' ');
        if ($space !== false && $space > 0) {
            $cut = mb_substr($cut, 0, $space);
        }
        return rtrim($cut) . '…';
    }

    /** Champ de formulaire : non-chaîne → '', taille bornée (DomainException), nettoyé. */
    public static function fromRequestField(mixed $raw, int $maxBytes = 200000): string
    {
        if (!is_string($raw)) {
            return '';
        }
        $raw = trim($raw);
        if (strlen($raw) > $maxBytes) {
            throw new DomainException('Le contenu est trop long (' . intdiv($maxBytes, 1000) . ' Ko au maximum).');
        }
        return self::sanitize($raw);
    }

    private static function copy(DOMNode $from, DOMNode $to, DOMDocument $out): void
    {
        foreach (iterator_to_array($from->childNodes) as $node) {
            if ($node->nodeType === XML_TEXT_NODE) {
                $to->appendChild($out->createTextNode($node->nodeValue ?? ''));
                continue;
            }
            if (!$node instanceof DOMElement) {
                continue;
            }
            $name = strtolower($node->nodeName);
            $copy = in_array($name, self::ELEMENTS, true) ? $out->createElement($name) : null;
            if ($copy !== null && $name === 'a') {
                $href = $node->getAttribute('href');
                if (preg_match('~^(?:https?:|mailto:|tel:|/(?![/\\\\]))~i', $href) !== 1 || preg_match('/[\x00-\x20]/', $href) === 1) {
                    $copy = null; // lien refusé : le texte reste
                } else {
                    $copy->setAttribute('href', $href);
                    if (preg_match('~^https?:~i', $href) === 1) { // hôte du site inconnu de façon fiable : tout lien absolu est externe
                        $copy->setAttribute('rel', 'noopener noreferrer');
                    }
                }
            } elseif ($copy !== null && $name === 'img') {
                $src = $node->getAttribute('src');
                $alt = trim($node->getAttribute('alt'));
                if (preg_match(self::MEDIA_SRC, $src) !== 1 || str_contains($src, '..') || $alt === '') {
                    continue;
                }
                $copy->setAttribute('src', $src);
                $copy->setAttribute('alt', $alt);
                foreach (['width', 'height'] as $dim) {
                    $v = $node->getAttribute($dim);
                    if (preg_match('/^[1-9]\d{0,3}$/', $v) === 1 && (int) $v <= 4000) {
                        $copy->setAttribute($dim, $v);
                    }
                }
                $to->appendChild($copy);
                continue;
            }
            if ($copy === null) { // hors liste du CMS (tableaux…) : contenu conservé
                self::copy($node, $to, $out);
                continue;
            }
            self::copy($node, $copy, $out);
            $to->appendChild($copy);
        }
    }
}
