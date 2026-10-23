<?php
namespace MasCamp\GoogleDocsSync;
if (!defined('ABSPATH')) exit;

/**
 * Dependency-free HTML → Google Docs converter.
 *
 * Produces plain text plus index-based formatting instructions for the Docs API.
 * All positions are in UTF-16 code units, which is how Google Docs counts characters
 * (curly quotes, accents and emoji would otherwise shift formatting out of place).
 *
 * Handles: title, source link, featured image, paragraphs, headings h1–h6, bold/italic/underline,
 * links, bulleted and numbered lists, images (with figcaptions), simple tables (cells tab-separated).
 */
final class Converter {
    public static $skip    = ['script', 'style', 'noscript', 'iframe', 'svg', 'form', 'button', 'input', 'select', 'textarea', 'video', 'audio', 'object', 'embed', 'template', 'head'];
    private static $blocks = ['p', 'div', 'section', 'article', 'header', 'footer', 'main', 'aside', 'nav', 'figure', 'blockquote', 'pre', 'address', 'table', 'thead', 'tbody', 'tfoot', 'tr', 'dl', 'dt', 'dd', 'center'];
    const MAX_IMAGE_PT = 468; // 6.5in usable page width

    /** @var array[] finished paragraphs */
    private $paras = [];
    /** @var array|null paragraph being built */
    private $current = null;
    /** @var bool insert images as links instead of embedding them */
    private $link_images = false;

