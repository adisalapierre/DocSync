<?php
namespace MasCamp\GoogleDocsSync;
if (!defined('ABSPATH')) exit;

/**
 * Small, dependency-free HTML-to-Google-Docs request converter.
 * Preserves paragraphs, headings, bold/italic/underline, links and common lists.
 * Image URIs are inserted after text at calculated positions; unsupported HTML is reduced to readable text.
 */
final class Converter {
    public static function convert($title,$html,$source_url,$featured_url='') {
        $blocks=[]; $images=[];
        if ($featured_url) $blocks[]=['type'=>'image','url'=>esc_url_raw($featured_url)];
        $blocks[]=['type'=>'heading','text'=>$title,'level'=>1];
        $blocks[]=['type'=>'paragraph','text'=>'Source: '.$source_url,'link'=>$source_url];
        if (class_exists('\DOMDocument')) {
            $dom=new \DOMDocument('1.0','UTF-8');
            $prev=libxml_use_internal_errors(true);
            $dom->loadHTML('<?xml encoding="utf-8" ?><div id="mc-root">'.$html.'</div>',LIBXML_HTML_NOIMPLIED|LIBXML_HTML_NODEFDTD);
            libxml_clear_errors(); libxml_use_internal_errors($prev);
            $root=$dom->getElementById('mc-root');
            if ($root) self::walk($root,$blocks);
        } else {
            $plain=wp_strip_all_tags(str_replace(['</p>','</div>','<br>','<br/>','<br />'],["\n\n","\n\n","\n","\n","\n"],$html));
            foreach (preg_split('/\n{2,}/',trim($plain)) as $p) if (trim($p)!=='') $blocks[]=['type'=>'paragraph','text'=>trim($p)];
        }
        $text=''; $styles=[]; $paragraphs=[];
        foreach ($blocks as $b) {
            if ($b['type']==='image') {
                // Placeholder paragraph is replaced by inline image using the same insertion index.
                $text.="\n";
                $images[]=['index'=>strlen($text)+1,'url'=>$b['url']];
                continue;
            }
            $start=1+mb_strlen($text,'UTF-8');
            $line=$b['text'] ?? '';
            $text.=$line."\n";
            $end=1+mb_strlen($text,'UTF-8');
            if ($b['type']==='heading') $paragraphs[]=['start'=>$start,'end'=>$end,'type'=>'HEADING_'.min(6,max(1,(int)($b['level'] ?? 1)))];
            elseif ($b['type']==='list') $paragraphs[]=['start'=>$start,'end'=>$end,'type'=>'NORMAL_TEXT'];
            if (!empty($b['bold']) || !empty($b['italic']) || !empty($b['underline'])) {
                $style=[];
                if (!empty($b['bold'])) $style['bold']=true;
                if (!empty($b['italic'])) $style['italic']=true;
                if (!empty($b['underline'])) $style['underline']=true;
                $styles[]=['start'=>$start,'end'=>$end-1,'style'=>$style];
            }
            if (!empty($b['link'])) $styles[]=['start'=>$start,'end'=>$end-1,'style'=>['link'=>['url'=>$b['link']]]];
        }
        return ['text'=>$text,'styles'=>$styles,'paragraphs'=>$paragraphs,'images'=>$images];
    }
    private static function walk($node,&$blocks,$state=[]) {
        foreach ($node->childNodes as $child) {
            if ($child->nodeType===XML_TEXT_NODE) {
                $t=preg_replace('/\s+/u',' ',trim($child->nodeValue));
                if ($t!=='') {
                    $last=count($blocks)-1;
                    if ($last>=0 && in_array($blocks[$last]['type'],['paragraph','heading','list'],true)) {
                        $blocks[$last]['text'].=($blocks[$last]['text']!==''?' ':'').$t;
                        foreach (['bold','italic','underline','link'] as $k) if (!empty($state[$k])) $blocks[$last][$k]=$state[$k];
                    } else $blocks[]=['type'=>'paragraph','text'=>$t]+$state;
                }
                continue;
            }
            if ($child->nodeType!==XML_ELEMENT_NODE) continue;
            $tag=strtolower($child->nodeName);
            if ($tag==='img') {
                $src=$child->getAttribute('src');
                if ($src && wp_http_validate_url($src)) $blocks[]=['type'=>'image','url'=>esc_url_raw($src)];
                $alt=trim($child->getAttribute('alt')); if ($alt) $blocks[]=['type'=>'paragraph','text'=>$alt];
                continue;
            }
            if ($tag==='br') { $blocks[]=['type'=>'paragraph','text'=>'']; continue; }
            $next=$state;
            if (in_array($tag,['strong','b'],true)) $next['bold']=true;
            if (in_array($tag,['em','i'],true)) $next['italic']=true;
            if ($tag==='u') $next['underline']=true;
            if ($tag==='a' && wp_http_validate_url($child->getAttribute('href'))) $next['link']=esc_url_raw($child->getAttribute('href'));
            $isBlock=in_array($tag,['p','div','section','article','blockquote','li','h1','h2','h3','h4','h5','h6'],true);
            $before=count($blocks);
            self::walk($child,$blocks,$next);
            if ($isBlock && count($blocks)===$before) $blocks[]=['type'=>'paragraph','text'=>''];
            if ($isBlock && count($blocks)>$before) {
                $idx=count($blocks)-1;
                if (preg_match('/^h([1-6])$/',$tag,$m)) { $blocks[$idx]['type']='heading'; $blocks[$idx]['level']=(int)$m[1]; }
                elseif ($tag==='li') $blocks[$idx]['type']='list';
            }
        }
    }
}
