<?php
if (!defined('ABSPATH')) { exit; }

final class WPDrive_Share {

    public static function init() {
        add_action('template_redirect', array(__CLASS__, 'handle_share_route'));
    }

    public static function add_rewrite_rules() {
        add_rewrite_rule('^wpdrive-share/([^/]+)/?$', 'index.php?wpdrive_share=$matches[1]', 'top');
    }

    public static function register_query_vars() {
        add_filter('query_vars', function($vars) {
            $vars[] = 'wpdrive_share';
            return $vars;
        });
    }

    public static function cookie_name($token) {
        return 'wpdrive_share_' . $token;
    }

    public static function is_token_authorized($token) {
        $token = preg_replace('/[^A-Za-z0-9_-]/', '', (string)$token);
        if ($token === '') { return false; }
        $cookie = self::cookie_name($token);
        if (empty($_COOKIE[$cookie])) { return false; }

        $val = (string)$_COOKIE[$cookie];
        $parts = explode('|', $val);
        if (count($parts) !== 2) { return false; }
        $exp = (int)$parts[0];
        $sig = (string)$parts[1];
        if ($exp <= 0 || time() > $exp) { return false; }

        $calc = hash_hmac('sha256', $token . '|' . $exp, wp_salt('auth'));
        return hash_equals($calc, $sig);
    }

    public static function set_auth_cookie($token, $ttl_seconds = 28800) {
        $exp = time() + (int)$ttl_seconds;
        $sig = hash_hmac('sha256', $token . '|' . $exp, wp_salt('auth'));
        $val = $exp . '|' . $sig;
        $cookie = self::cookie_name($token);

        setcookie($cookie, $val, array(
            'expires' => $exp,
            'path' => '/',
            'secure' => is_ssl(),
            'httponly' => true,
            'samesite' => 'Lax',
        ));
        $_COOKIE[$cookie] = $val;
    }

    public static function path_within_scope($path, $scope, $scope_is_dir) {
        $path = WPDrive_Helpers::normalize_rel_path($path);
        $scope = WPDrive_Helpers::normalize_rel_path($scope);
        if ($path === '' || $scope === '') { return false; }

        if ($scope_is_dir) {
            $prefix = rtrim($scope, '/') . '/';
            return ($path === $scope) || (strpos($path, $prefix) === 0);
        } else {
            return $path === $scope;
        }
    }

    public static function handle_share_route() {
        $token = get_query_var('wpdrive_share');
        if (!$token) { return; }

        $token = preg_replace('/[^A-Za-z0-9_-]/', '', (string)$token);
        $share = WPDrive_DB::get_share_by_token($token);
        if (!$share) { status_header(404); echo 'Invalid share link'; exit; }

        if (!empty($share['expires_at'])) {
            $exp = strtotime($share['expires_at'] . ' UTC');
            if ($exp !== false && time() > $exp) { status_header(410); echo 'Share link expired'; exit; }
        }

        $authed = self::is_token_authorized($token);
        $error = '';

        if (!$authed && isset($_POST['wpdrive_share_pw'])) {
            $pw = (string)($_POST['wpdrive_share_pw'] ?? '');
            if ($pw !== '' && wp_check_password($pw, $share['password_hash'])) {
                self::set_auth_cookie($token, 28800);
                $authed = true;
            } else {
                $error = 'Incorrect password.';
            }
        }

        if (!$authed) {
            self::render_password_prompt($token, $error);
            exit;
        }

        self::render_share_browser($token, $share);
        exit;
    }

    private static function render_password_prompt($token, $error) {
        $action = esc_url(home_url('/wpdrive-share/' . $token . '/'));
        ?>
        <!doctype html>
        <html><head>
            <meta charset="utf-8" />
            <meta name="viewport" content="width=device-width, initial-scale=1" />
            <title>WPDrive Share</title>
            <?php wp_head(); ?>
        </head><body>
            <div style="max-width:420px;margin:80px auto;font-family:system-ui, sans-serif;">
                <h2>Enter password</h2>
                <?php if ($error): ?><p style="color:#b00020;"><?php echo esc_html($error); ?></p><?php endif; ?>
                <form method="post" action="<?php echo $action; ?>">
                    <input type="password" name="wpdrive_share_pw" style="width:100%;padding:10px;font-size:16px;" autofocus />
                    <button type="submit" style="margin-top:12px;padding:10px 14px;font-size:16px;">Continue</button>
                </form>
            </div>
            <?php wp_footer(); ?>
        </body></html>
        <?php
    }

