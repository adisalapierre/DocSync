<?php
namespace MasCamp\GoogleDocsSync;
if (!defined('ABSPATH')) exit;

/**
 * Dependency-free HTML → GitHub-flavoured Markdown.
 * Headings, paragraphs, bold/italic/strikethrough, links, images (by URL), nested lists,
 * blockquotes, code, tables and captions. Skips scripts, forms and page-builder duplicates.
 */
final class MarkdownConverter {
    private static $blocks = ['p', 'div', 'section', 'article', 'header', 'footer', 'main', 'aside', 'nav', 'figure', 'address', 'center', 'dl', 'dt', 'dd', 'thead', 'tbody', 'tfoot', 'tr'];
    /** @var string[] fenced code blocks, protected from whitespace clean-up */
    private $code = [];

    public static function convert($html) {
        if (trim((string) $html) === '') return '';
        if (!class_exists('\DOMDocument')) return trim(html_entity_decode(wp_strip_all_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $root = Converter::load_html($html);
        if (!$root) return '';
        $c = new self();
        $md = self::tidy($c->children($root));
        // Restore fenced code blocks.
        return preg_replace_callback('/\x{E000}(\d+)\x{E000}/u', function ($m) use ($c) { return $c->code[(int) $m[1]] ?? ''; }, $md);
    }

    private function children(\DOMNode $node) {
        $out = '';
        foreach ($node->childNodes as $child) $out .= $this->node($child);
        return $out;
    }

    private function node(\DOMNode $n) {
        if ($n->nodeType === XML_TEXT_NODE || $n->nodeType === XML_CDATA_SECTION_NODE) {
            return self::escape(preg_replace('/[\s\x{00A0}]+/u', ' ', $n->nodeValue));
        }
        if ($n->nodeType !== XML_ELEMENT_NODE) return '';
        /** @var \DOMElement $n */
        $tag = strtolower($n->nodeName);
        if (in_array($tag, Converter::$skip, true) || Converter::hidden($n)) return '';

        if (preg_match('/^h([1-6])$/', $tag, $m)) {
            $t = self::one_line($this->children($n));
            return $t === '' ? '' : "\n\n" . str_repeat('#', (int) $m[1]) . ' ' . $t . "\n\n";
        }
        switch ($tag) {
            case 'br': return "\\\n";
            case 'hr': return "\n\n---\n\n";
            case 'strong': case 'b': return self::wrap('**', $this->children($n));
            case 'em': case 'i': case 'cite': return self::wrap('*', $this->children($n));
            case 'del': case 's': case 'strike': return self::wrap('~~', $this->children($n));
            case 'code':
                $t = $n->textContent;
                $tick = strpos($t, '`') !== false ? '``' : '`';
                return $t === '' ? '' : $tick . ($tick === '``' ? ' ' . $t . ' ' : $t) . $tick;
            case 'pre':
                $t = rtrim($n->textContent, "\n");
                $fence = strpos($t, '```') !== false ? '~~~~' : '```';
                $this->code[] = $fence . "\n" . $t . "\n" . $fence;
                return "\n\n\u{E000}" . (count($this->code) - 1) . "\u{E000}\n\n";
            case 'a':
                $text = trim($this->children($n));
                $href = Converter::absolute_url($n->getAttribute('href'));
                if ($text === '') return '';
                return $href ? '[' . $text . '](' . self::url($href) . ')' : $text;
            case 'img':
                if (Converter::is_emoji($n)) return $n->getAttribute('alt');
                $src = Converter::image_src($n);
                if (!$src) return '';
                $alt = str_replace(['[', ']', "\n"], ['\\[', '\\]', ' '], trim($n->getAttribute('alt')));
                return '![' . $alt . '](' . self::url($src) . ')';
            case 'ul': return "\n\n" . $this->lists($n, false) . "\n\n";
            case 'ol': return "\n\n" . $this->lists($n, true, max(1, (int) ($n->getAttribute('start') ?: 1))) . "\n\n";
            case 'blockquote':
                $in = self::tidy($this->children($n));
                return $in === '' ? '' : "\n\n" . preg_replace('/^/m', '> ', $in) . "\n\n";
            case 'table': return "\n\n" . $this->table($n) . "\n\n";
            case 'figcaption':
                $t = self::one_line($this->children($n));
                return $t === '' ? '' : "\n\n*" . $t . "*\n\n";
            case 'td': case 'th': return $this->children($n) . ' ';
        }
        if (in_array($tag, self::$blocks, true)) return "\n\n" . $this->children($n) . "\n\n";
        return $this->children($n); // inline (span, etc.)
    }

    private function lists(\DOMElement $list, $ordered, $start = 1) {
        $items = []; $i = $start;
        foreach ($list->childNodes as $li) {
            if ($li->nodeType !== XML_ELEMENT_NODE) continue;
            $tag = strtolower($li->nodeName);
            if ($tag === 'ul' || $tag === 'ol') { // malformed nesting directly in a list
                $items[] = '  ' . str_replace("\n", "\n  ", $this->lists($li, $tag === 'ol'));
                continue;
            }
            if ($tag !== 'li' || Converter::hidden($li)) continue;
            $content = preg_replace('/\n{2,}/', "\n", self::tidy($this->children($li))); // tight list
            $marker = $ordered ? ($i++) . '. ' : '- ';
            $pad = str_repeat(' ', strlen($marker));
            $lines = explode("\n", $content);
            $item = $marker . array_shift($lines);
            foreach ($lines as $l) $item .= "\n" . ($l === '' ? '' : $pad . $l);
            $items[] = rtrim($item);
        }
        return implode("\n", $items);
    }

    private function table(\DOMElement $table) {
        $rows = [];
        foreach ($table->getElementsByTagName('tr') as $tr) {
            $cells = [];
            foreach ($tr->childNodes as $cell) {
                if ($cell->nodeType !== XML_ELEMENT_NODE || !in_array(strtolower($cell->nodeName), ['td', 'th'], true)) continue;
                $t = self::one_line($this->children($cell));
                $cells[] = str_replace('|', '\\|', $t);
            }
            if ($cells) $rows[] = $cells;
        }
        if (!$rows) return '';
        $cols = max(array_map('count', $rows));
        $line = static function ($cells) use ($cols) { return '| ' . implode(' | ', array_pad($cells, $cols, '')) . ' |'; };
        $out = [$line($rows[0]), '|' . str_repeat(' --- |', $cols)];
        foreach (array_slice($rows, 1) as $r) $out[] = $line($r);
        return implode("\n", $out);
    }

    /* ------------------------------------------------------------ Helpers */

    private static function escape($t) { return preg_replace('/([\\\\`*_\[\]<])/', '\\\\$1', $t); }

    private static function url($u) { return str_replace([' ', '(', ')'], ['%20', '%28', '%29'], $u); }

    /** Wraps text in markers, keeping surrounding spaces outside them (**bold** not ** bold **). */
    private static function wrap($marker, $s) {
        if (trim($s) === '') return $s;
        preg_match('/^(\s*)(.*?)(\s*)$/s', $s, $m);
        return $m[1] . $marker . $m[2] . $marker . $m[3];
    }

    private static function one_line($s) {
        return trim(preg_replace('/\s*\n\s*/', ' ', str_replace("\\\n", ' ', $s)));
    }

    /** Normalises blank lines and stray spaces without disturbing list indentation. */
    private static function tidy($md) {
        $lines = explode("\n", str_replace("\r", '', $md));
        foreach ($lines as &$l) {
            $l = rtrim($l, " \t");
            $l = preg_replace('/^ (?=\S)/', '', $l); // a single stray leading space from inline whitespace
        }
        unset($l);
        $md = implode("\n", $lines);
        $md = preg_replace('/\\\\\n(?=\s*(\n|$))/', "\n", $md); // no hard break at the end of a paragraph
        $md = preg_replace('/\\\\$/', '', rtrim($md));
        $md = preg_replace("/\n{3,}/", "\n\n", $md);
        return trim($md, "\n");
    }
}
