<?php
namespace MasCamp\GoogleDocsSync;
if (!defined('ABSPATH')) exit;

/**
 * Google Docs (Docs API JSON) → WordPress block markup.
 *
 * Paragraphs, headings, bold/italic/underline/strikethrough, links, line breaks, bulleted and
 * numbered lists (nested), images and simple tables. The document title (Title style) is returned
 * separately. When the doc was created by a push, the plugin's header (Source line and featured
 * image) is stripped so it doesn't end up inside the post.
 */
final class DocsImporter {
    /** @var callable fn(array $inlineObject, string $objectId): ?array{id:int,url:string,alt:string} */
    private $resolver;
    private $lists = [];
    private $objects = [];

    /**
     * @param array    $doc      documents.get response
     * @param callable $resolver resolves an inline image to a Media Library attachment
     * @param array    $opts     ['strip_header' => bool, 'pushed_featured' => bool]
     * @return array{title:?string,content:string}
     */
    public static function convert(array $doc, callable $resolver, array $opts = []) {
        $c = new self();
        $c->resolver = $resolver;
        $c->lists = $doc['lists'] ?? [];
        $c->objects = $doc['inlineObjects'] ?? [];

        $items = [];
        foreach ($doc['body']['content'] ?? [] as $el) {
            if (isset($el['paragraph'])) $items[] = $c->para($el['paragraph']);
            elseif (isset($el['table'])) $items[] = ['kind' => 'table', 'table' => $el['table']];
        }
        $n = count($items); $i = 0;
        $skip_empty = static function () use (&$i, $items, $n) {
            while ($i < $n && $items[$i]['kind'] === 'p' && $items[$i]['empty'] && !$items[$i]['images']) $i++;
        };

        // Title paragraph at the top becomes the post title.
        $title = null;
        $skip_empty();
        if ($i < $n && $items[$i]['kind'] === 'p' && $items[$i]['style'] === 'TITLE' && !$items[$i]['empty']) {
            $title = trim(preg_replace('/\s+/u', ' ', $items[$i]['text']));
            $i++;
        }
        // Header written by our own push: "Source: <permalink>" and (optionally) the featured image.
        if (!empty($opts['strip_header'])) {
            $skip_empty();
            if ($i < $n && $items[$i]['kind'] === 'p' && strpos(ltrim($items[$i]['text']), 'Source:') === 0) $i++;
            if (!empty($opts['pushed_featured'])) {
                $skip_empty();
                if ($i < $n && $items[$i]['kind'] === 'p' && (($items[$i]['images'] && $items[$i]['empty']) || strpos(ltrim($items[$i]['text']), 'Image:') === 0 || strpos(ltrim($items[$i]['text']), '[Image:') === 0)) $i++;
            }
        }

        $blocks = [];
        for (; $i < $n; $i++) {
            $it = $items[$i];
            if ($it['kind'] === 'table') { $blocks[] = $c->table_block($it['table']); continue; }
            if ($it['bullet']) {
                $run = []; $list_id = $it['bullet']['listId'] ?? '';
                while ($i < $n && $items[$i]['kind'] === 'p' && $items[$i]['bullet'] && ($items[$i]['bullet']['listId'] ?? '') === $list_id) { $run[] = $items[$i]; $i++; }
                $i--;
                $k = 0;
                $blocks[] = $c->list_block($run, $k, 0, $list_id);
                continue;
            }
            if (!$it['empty']) $blocks[] = $c->text_block($it);
            foreach ($it['images'] as $oid) $blocks[] = $c->image_block($oid);
        }
        return ['title' => $title, 'content' => implode("\n\n", array_filter($blocks))];
    }

    /* ------------------------------------------------------------ Paragraphs */

    private function para(array $p) {
        $html = ''; $text = ''; $images = [];
        foreach ($p['elements'] ?? [] as $e) {
            if (isset($e['textRun'])) {
                $content = (string) ($e['textRun']['content'] ?? '');
                $content = preg_replace('/\n$/', '', $content);
                if ($content === '') continue;
                $text .= $content;
                $html .= self::format($content, $e['textRun']['textStyle'] ?? []);
            } elseif (isset($e['inlineObjectElement'])) {
                $images[] = $e['inlineObjectElement']['inlineObjectId'] ?? '';
            } elseif (isset($e['richLink'])) {
                $rp = $e['richLink']['richLinkProperties'] ?? [];
                $t = (string) ($rp['title'] ?? ($rp['uri'] ?? ''));
                $text .= $t;
                $html .= !empty($rp['uri']) ? '<a href="' . esc_url($rp['uri']) . '">' . esc_html($t) . '</a>' : esc_html($t);
            } elseif (isset($e['person'])) {
                $t = (string) ($e['person']['personProperties']['name'] ?? ($e['person']['personProperties']['email'] ?? ''));
                $text .= $t; $html .= esc_html($t);
            }
        }
        $html = preg_replace('~^(<br>)+|(<br>)+$~', '', trim($html));
        return [
            'kind' => 'p',
            'style' => $p['paragraphStyle']['namedStyleType'] ?? 'NORMAL_TEXT',
            'bullet' => $p['bullet'] ?? null,
            'html' => $html,
            'text' => $text,
            'empty' => trim(str_replace("\x0B", '', $text)) === '',
            'images' => array_values(array_filter($images)),
        ];
    }