    /**
     * @param array $opts ['images' => 'embed'|'link']
     */
    public static function convert($title, $html, $source_url, $featured_url = '', array $opts = []) {
        $c = new self();
        $c->link_images = ($opts['images'] ?? 'embed') === 'link';
        $c->paras[] = ['runs' => [self::run($title, [])], 'type' => 'TITLE', 'list' => null];
        $c->paras[] = ['runs' => [self::run('Source: ', ['italic' => true]), self::run($source_url, ['italic' => true, 'link' => $source_url])], 'type' => 'NORMAL_TEXT', 'list' => null];
        if ($featured_url && ($u = self::absolute_url($featured_url))) $c->add_image($u, self::MAX_IMAGE_PT, 'Featured image');

        if (class_exists('\DOMDocument') && trim($html) !== '') {
            $root = self::load_html($html);
            if ($root) $c->walk($root, []);
            $c->flush();
        } elseif (trim($html) !== '') {
            $plain = html_entity_decode(wp_strip_all_tags(preg_replace('~</(p|div|h[1-6]|li)>|<br\s*/?>~i', "\n\n", $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            foreach (preg_split('/\n{2,}/', $plain) as $p) {
                $p = trim(preg_replace('/\s+/u', ' ', $p));
                if ($p !== '') $c->paras[] = ['runs' => [self::run($p, [])], 'type' => 'NORMAL_TEXT', 'list' => null];
            }
        }
        return $c->build();
    }

    /** Parses an HTML fragment and returns its wrapper element (or null). Shared with the Markdown converter. */
    public static function load_html($html) {
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $prev = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?><div id="mc-root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        return (new \DOMXPath($dom))->query('//div[@id="mc-root"]')->item(0);
    }

    /** Best image URL for an <img>, handling lazy-load attributes. Shared with the Markdown converter. */
    public static function image_src(\DOMElement $el) {
        $src = $el->getAttribute('src');
        if (!$src || strpos($src, 'data:') === 0) $src = $el->getAttribute('data-lazy-src') ?: $el->getAttribute('data-src');
        return self::absolute_url($src);
    }

    /** Length in UTF-16 code units (Google Docs index units). */
    public static function u16len($s) {
        if ($s === '') return 0;
        $chars = preg_match_all('/./us', $s);
        $astral = preg_match_all('/[\x{10000}-\x{10FFFF}]/u', $s);
        return (int) $chars + (int) $astral;
    }

    /* ------------------------------------------------------------ DOM walk */

    private function walk(\DOMNode $node, array $ctx) {
        foreach ($node->childNodes as $child) {
            if ($child->nodeType === XML_TEXT_NODE || $child->nodeType === XML_CDATA_SECTION_NODE) {
                $this->text($child->nodeValue, $ctx);
                continue;
            }
            if ($child->nodeType !== XML_ELEMENT_NODE) continue;
            $tag = strtolower($child->nodeName);
            if (in_array($tag, self::$skip, true)) continue;
            if (self::hidden($child)) continue;

            if ($tag === 'br' || $tag === 'hr') { $this->flush(); continue; }
            if ($tag === 'img') { $this->image($child); continue; }

            $next = $ctx;
            if (in_array($tag, ['strong', 'b', 'th'], true)) $next['bold'] = true;
            if (in_array($tag, ['em', 'i', 'cite', 'figcaption'], true)) $next['italic'] = true;
            if ($tag === 'u' || $tag === 'ins') $next['underline'] = true;
            if ($tag === 'a') {
                $href = self::absolute_url($child->getAttribute('href'));
                if ($href) $next['link'] = $href;
            }

            if (preg_match('/^h([1-6])$/', $tag, $m)) {
                $this->flush();
                $next['heading'] = 'HEADING_' . $m[1];
                $this->walk($child, $next);
                $this->flush();
                continue;
            }
            if ($tag === 'ul' || $tag === 'ol') {
                $this->flush();
                $next['list'] = $tag === 'ol' ? 'NUMBERED' : 'BULLET';
                $this->walk($child, $next);
                $this->flush();
                continue;
            }
            if ($tag === 'li') {
                $this->flush();
                $next['item'] = $ctx['list'] ?? 'BULLET';
                $this->walk($child, $next);
                $this->flush();
                continue;
            }
            if ($tag === 'td' || $tag === 'th') {
                $this->walk($child, $next);
                $this->text("\t", ['raw' => true]);
                continue;
            }
            if (in_array($tag, self::$blocks, true) || $tag === 'figcaption') {
                $this->flush();
                $this->walk($child, $next);
                $this->flush();
                continue;
            }
            $this->walk($child, $next); // inline element (span, a, strong, etc.)
        }
    }

    private function text($value, array $ctx) {
        $t = !empty($ctx['raw']) ? $value : preg_replace('/[\s\x{00A0}]+/u', ' ', $value);
        $t = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', (string) $t);
        if ($t === '' || $t === null) return;
        if ($this->current === null) {
            if (trim($t) === '') return; // don't open a paragraph for stray whitespace
            $this->current = ['runs' => [], 'type' => $ctx['heading'] ?? 'NORMAL_TEXT', 'list' => $ctx['item'] ?? null];
        }
        $run = self::run($t, $ctx);
        $n = count($this->current['runs']);
        if ($n && self::same_format($this->current['runs'][$n - 1], $run)) $this->current['runs'][$n - 1]['text'] .= $t;
        else $this->current['runs'][] = $run;
    }

    private function image(\DOMElement $el) {
        $url = self::image_src($el);
        if (!$url) return;
        $this->flush();
        $w = (int) $el->getAttribute('width');
        $pt = $w > 0 ? min(self::MAX_IMAGE_PT, max(24, (int) round($w * 0.75))) : self::MAX_IMAGE_PT;
        $this->add_image($url, $pt, trim($el->getAttribute('alt')));
    }

    /** Embeds the image, or (link mode) adds a linked "Image: …" line pointing at its URL. */
    private function add_image($url, $pt, $alt = '') {
        if (!$this->link_images) { $this->paras[] = ['image' => $url, 'width' => $pt]; return; }
        $name = $alt !== '' ? $alt : rawurldecode(basename((string) parse_url($url, PHP_URL_PATH)));
        $this->paras[] = ['runs' => [self::run('Image: ', ['italic' => true]), self::run($name ?: $url, ['italic' => true, 'link' => $url])], 'type' => 'NORMAL_TEXT', 'list' => null];
    }

    private function flush() {
        if ($this->current === null) return;
        $p = $this->current;
        $this->current = null;
        $runs = $p['runs'];
        if (!$runs) return;
        $runs[0]['text'] = ltrim($runs[0]['text']);
        $last = count($runs) - 1;
        $runs[$last]['text'] = rtrim($runs[$last]['text']);
        $runs = array_values(array_filter($runs, static function ($r) { return $r['text'] !== ''; }));
        if (!$runs || trim(implode('', array_column($runs, 'text'))) === '') return;
        $p['runs'] = $runs;
        $this->paras[] = $p;
    }

    /* ------------------------------------------------------------ Output */

    private function build() {
        $text = ''; $pos = 1;
        $paragraphs = []; $styles = []; $images = []; $lists = [];
        $open = null; // current list group

        foreach ($this->paras as $p) {
            if (isset($p['image'])) {
                if ($open) { $lists[] = $open; $open = null; }
                $images[] = ['index' => $pos, 'url' => $p['image'], 'width' => $p['width']];
                $text .= "\n"; $pos += 1;
                continue;
            }
            $start = $pos;
            foreach ($p['runs'] as $r) {
                $len = self::u16len($r['text']);
                $style = [];
                if (!empty($r['bold'])) $style['bold'] = true;
                if (!empty($r['italic'])) $style['italic'] = true;
                if (!empty($r['underline'])) $style['underline'] = true;
                if (!empty($r['link'])) $style['link'] = ['url' => $r['link']];
                if ($style && $len > 0) $styles[] = ['start' => $pos, 'end' => $pos + $len, 'style' => $style];
                $text .= $r['text']; $pos += $len;
            }
            $text .= "\n"; $pos += 1;
            $paragraphs[] = ['start' => $start, 'end' => $pos, 'type' => $p['type']];

            $preset = $p['list'] === 'NUMBERED' ? 'NUMBERED_DECIMAL_ALPHA_ROMAN' : ($p['list'] ? 'BULLET_DISC_CIRCLE_SQUARE' : null);
            if ($preset && $open && $open['preset'] === $preset) $open['end'] = $pos;
            else {
                if ($open) $lists[] = $open;
                $open = $preset ? ['start' => $start, 'end' => $pos, 'preset' => $preset] : null;
            }
        }
        if ($open) $lists[] = $open;

        return ['text' => $text, 'end' => $pos, 'paragraphs' => $paragraphs, 'styles' => $styles, 'lists' => $lists, 'images' => $images];
    }

    /* ------------------------------------------------------------ Helpers */

    /**
     * Elements that are invisible on desktop or duplicated by page builders (Elementor & co.):
     * screen-reader text, mobile-only copies, tab titles rendered twice, cloned slider slides.
     */
    private static $hidden_classes = [
        'screen-reader-text', 'sr-only', 'elementor-screen-only', 'elementor-hidden-desktop',
        'elementor-tab-desktop-title', 'swiper-slide-duplicate', 'slick-cloned',
    ];
    public static function hidden(\DOMElement $el) {
        $style = strtolower(str_replace(' ', '', $el->getAttribute('style')));
        if (strpos($style, 'display:none') !== false || $el->hasAttribute('hidden')) return true;
        $class = ' ' . $el->getAttribute('class') . ' ';
        if (trim($class) === '') return false;
        foreach (self::$hidden_classes as $c) if (strpos($class, ' ' . $c . ' ') !== false) return true;
        return false;
    }

    private static function run($text, array $ctx) {
        return ['text' => $text, 'bold' => !empty($ctx['bold']), 'italic' => !empty($ctx['italic']), 'underline' => !empty($ctx['underline']), 'link' => $ctx['link'] ?? null];
    }

    private static function same_format(array $a, array $b) {
        return $a['bold'] === $b['bold'] && $a['italic'] === $b['italic'] && $a['underline'] === $b['underline'] && $a['link'] === $b['link'];
    }

    public static function absolute_url($url) {
        $url = trim(html_entity_decode((string) $url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($url === '' || $url[0] === '#') return '';
        if (strpos($url, '//') === 0) $url = 'https:' . $url;
        elseif ($url[0] === '/') $url = home_url($url);
        if (!preg_match('~^https?://~i', $url)) return '';
        $url = esc_url_raw($url);
        return $url ?: '';
    }
}
