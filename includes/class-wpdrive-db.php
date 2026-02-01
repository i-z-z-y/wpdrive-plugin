<?php
if (!defined('ABSPATH')) { exit; }

final class WPDrive_DB {

    public static function table($name) {
        global $wpdb;
        return $wpdb->prefix . 'wpdrive_' . $name;
    }

    public static function install() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset_collate = $wpdb->get_charset_collate();

        $entries = self::table('entries');
        $changes = self::table('changes');
        $shares  = self::table('shares');
        $uploads = self::table('uploads');

        $sql_entries = "CREATE TABLE $entries (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            rel_path VARCHAR(2048) NOT NULL,
            is_dir TINYINT(1) NOT NULL DEFAULT 0,
            rev BIGINT UNSIGNED NOT NULL DEFAULT 0,
            size BIGINT UNSIGNED NOT NULL DEFAULT 0,
            mtime BIGINT UNSIGNED NOT NULL DEFAULT 0,
            crc32 BIGINT UNSIGNED NULL,
            storage_path VARCHAR(4096) NULL,
            deleted TINYINT(1) NOT NULL DEFAULT 0,
            deleted_rev BIGINT UNSIGNED NULL,
            deleted_size BIGINT UNSIGNED NULL,
            deleted_crc32 BIGINT UNSIGNED NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY rel_path (rel_path)
        ) $charset_collate;";

        $sql_changes = "CREATE TABLE $changes (
            change_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            rel_path VARCHAR(2048) NOT NULL,
            action VARCHAR(32) NOT NULL,
            is_dir TINYINT(1) NOT NULL DEFAULT 0,
            rev BIGINT UNSIGNED NOT NULL DEFAULT 0,
            size BIGINT UNSIGNED NULL,
            mtime BIGINT UNSIGNED NULL,
            crc32 BIGINT UNSIGNED NULL,
            deleted_size BIGINT UNSIGNED NULL,
            deleted_crc32 BIGINT UNSIGNED NULL,
            device_id VARCHAR(128) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (change_id),
            KEY rel_path (rel_path),
            KEY created_at (created_at)
        ) $charset_collate;";

        $sql_shares = "CREATE TABLE $shares (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            token CHAR(48) NOT NULL,
            scope_path VARCHAR(2048) NOT NULL,
            is_dir TINYINT(1) NOT NULL DEFAULT 0,
            password_hash VARCHAR(255) NOT NULL,
            expires_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY token (token)
        ) $charset_collate;";

        $sql_uploads = "CREATE TABLE $uploads (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            upload_id CHAR(48) NOT NULL,
            rel_path VARCHAR(2048) NOT NULL,
            decided_path VARCHAR(2048) NOT NULL,
            base_rev BIGINT UNSIGNED NOT NULL DEFAULT 0,
            size BIGINT UNSIGNED NOT NULL DEFAULT 0,
            mtime BIGINT UNSIGNED NOT NULL DEFAULT 0,
            crc32 BIGINT UNSIGNED NULL,
            received_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
            tmp_path VARCHAR(4096) NOT NULL,
            device_id VARCHAR(128) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY upload_id (upload_id)
        ) $charset_collate;";

        dbDelta($sql_entries);
        dbDelta($sql_changes);
        dbDelta($sql_shares);
        dbDelta($sql_uploads);

        WPDrive_Helpers::ensure_storage_dirs();
    }

    public static function get_entry($rel_path) {
        global $wpdb;
        $table = self::table('entries');
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE rel_path=%s", $rel_path), ARRAY_A);
    }

    public static function upsert_entry($data) {
        global $wpdb;
        $table = self::table('entries');
        $existing = self::get_entry($data['rel_path']);
        if ($existing) {
            $wpdb->update($table, $data, array('rel_path' => $data['rel_path']));
        } else {
            $wpdb->insert($table, $data);
        }
        return true;
    }

    public static function add_change($change) {
        global $wpdb;
        $table = self::table('changes');
        $wpdb->insert($table, $change);
        return (int)$wpdb->insert_id;
    }

    public static function get_changes_since($since_id, $limit = 500) {
        global $wpdb;
        $table = self::table('changes');
        $since_id = (int)$since_id;
        $limit = max(1, min(2000, (int)$limit));
        return $wpdb->get_results(
            $wpdb->prepare("SELECT * FROM $table WHERE change_id > %d ORDER BY change_id ASC LIMIT %d", $since_id, $limit),
            ARRAY_A
        );
    }

    public static function create_upload($row) {
        global $wpdb;
        $table = self::table('uploads');
        $wpdb->insert($table, $row);
        return (int)$wpdb->insert_id;
    }

    public static function get_upload($upload_id) {
        global $wpdb;
        $table = self::table('uploads');
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE upload_id=%s", $upload_id), ARRAY_A);
    }

    public static function update_upload($upload_id, $data) {
        global $wpdb;
        $table = self::table('uploads');
        $wpdb->update($table, $data, array('upload_id' => $upload_id));
        return true;
    }

    public static function delete_upload($upload_id) {
        global $wpdb;
        $table = self::table('uploads');
        $wpdb->delete($table, array('upload_id' => $upload_id));
    }

    public static function create_share($row) {
        global $wpdb;
        $table = self::table('shares');
        $wpdb->insert($table, $row);
        return (int)$wpdb->insert_id;
    }

    public static function get_share_by_token($token) {
        global $wpdb;
        $table = self::table('shares');
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE token=%s", $token), ARRAY_A);
    }

    public static function list_children($dir_path) {
        global $wpdb;
        $table = self::table('entries');

        $dir_path = $dir_path === '' ? '' : rtrim($dir_path, '/') . '/';
        $like = $wpdb->esc_like($dir_path) . '%';

        $rows = $wpdb->get_results(
            $wpdb->prepare("SELECT rel_path, is_dir, rev, size, mtime, crc32 FROM $table WHERE deleted=0 AND rel_path LIKE %s", $like),
            ARRAY_A
        );

        $children = array();
        foreach ($rows as $r) {
            $rel = $r['rel_path'];
            $rest = $dir_path === '' ? $rel : substr($rel, strlen($dir_path));
            if ($rest === '' || $rest === false) { continue; }
            $parts = explode('/', $rest, 2);
            $name = $parts[0];
            $is_dir = (count($parts) > 1);
            $child_path = $dir_path . $name;

            if (!isset($children[$child_path])) {
                $children[$child_path] = array(
                    'name' => $name,
                    'rel_path' => $child_path,
                    'is_dir' => $is_dir ? 1 : (int)$r['is_dir'],
                    'rev' => (int)$r['rev'],
                    'size' => $is_dir ? null : (int)$r['size'],
                    'mtime' => $is_dir ? null : (int)$r['mtime'],
                    'crc32' => $is_dir ? null : (string)$r['crc32'],
                );
            } else {
                if ($is_dir) { $children[$child_path]['is_dir'] = 1; }
            }
        }

        $list = array_values($children);
        usort($list, function($a, $b) {
            if ($a['is_dir'] != $b['is_dir']) { return $a['is_dir'] ? -1 : 1; }
            return strcasecmp($a['name'], $b['name']);
        });

        return $list;
    }
}
