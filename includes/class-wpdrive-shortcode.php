<?php
if (!defined('ABSPATH')) { exit; }

final class WPDrive_Shortcode {

    public static function init() {
        add_shortcode('wpdrive_browser', array(__CLASS__, 'render'));
    }

    public static function render($atts) {
        if (!(is_user_logged_in() && (current_user_can('wpdrive_browse') || current_user_can('wpdrive_manage') || current_user_can('wpdrive_sync')))) {
            return '<p>You do not have permission to view this.</p>';
        }

        $path = isset($_GET['wpdrive_path']) ? (string)$_GET['wpdrive_path'] : '';
        $path = $path ? WPDrive_Helpers::normalize_rel_path($path) : '';
        if ($path === '') { $path = ''; }

        $children = WPDrive_DB::list_children($path);
        $current_url = remove_query_arg(array('wpdrive_path'));

        $html = '';
        $html .= '<div class="wpdrive-browser" style="font-family:system-ui, sans-serif;">';
        $html .= '<div style="margin:12px 0;">' . self::breadcrumbs($path) . '</div>';
        $html .= '<table style="width:100%;border-collapse:collapse;">';
        $html .= '<thead><tr>';
        $html .= '<th style="text-align:left;border-bottom:1px solid #ddd;padding:8px;">Name</th>';
        $html .= '<th style="text-align:right;border-bottom:1px solid #ddd;padding:8px;">Size</th>';
        $html .= '<th style="text-align:right;border-bottom:1px solid #ddd;padding:8px;">Modified</th>';
        $html .= '<th style="text-align:right;border-bottom:1px solid #ddd;padding:8px;">Action</th>';
        $html .= '</tr></thead><tbody>';

        foreach ($children as $c) {
            $name = esc_html($c['name']);
            $is_dir = (int)$c['is_dir'] === 1;
            $rel = $c['rel_path'];

            $html .= '<tr>';
            $html .= '<td style="padding:8px;border-bottom:1px solid #f0f0f0;">';
            if ($is_dir) {
                $url = esc_url(add_query_arg(array('wpdrive_path' => $rel), $current_url));
                $html .= '<a href="'.$url.'">📁 '.$name.'</a>';
            } else {
                $html .= '📄 '.$name;
            }
            $html .= '</td>';

            $html .= '<td style="padding:8px;border-bottom:1px solid #f0f0f0;text-align:right;">';
            $html .= $is_dir ? '' : esc_html(size_format((int)$c['size']));
            $html .= '</td>';

            $html .= '<td style="padding:8px;border-bottom:1px solid #f0f0f0;text-align:right;">';
            $html .= $is_dir ? '' : esc_html(gmdate('Y-m-d H:i', (int)$c['mtime']));
            $html .= '</td>';

            $html .= '<td style="padding:8px;border-bottom:1px solid #f0f0f0;text-align:right;">';
            if (!$is_dir) {
                $dl = esc_url(add_query_arg(array('wpdrive_dl' => base64_encode($rel)), home_url('/')));
                $html .= '<a href="'.$dl.'">Download</a>';
            }
            $html .= '</td>';
            $html .= '</tr>';
        }

        $html .= '</tbody></table></div>';
        return $html;
    }

    private static function breadcrumbs($path) {
        $path = trim((string)$path, '/');
        if ($path === '') { return '<strong>Root</strong>'; }

        $segs = explode('/', $path);
        $acc = '';
        $out = '<a href="'.esc_url(remove_query_arg(array('wpdrive_path'))).'"><strong>Root</strong></a>';
        foreach ($segs as $s) {
            $acc = $acc === '' ? $s : ($acc . '/' . $s);
            $out .= ' / ' . '<a href="'.esc_url(add_query_arg(array('wpdrive_path' => $acc))).'">'.esc_html($s).'</a>';
        }
        return $out;
    }
}