    /** One text run → inline HTML. Line breaks (vertical tab) become <br>. */
    private static function format($content, array $style) {
        $h = str_replace("\x0B", '<br>', esc_html($content));
        $url = $style['link']['url'] ?? '';
        if (!empty($style['strikethrough'])) $h = '<s>' . $h . '</s>';
        if (!empty($style['underline']) && !$url) $h = '<u>' . $h . '</u>'; // Docs underlines links automatically
        if (!empty($style['italic'])) $h = '<em>' . $h . '</em>';
        if (!empty($style['bold'])) $h = '<strong>' . $h . '</strong>';
        if ($url) $h = '<a href="' . esc_url($url) . '">' . $h . '</a>';
        return $h;
    }

    private function text_block(array $it) {
        if (preg_match('/^HEADING_([1-6])$/', $it['style'], $m)) {
            $lvl = (int) $m[1];
            $attr = $lvl === 2 ? '' : ' {"level":' . $lvl . '}';
            return "<!-- wp:heading{$attr} -->\n<h{$lvl} class=\"wp-block-heading\">" . $it['html'] . "</h{$lvl}>\n<!-- /wp:heading -->";
        }
        if ($it['style'] === 'TITLE') { // a second Title further down: treat as a main heading
            return "<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">" . $it['html'] . "</h2>\n<!-- /wp:heading -->";
        }
        return "<!-- wp:paragraph -->\n<p>" . $it['html'] . "</p>\n<!-- /wp:paragraph -->";
    }

    /* ------------------------------------------------------------ Lists */

    private function ordered($list_id, $level) {
        $nl = $this->lists[$list_id]['listProperties']['nestingLevels'][$level] ?? [];
        $g = $nl['glyphType'] ?? '';
        return $g !== '' && !in_array($g, ['GLYPH_TYPE_UNSPECIFIED', 'NONE'], true);
    }

    private function list_block(array $run, &$k, $level, $list_id) {
        $lis = [];
        $count = count($run);
        while ($k < $count) {
            $lvl = (int) ($run[$k]['bullet']['nestingLevel'] ?? 0);
            if ($lvl < $level) break;
            if ($lvl > $level) {
                $inner = $this->list_block($run, $k, $level + 1, $list_id);
                if ($lis) $lis[count($lis) - 1]['inner'] .= $inner;
                else $lis[] = ['html' => '', 'inner' => $inner];
                continue;
            }
            $lis[] = ['html' => $run[$k]['html'], 'inner' => ''];
            $k++;
        }
        $ordered = $this->ordered($list_id, $level);
        $tag = $ordered ? 'ol' : 'ul';
        $out = '';
        foreach ($lis as $li) $out .= "<!-- wp:list-item -->\n<li>" . $li['html'] . $li['inner'] . "</li>\n<!-- /wp:list-item -->";
        return '<!-- wp:list' . ($ordered ? ' {"ordered":true}' : '') . " -->\n<{$tag} class=\"wp-block-list\">" . $out . "</{$tag}>\n<!-- /wp:list -->";
    }

    /* ------------------------------------------------------------ Images & tables */

    private function image_block($oid) {
        $obj = $this->objects[$oid] ?? null;
        if (!$obj) return '';
        $img = call_user_func($this->resolver, $obj, $oid);
        if (!$img || empty($img['url'])) return '';
        $alt = esc_attr($img['alt'] ?? '');
        if (!empty($img['id'])) {
            $id = (int) $img['id'];
            return "<!-- wp:image {\"id\":{$id},\"sizeSlug\":\"large\",\"linkDestination\":\"none\"} -->\n<figure class=\"wp-block-image size-large\"><img src=\"" . esc_url($img['url']) . "\" alt=\"{$alt}\" class=\"wp-image-{$id}\"/></figure>\n<!-- /wp:image -->";
        }
        return "<!-- wp:image -->\n<figure class=\"wp-block-image\"><img src=\"" . esc_url($img['url']) . "\" alt=\"{$alt}\"/></figure>\n<!-- /wp:image -->";
    }

    private function table_block(array $table) {
        $rows = '';
        foreach ($table['tableRows'] ?? [] as $row) {
            $cells = '';
            foreach ($row['tableCells'] ?? [] as $cell) {
                $parts = [];
                foreach ($cell['content'] ?? [] as $el) {
                    if (!isset($el['paragraph'])) continue;
                    $p = $this->para($el['paragraph']);
                    if (!$p['empty']) $parts[] = $p['html'];
                }
                $cells .= '<td>' . implode('<br>', $parts) . '</td>';
            }
            $rows .= '<tr>' . $cells . '</tr>';
        }
        if ($rows === '') return '';
        return "<!-- wp:table {\"hasFixedLayout\":false} -->\n<figure class=\"wp-block-table\"><table><tbody>" . $rows . "</tbody></table></figure>\n<!-- /wp:table -->";
    }
}
