<?php
if (!defined('ABSPATH')) { exit; }

final class WPDrive_Download {

    public static function init() {
        add_action('template_redirect', function () {
            if (!isset($_GET['wpdrive_dl'])) { return; }
            $p = base64_decode((string)$_GET['wpdrive_dl'], true);
            if ($p === false) { status_header(400); exit; }
            $path = WPDrive_Helpers::normalize_rel_path($p);
            if ($path === '') { status_header(400); exit; }
            if (!(current_user_can('wpdrive_browse') || current_user_can('wpdrive_manage') || current_user_can('wpdrive_sync'))) {
                status_header(403); exit;
            }
            self::send_file($path);
        });

        add_action('template_redirect', function () {
            if (!isset($_GET['wpdrive_share_dl'])) { return; }
            $token = preg_replace('/[^A-Za-z0-9_-]/', '', (string)$_GET['wpdrive_share_dl']);
            $p = isset($_GET['p']) ? base64_decode((string)$_GET['p'], true) : false;
            if ($p === false) { status_header(400); exit; }
            $path = WPDrive_Helpers::normalize_rel_path($p);
            if ($path === '') { status_header(400); exit; }

            $share = WPDrive_DB::get_share_by_token($token);
            if (!$share) { status_header(404); exit; }
            if (!WPDrive_Share::is_token_authorized($token)) { status_header(403); exit; }

            $scope = WPDrive_Helpers::normalize_rel_path($share['scope_path']);
            if (!WPDrive_Share::path_within_scope($path, $scope, (int)$share['is_dir'])) { status_header(403); exit; }
            self::send_file($path);
        });
    }

    public static function rest_download($request) {
        $path = WPDrive_Helpers::normalize_rel_path($request->get_param('path'));
        if ($path === '') { return new WP_Error('wpdrive_path', 'Invalid path', array('status'=>400)); }

        $token = $request->get_param('token');
        if ($token) {
            $token = preg_replace('/[^A-Za-z0-9_-]/', '', (string)$token);
            $share = WPDrive_DB::get_share_by_token($token);
            if (!$share) { return new WP_Error('wpdrive_share', 'Invalid token', array('status'=>404)); }
            if (!WPDrive_Share::is_token_authorized($token)) { return new WP_Error('wpdrive_share', 'Unauthorized', array('status'=>403)); }
            $scope = WPDrive_Helpers::normalize_rel_path($share['scope_path']);
            if (!WPDrive_Share::path_within_scope($path, $scope, (int)$share['is_dir'])) {
                return new WP_Error('wpdrive_share', 'Out of scope', array('status'=>403));
            }
        }

        self::send_file($path);
        return WPDrive_Helpers::json_response(array('ok'=>true));
    }

    public static function send_file($rel_path) {
        $entry = WPDrive_DB::get_entry($rel_path);
        if (!$entry || (int)$entry['deleted'] === 1) { status_header(404); echo 'Not found'; exit; }
        $full = $entry['storage_path'];
        if (!$full || !file_exists($full)) { status_header(404); echo 'Not found'; exit; }

        $size = filesize($full);
        $start = 0;
        $end = $size - 1;
        $http_range = isset($_SERVER['HTTP_RANGE']) ? $_SERVER['HTTP_RANGE'] : null;

        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . basename($rel_path) . '"');
        header('Accept-Ranges: bytes');
        header('Cache-Control: private, no-store, no-cache, must-revalidate');

        $fp = fopen($full, 'rb');
        if (!$fp) { status_header(500); echo 'Failed to open file'; exit; }

        if ($http_range && preg_match('/bytes=(\d+)-(\d*)/', $http_range, $m)) {
            $start = (int)$m[1];
            $end = ($m[2] !== '') ? (int)$m[2] : $end;
            if ($start > $end || $end >= $size) {
                header('Content-Range: bytes */' . $size);
                status_header(416);
                fclose($fp);
                exit;
            }
            status_header(206);
            header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
        } else {
            status_header(200);
        }

        $length = $end - $start + 1;
        header('Content-Length: ' . $length);

        @set_time_limit(0);
        ignore_user_abort(true);

        fseek($fp, $start);
        $chunk = 2 * 1024 * 1024; // 2MB
        $sent = 0;

        while (!feof($fp) && $sent < $length) {
            $remaining = $length - $sent;
            $read = ($remaining > $chunk) ? $chunk : $remaining;
            $buf = fread($fp, $read);
            if ($buf === false) { break; }
            $out_len = strlen($buf);
            if ($out_len === 0) { break; }
            echo $buf;
            $sent += $out_len;
            @flush();
        }
        fclose($fp);
        exit;
    }
}