    private static function render_share_browser($token, $share) {
        $scope = WPDrive_Helpers::normalize_rel_path($share['scope_path']);
        $scope_is_dir = (int)$share['is_dir'] === 1;

        $path_q = isset($_GET['path']) ? (string)$_GET['path'] : '';
        $path_q = $path_q ? WPDrive_Helpers::normalize_rel_path($path_q) : '';

        $path = $scope;
        if ($scope_is_dir && $path_q !== '' && self::path_within_scope($path_q, $scope, 1)) {
            $path = $path_q;
        }

        $children = WPDrive_DB::list_children($path);
        $base_url = home_url('/wpdrive-share/' . $token . '/');
        ?>
        <!doctype html>
        <html><head>
            <meta charset="utf-8" />
            <meta name="viewport" content="width=device-width, initial-scale=1" />
            <title>WPDrive Share</title>
            <?php wp_head(); ?>
        </head><body>
        <div style="max-width:900px;margin:40px auto;font-family:system-ui,sans-serif;">
            <h2>Shared: <?php echo esc_html($scope); ?></h2>
            <div style="margin:12px 0;"><?php echo self::render_breadcrumbs($base_url, $scope, $path); ?></div>

            <table style="width:100%;border-collapse:collapse;">
                <thead>
                    <tr>
                        <th style="text-align:left;border-bottom:1px solid #ddd;padding:8px;">Name</th>
                        <th style="text-align:right;border-bottom:1px solid #ddd;padding:8px;">Size</th>
                        <th style="text-align:right;border-bottom:1px solid #ddd;padding:8px;">Modified</th>
                        <th style="text-align:right;border-bottom:1px solid #ddd;padding:8px;">Action</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($children as $c): ?>
                    <tr>
                        <td style="padding:8px;border-bottom:1px solid #f0f0f0;">
                            <?php if ((int)$c['is_dir'] === 1): ?>
                                <a href="<?php echo esc_url(add_query_arg(array('path' => $c['rel_path']), $base_url)); ?>">
                                    📁 <?php echo esc_html($c['name']); ?>
                                </a>
                            <?php else: ?>
                                📄 <?php echo esc_html($c['name']); ?>
                            <?php endif; ?>
                        </td>
                        <td style="padding:8px;border-bottom:1px solid #f0f0f0;text-align:right;">
                            <?php echo (int)$c['is_dir'] === 1 ? '' : esc_html(size_format((int)$c['size'])); ?>
                        </td>
                        <td style="padding:8px;border-bottom:1px solid #f0f0f0;text-align:right;">
                            <?php echo (int)$c['is_dir'] === 1 ? '' : esc_html(gmdate('Y-m-d H:i', (int)$c['mtime'])); ?>
                        </td>
                        <td style="padding:8px;border-bottom:1px solid #f0f0f0;text-align:right;">
                            <?php if ((int)$c['is_dir'] === 0): ?>
                                <?php
                                    $dl = add_query_arg(array(
                                        'wpdrive_share_dl' => $token,
                                        'p' => base64_encode($c['rel_path']),
                                    ), home_url('/'));
                                ?>
                                <a href="<?php echo esc_url($dl); ?>">Download</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php wp_footer(); ?>
        </body></html>
        <?php
    }

    private static function render_breadcrumbs($base_url, $scope, $path) {
        $scope = trim($scope, '/');
        $path = trim($path, '/');
        if ($scope === '') { return ''; }

        $crumbs = array();
        $crumbs[] = array('name' => $scope, 'path' => $scope);

        if ($path !== $scope) {
            $rest = substr($path, strlen($scope));
            $rest = ltrim($rest, '/');
            if ($rest !== '') {
                $segs = explode('/', $rest);
                $acc = $scope;
                foreach ($segs as $s) {
                    $acc .= '/' . $s;
                    $crumbs[] = array('name' => $s, 'path' => $acc);
                }
            }
        }

        $out = '';
        foreach ($crumbs as $i => $c) {
            if ($i > 0) { $out .= ' / '; }
            $out .= '<a href="' . esc_url(add_query_arg(array('path' => $c['path']), $base_url)) . '">' . esc_html($c['name']) . '</a>';
        }
        return $out;
    }
}
