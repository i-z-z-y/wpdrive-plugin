<?php
if (!defined('ABSPATH')) { exit; }

final class WPDrive_REST {

    public static function init() {
        add_action('rest_api_init', function () {
            register_rest_route('wpdrive/v1', '/changes', array(
                'methods' => 'GET',
                'callback' => array(__CLASS__, 'changes'),
                'permission_callback' => array(__CLASS__, 'perm_sync'),
            ));

            register_rest_route('wpdrive/v1', '/upload/init', array(
                'methods' => 'POST',
                'callback' => array(__CLASS__, 'upload_init'),
                'permission_callback' => array(__CLASS__, 'perm_sync'),
            ));

            register_rest_route('wpdrive/v1', '/upload/chunk', array(
                'methods' => 'POST',
                'callback' => array(__CLASS__, 'upload_chunk'),
                'permission_callback' => array(__CLASS__, 'perm_sync'),
            ));

            register_rest_route('wpdrive/v1', '/upload/finalize', array(
                'methods' => 'POST',
                'callback' => array(__CLASS__, 'upload_finalize'),
                'permission_callback' => array(__CLASS__, 'perm_sync'),
            ));

            register_rest_route('wpdrive/v1', '/delete', array(
                'methods' => 'POST',
                'callback' => array(__CLASS__, 'delete_path'),
                'permission_callback' => array(__CLASS__, 'perm_sync'),
            ));

            register_rest_route('wpdrive/v1', '/list', array(
                'methods' => 'GET',
                'callback' => array(__CLASS__, 'list_path'),
                'permission_callback' => array(__CLASS__, 'perm_browse_or_share'),
            ));

            register_rest_route('wpdrive/v1', '/download', array(
                'methods' => 'GET',
                'callback' => array('WPDrive_Download', 'rest_download'),
                'permission_callback' => array(__CLASS__, 'perm_download_or_share'),
            ));
        });
    }

    public static function perm_sync($request) {
        return current_user_can('wpdrive_sync');
    }

    public static function perm_browse($request) {
        return current_user_can('wpdrive_browse') || current_user_can('wpdrive_manage') || current_user_can('wpdrive_sync');
    }

    public static function perm_browse_or_share($request) {
        if (self::perm_browse($request)) { return true; }
        $token = $request->get_param('token');
        if ($token) { return WPDrive_Share::is_token_authorized($token); }
        return false;
    }

    public static function perm_download_or_share($request) {
        if (self::perm_browse($request)) { return true; }
        $token = $request->get_param('token');
        if ($token) { return WPDrive_Share::is_token_authorized($token); }
        return false;
    }

    public static function changes($request) {
        $since = (int)$request->get_param('since');
        $limit = (int)$request->get_param('limit');
        if ($limit <= 0) { $limit = 500; }

        $rows = WPDrive_DB::get_changes_since($since, $limit);
        $next = $since;
        foreach ($rows as $r) {
            $cid = (int)$r['change_id'];
            if ($cid > $next) { $next = $cid; }
        }

        return WPDrive_Helpers::json_response(array(
            'changes' => $rows,
            'next_since' => $next,
        ));
    }

