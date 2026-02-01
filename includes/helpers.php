<?php
if (!defined('ABSPATH')) { exit; }

final class WPDrive_Helpers {

    public static function normalize_rel_path($p) {
        $p = (string)$p;
        $p = str_replace("\\", "/", $p);
        $p = trim($p);
        $p = ltrim($p, "/");
        $p = preg_replace('#/+#', '/', $p);

        if ($p === '' || $p === '.') { return ''; }

        if (preg_match('/[\x00-\x1F\x7F]/', $p)) { return ''; }

        $parts = explode('/', $p);
        foreach ($parts as $seg) {
            if ($seg === '' || $seg === '.' || $seg === '..') {
                return '';
            }
        }

        if (strlen($p) > 2048) { return ''; }
        return $p;
    }

    public static function is_path_within($candidate, $base_dir) {
        $base_dir = wp_normalize_path($base_dir);
        $candidate = wp_normalize_path($candidate);
        $base_prefix = rtrim($base_dir, '/') . '/';
        return (strpos($candidate, $base_prefix) === 0) || ($candidate === rtrim($base_dir, '/'));
    }

    public static function join_private_path($base_dir, $rel_path) {
        $base_dir = rtrim($base_dir, DIRECTORY_SEPARATOR);
        $rel_path = str_replace('/', DIRECTORY_SEPARATOR, $rel_path);
        $full = $base_dir . DIRECTORY_SEPARATOR . $rel_path;
        $full = wp_normalize_path($full);

        $base_norm = wp_normalize_path($base_dir);
        if (!self::is_path_within($full, $base_norm)) {
            return '';
        }
        return $full;
    }

    public static function get_storage_base() {
        $opt = get_option('wpdrive_storage_base', '');
        $opt = is_string($opt) ? trim($opt) : '';
        if ($opt !== '') { return $opt; }

        return wp_normalize_path(dirname(ABSPATH) . '/wpdrive-data');
    }

    public static function ensure_storage_dirs() {
        $base = self::get_storage_base();
        if ($base === '') { return new WP_Error('wpdrive_storage', 'Storage base is empty'); }
        $base = wp_normalize_path($base);

        if (!file_exists($base)) { @wp_mkdir_p($base); }

        if (!is_dir($base) || !is_writable($base)) {
            // shared host fallback
            $base = wp_normalize_path(WP_CONTENT_DIR . '/wpdrive-private');
            if (!file_exists($base)) { @wp_mkdir_p($base); }
            if (!is_dir($base) || !is_writable($base)) {
                return new WP_Error('wpdrive_storage', 'Storage base is not writable. Configure wpdrive_storage_base in settings.');
            }
            update_option('wpdrive_storage_base', $base, false);
        }

        $files = $base . '/files';
        $tmp   = $base . '/_tmp';

        @wp_mkdir_p($files);
        @wp_mkdir_p($tmp);

        self::best_effort_deny_direct_access($base);

        return array('base' => $base, 'files' => $files, 'tmp' => $tmp);
    }

    public static function best_effort_deny_direct_access($dir) {
        $ht = rtrim($dir, '/') . '/.htaccess';
        if (!file_exists($ht)) { @file_put_contents($ht, "Deny from all\n"); }
        $idx = rtrim($dir, '/') . '/index.html';
        if (!file_exists($idx)) { @file_put_contents($idx, "<!-- WPDrive private -->\n"); }
    }

    public static function crc32_of_file($path) {
        $hex = @hash_file('crc32b', $path);
        if ($hex === false) { return null; }
        $val = hexdec($hex);
        return sprintf('%u', $val);
    }

    public static function make_conflict_path($rel_path, $device_label) {
        $device_label = preg_replace('/[^A-Za-z0-9 _.-]/', '_', (string)$device_label);
        $device_label = trim($device_label);
        if ($device_label === '') { $device_label = 'device'; }

        $ts = gmdate('Y-m-d_H-i-s');
        $info = "conflict from {$device_label} {$ts}";

        $dot = strrpos($rel_path, '.');
        if ($dot === false) {
            return $rel_path . " ({$info})";
        }
        $base = substr($rel_path, 0, $dot);
        $ext  = substr($rel_path, $dot);
        return $base . " ({$info})" . $ext;
    }

    public static function json_response($data, $status = 200) {
        return new WP_REST_Response($data, $status);
    }
}
