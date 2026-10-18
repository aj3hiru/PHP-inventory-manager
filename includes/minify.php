<?php

function minifyHTML($html) {

    $protected = [];
    $html = preg_replace_callback(
        '/<(pre|textarea)\b[^>]*>[\s\S]*?<\/\1>/i',
        function($m) use (&$protected) {
            $key = '%%PROTECTED_' . count($protected) . '%%';
            $protected[$key] = $m[0];
            return $key;
        },
        $html
    );

    $html = preg_replace_callback(
        '/<style\b([^>]*)>([\s\S]*?)<\/style>/i',
        function($m) {
            return '<style' . $m[1] . '>' . minifyCSS($m[2]) . '</style>';
        },
        $html
    );

    $html = preg_replace_callback(
        '/<script\b([^>]*)>([\s\S]*?)<\/script>/i',
        function($m) {
            $attrs = $m[1];
            $isNonJS = preg_match(
                '/type\s*=\s*["\'](?!text\/javascript|application\/javascript)[^"\']*["\']/i',
                $attrs
            );
            if ($isNonJS) return $m[0];
            return '<script' . $attrs . '>' . minifyJS($m[2]) . '</script>';
        },
        $html
    );

    $html = preg_replace('/<!--(?!\[if\s)[\s\S]*?-->/i', '', $html);
    $html = preg_replace('/>\s+</s', '><', $html);
    $html = preg_replace('/\s{2,}/', ' ', $html);

    foreach ($protected as $key => $value) {
        $html = str_replace($key, $value, $html);
    }

    return trim($html);
}


function minifyCSS($css) {
    $css = preg_replace('/\/\*[\s\S]*?\*\//', '', $css);
    $css = preg_replace('/\s+/', ' ', $css);
    $css = preg_replace('/\s*([{};:,>~+])\s*/', '$1', $css);
    $css = str_replace(';}', '}', $css);
    return trim($css);
}


function minifyJS($js) {
    if (trim($js) === '') return '';

    $js = preg_replace('/(?<![:"\'\/])\/\/(?!\*).*$/m', '', $js);
    $js = preg_replace('/\/\*(?!!)[\\s\\S]*?\*\//', '', $js);

    $lines = array_map('trim', explode("\n", $js));
    $lines = array_filter($lines, function($l) { return $l !== ''; });
    $js = implode("\n", array_map(function($l) {
        return preg_replace('/[ \t]+/', ' ', $l);
    }, $lines));

    return trim($js);
}

ob_start("minifyHTML");