    public static function upload_init($request) {
        $params = $request->get_json_params();
        if (!is_array($params)) { $params = array(); }

        $rel_path = WPDrive_Helpers::normalize_rel_path($params['rel_path'] ?? '');
        if ($rel_path === '') { return new WP_Error('wpdrive_path', 'Invalid rel_path', array('status'=>400)); }

        $size = (int)($params['size'] ?? 0);
        $mtime = (int)($params['mtime'] ?? 0);
        $crc32 = isset($params['crc32']) ? (string)$params['crc32'] : null;
        $base_rev = (int)($params['base_rev'] ?? 0);
        $device_id = isset($params['device_id']) ? substr((string)$params['device_id'], 0, 128) : null;
        $device_label = isset($params['device_label']) ? substr((string)$params['device_label'], 0, 64) : 'device';

        if ($size < 0 || $mtime < 0) { return new WP_Error('wpdrive_meta', 'Invalid size/mtime', array('status'=>400)); }

        $dirs = WPDrive_Helpers::ensure_storage_dirs();
        if (is_wp_error($dirs)) { return $dirs; }

        $entry = WPDrive_DB::get_entry($rel_path);
        $decided_path = $rel_path;

        if ($entry && (int)$entry['deleted'] === 0) {
            $server_rev = (int)$entry['rev'];
            if ($base_rev !== $server_rev) {
                $decided_path = WPDrive_Helpers::make_conflict_path($rel_path, $device_label);
            }
        }

        $upload_id = wp_generate_password(32, false, false);
        $tmp_path = wp_normalize_path($dirs['tmp'] . '/' . $upload_id . '.part');

        @wp_mkdir_p($dirs['tmp']);

        WPDrive_DB::create_upload(array(
            'upload_id' => $upload_id,
            'rel_path' => $rel_path,
            'decided_path' => $decided_path,
            'base_rev' => $base_rev,
            'size' => $size,
            'mtime' => $mtime,
            'crc32' => $crc32,
            'received_bytes' => 0,
            'tmp_path' => $tmp_path,
            'device_id' => $device_id,
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'),
        ));

        if (!file_exists($tmp_path)) {
            $h = @fopen($tmp_path, 'wb');
            if ($h === false) { return new WP_Error('wpdrive_tmp', 'Could not create temp file', array('status'=>500)); }
            fclose($h);
        }

        return WPDrive_Helpers::json_response(array(
            'upload_id' => $upload_id,
            'decided_path' => $decided_path,
            'suggested_chunk_size_mb' => 32,
        ));
    }

    public static function upload_chunk($request) {
        $upload_id = (string)$request->get_param('upload_id');
        $offset = (int)$request->get_param('offset');
        if ($upload_id === '' || $offset < 0) { return new WP_Error('wpdrive_chunk', 'Missing upload_id/offset', array('status'=>400)); }

        $u = WPDrive_DB::get_upload($upload_id);
        if (!$u) { return new WP_Error('wpdrive_chunk', 'Unknown upload_id', array('status'=>404)); }

        $tmp_path = $u['tmp_path'];
        $expected = (int)$u['received_bytes'];
        if ($offset > $expected) {
            return new WP_Error('wpdrive_chunk', 'Offset ahead of received bytes', array('status'=>409, 'expected_offset'=>$expected));
        }

        $body = file_get_contents('php://input');
        if ($body === false) { return new WP_Error('wpdrive_chunk', 'Could not read body', array('status'=>400)); }
        $len = strlen($body);
        if ($len <= 0) { return new WP_Error('wpdrive_chunk', 'Empty chunk', array('status'=>400)); }

        if ($offset < $expected) {
            // duplicate retry ignored (client should resume from expected offset)
            return WPDrive_Helpers::json_response(array('ok'=>true, 'received_bytes'=>$expected, 'note'=>'duplicate chunk ignored'));
        }

        $h = @fopen($tmp_path, 'ab');
        if ($h === false) { return new WP_Error('wpdrive_chunk', 'Could not open temp file', array('status'=>500)); }
        $written = @fwrite($h, $body);
        @fclose($h);

        if ($written === false || $written != $len) {
            return new WP_Error('wpdrive_chunk', 'Failed to write chunk', array('status'=>500));
        }

        $new_received = $expected + $len;
        WPDrive_DB::update_upload($upload_id, array(
            'received_bytes' => $new_received,
            'updated_at' => current_time('mysql'),
        ));

        return WPDrive_Helpers::json_response(array('ok'=>true, 'received_bytes'=>$new_received));
    }

    public static function upload_finalize($request) {
        $params = $request->get_json_params();
        if (!is_array($params)) { $params = array(); }
        $upload_id = isset($params['upload_id']) ? (string)$params['upload_id'] : '';
        if ($upload_id === '') { return new WP_Error('wpdrive_finalize', 'Missing upload_id', array('status'=>400)); }

        $u = WPDrive_DB::get_upload($upload_id);
        if (!$u) { return new WP_Error('wpdrive_finalize', 'Unknown upload_id', array('status'=>404)); }

        $dirs = WPDrive_Helpers::ensure_storage_dirs();
        if (is_wp_error($dirs)) { return $dirs; }

        $size = (int)$u['size'];
        $received = (int)$u['received_bytes'];
        if ($received !== $size) {
            return new WP_Error('wpdrive_finalize', 'Upload incomplete', array('status'=>409, 'received_bytes'=>$received, 'expected_bytes'=>$size));
        }

        $tmp_path = $u['tmp_path'];
        if (!file_exists($tmp_path)) { return new WP_Error('wpdrive_finalize', 'Temp file missing', array('status'=>500)); }

        $crc_expected = $u['crc32'];
        $crc_actual = WPDrive_Helpers::crc32_of_file($tmp_path);
        if ($crc_actual === null) { return new WP_Error('wpdrive_finalize', 'CRC32 failed', array('status'=>500)); }
        if ($crc_expected !== null && (string)$crc_expected !== (string)$crc_actual) {
            return new WP_Error('wpdrive_finalize', 'CRC32 mismatch', array('status'=>400, 'expected'=>$crc_expected, 'actual'=>$crc_actual));
        }

        $decided_path = WPDrive_Helpers::normalize_rel_path($u['decided_path']);
        if ($decided_path === '') { return new WP_Error('wpdrive_finalize', 'Invalid decided path', array('status'=>500)); }

        $dest_full = WPDrive_Helpers::join_private_path($dirs['files'], $decided_path);
        if ($dest_full === '') { return new WP_Error('wpdrive_finalize', 'Invalid destination path', array('status'=>500)); }

        @wp_mkdir_p(dirname($dest_full));

        $temp_final = $dest_full . '.tmp.' . wp_generate_password(8, false, false);
        if (!@rename($tmp_path, $temp_final)) {
            if (!@copy($tmp_path, $temp_final)) {
                return new WP_Error('wpdrive_finalize', 'Failed to move into place', array('status'=>500));
            }
            @unlink($tmp_path);
        }

        if (file_exists($dest_full)) { @unlink($dest_full); }
        if (!@rename($temp_final, $dest_full)) {
            return new WP_Error('wpdrive_finalize', 'Failed to finalize destination', array('status'=>500));
        }

        $entry = WPDrive_DB::get_entry($decided_path);
        $new_rev = $entry ? ((int)$entry['rev'] + 1) : 1;

        WPDrive_DB::upsert_entry(array(
            'rel_path' => $decided_path,
            'is_dir' => 0,
            'rev' => $new_rev,
            'size' => $size,
            'mtime' => (int)$u['mtime'],
            'crc32' => (string)$crc_actual,
            'storage_path' => $dest_full,
            'deleted' => 0,
            'deleted_rev' => null,
            'deleted_size' => null,
            'deleted_crc32' => null,
            'updated_at' => current_time('mysql'),
        ));

        $change_id = WPDrive_DB::add_change(array(
            'rel_path' => $decided_path,
            'action' => 'upsert',
            'is_dir' => 0,
            'rev' => $new_rev,
            'size' => $size,
            'mtime' => (int)$u['mtime'],
            'crc32' => (string)$crc_actual,
            'deleted_size' => null,
            'deleted_crc32' => null,
            'device_id' => $u['device_id'],
            'created_at' => current_time('mysql'),
        ));

        WPDrive_DB::delete_upload($upload_id);

        return WPDrive_Helpers::json_response(array(
            'ok' => true,
            'rel_path' => $decided_path,
            'rev' => $new_rev,
            'crc32' => (string)$crc_actual,
            'change_id' => $change_id,
        ));
    }

    public static function delete_path($request) {
        $params = $request->get_json_params();
        if (!is_array($params)) { $params = array(); }
        $rel_path = WPDrive_Helpers::normalize_rel_path($params['rel_path'] ?? '');
        if ($rel_path === '') { return new WP_Error('wpdrive_path', 'Invalid rel_path', array('status'=>400)); }
        $device_id = isset($params['device_id']) ? substr((string)$params['device_id'], 0, 128) : null;

        $entry = WPDrive_DB::get_entry($rel_path);
        if (!$entry || (int)$entry['deleted'] === 1) {
            $change_id = WPDrive_DB::add_change(array(
                'rel_path' => $rel_path,
                'action' => 'delete',
                'is_dir' => 0,
                'rev' => $entry ? ((int)$entry['rev'] + 1) : 1,
                'size' => null,
                'mtime' => null,
                'crc32' => null,
                'deleted_size' => $entry ? $entry['deleted_size'] : null,
                'deleted_crc32' => $entry ? $entry['deleted_crc32'] : null,
                'device_id' => $device_id,
                'created_at' => current_time('mysql'),
            ));
            if (!$entry) {
                WPDrive_DB::upsert_entry(array(
                    'rel_path' => $rel_path,
                    'is_dir' => 0,
                    'rev' => 1,
                    'size' => 0,
                    'mtime' => 0,
                    'crc32' => null,
                    'storage_path' => null,
                    'deleted' => 1,
                    'deleted_rev' => 1,
                    'deleted_size' => null,
                    'deleted_crc32' => null,
                    'updated_at' => current_time('mysql'),
                ));
            }
            return WPDrive_Helpers::json_response(array('ok'=>true, 'already_deleted'=>true, 'change_id'=>$change_id));
        }

        $new_rev = ((int)$entry['rev']) + 1;
        $deleted_size = (int)$entry['size'];
        $deleted_crc32 = $entry['crc32'];

        if (!empty($entry['storage_path']) && file_exists($entry['storage_path'])) { @unlink($entry['storage_path']); }

        WPDrive_DB::upsert_entry(array(
            'rel_path' => $rel_path,
            'is_dir' => 0,
            'rev' => $new_rev,
            'size' => 0,
            'mtime' => 0,
            'crc32' => null,
            'storage_path' => null,
            'deleted' => 1,
            'deleted_rev' => $new_rev,
            'deleted_size' => $deleted_size,
            'deleted_crc32' => $deleted_crc32,
            'updated_at' => current_time('mysql'),
        ));

        $change_id = WPDrive_DB::add_change(array(
            'rel_path' => $rel_path,
            'action' => 'delete',
            'is_dir' => 0,
            'rev' => $new_rev,
            'size' => null,
            'mtime' => null,
            'crc32' => null,
            'deleted_size' => $deleted_size,
            'deleted_crc32' => $deleted_crc32,
            'device_id' => $device_id,
            'created_at' => current_time('mysql'),
        ));

        return WPDrive_Helpers::json_response(array('ok'=>true, 'rel_path'=>$rel_path, 'rev'=>$new_rev, 'change_id'=>$change_id));
    }

    public static function list_path($request) {
        $path = $request->get_param('path');
        $path = $path ? WPDrive_Helpers::normalize_rel_path($path) : '';
        if ($path === false) { $path = ''; }

        $token = $request->get_param('token');
        if ($token) {
            $token = preg_replace('/[^A-Za-z0-9_-]/', '', (string)$token);
            $share = WPDrive_DB::get_share_by_token($token);
            if (!$share) { return new WP_Error('wpdrive_share', 'Invalid token', array('status'=>404)); }
            if (!WPDrive_Share::is_token_authorized($token)) { return new WP_Error('wpdrive_share', 'Unauthorized', array('status'=>403)); }

            $scope = WPDrive_Helpers::normalize_rel_path($share['scope_path']);
            if ($scope === '') { return new WP_Error('wpdrive_share', 'Bad scope', array('status'=>500)); }

            $requested = $path === '' ? $scope : $path;
            if (!WPDrive_Share::path_within_scope($requested, $scope, (int)$share['is_dir'])) {
                return new WP_Error('wpdrive_share', 'Out of scope', array('status'=>403));
            }
            $path = $requested;
        }

        $children = WPDrive_DB::list_children($path);
        return WPDrive_Helpers::json_response(array('path'=>$path, 'children'=>$children));
    }
}
