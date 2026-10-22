<?php
// Read-only inventory: no full WordPress bootstrap, updates, or cron execution.
error_reporting(E_ALL);
ini_set('display_errors', 'stderr');
$root = getenv('HOME') . '/web/goatmusic.jp';
if (!is_file($root . '/wp-load.php')) { exit(1); }
define('SHORTINIT', true);
require $root . '/wp-load.php';
require $root . '/wp-includes/version.php';
global $wpdb;
function option_value($name, $default = null) {
    global $wpdb;
    $v = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name));
    if ($v === null) { return $default; }
    if (is_serialized($v)) { return unserialize($v, ['allowed_classes' => false]); }
    return $v;
}
function headers_for($file) {
    $text = file_get_contents($file, false, null, 0, 8192);
    $out = [];
    foreach (['Plugin Name', 'Theme Name', 'Version', 'Requires at least', 'Requires PHP', 'Template', 'Update URI'] as $key) {
        if (preg_match('/^[ \t\/*#@]*' . preg_quote($key, '/') . ':\s*(.+)$/mi', $text, $m)) {
            $out[$key] = trim($m[1]);
        }
    }
    return $out;
}
$active = (array) option_value('active_plugins', []);
$auto = (array) option_value('auto_update_plugins', []);
$plugins = [];
foreach (array_merge(glob($root . '/wp-content/plugins/*/*.php'), glob($root . '/wp-content/plugins/*.php')) as $file) {
    $h = headers_for($file);
    if (!isset($h['Plugin Name'])) { continue; }
    $relative = substr($file, strlen($root . '/wp-content/plugins/'));
    $plugins[$relative] = ['Name' => $h['Plugin Name'], 'Version' => $h['Version'] ?? '',
        'RequiresWP' => $h['Requires at least'] ?? '', 'RequiresPHP' => $h['Requires PHP'] ?? '',
        'UpdateURI' => $h['Update URI'] ?? '', 'active' => in_array($relative, $active, true),
        'auto_update' => in_array($relative, $auto, true)];
}
$themes = [];
$stylesheet = option_value('stylesheet');
$template = option_value('template');
foreach (glob($root . '/wp-content/themes/*/style.css') as $file) {
    $h = headers_for($file);
    if (!isset($h['Theme Name'])) { continue; }
    $slug = basename(dirname($file));
    $themes[$slug] = ['Name' => $h['Theme Name'], 'Version' => $h['Version'] ?? '',
        'RequiresWP' => $h['Requires at least'] ?? '', 'RequiresPHP' => $h['Requires PHP'] ?? '',
        'Template' => $h['Template'] ?? '', 'active' => $slug === $stylesheet || $slug === $template];
}
$flags = [];
foreach (['WP_DEBUG','WP_DEBUG_LOG','WP_DEBUG_DISPLAY','DISALLOW_FILE_EDIT','DISALLOW_FILE_MODS',
    'FORCE_SSL_ADMIN','DISABLE_WP_CRON','AUTOMATIC_UPDATER_DISABLED','WP_AUTO_UPDATE_CORE','MULTISITE','DISALLOW_UNFILTERED_HTML'] as $key) {
    // Output booleans only; WP_DEBUG_LOG can contain an internal file path.
    $flags[$key] = defined($key) ? (bool) constant($key) : null;
}
$salts = true;
foreach (['AUTH_KEY','SECURE_AUTH_KEY','LOGGED_IN_KEY','NONCE_KEY','AUTH_SALT','SECURE_AUTH_SALT','LOGGED_IN_SALT','NONCE_SALT'] as $key) {
    if (!defined($key) || strlen((string) constant($key)) < 32 || constant($key) === 'put your unique phrase here') { $salts = false; }
}
$cron = (array) option_value('cron', []);
$events = [];
foreach ($cron as $timestamp => $hooks) {
    if (!is_numeric($timestamp) || !is_array($hooks)) { continue; }
    foreach (['wp_version_check','wp_update_plugins','wp_update_themes','wp_maybe_auto_update'] as $hook) {
        if (isset($hooks[$hook])) { $events[$hook][] = (int) $timestamp; }
    }
}
$admins = 0;
$rows = $wpdb->get_col($wpdb->prepare("SELECT meta_value FROM {$wpdb->usermeta} WHERE meta_key = %s", $wpdb->prefix . 'capabilities'));
foreach ($rows as $raw) {
    $roles = unserialize($raw, ['allowed_classes' => false]);
    if (is_array($roles) && !empty($roles['administrator'])) { $admins++; }
}
if ($wpdb->last_error) { fwrite(STDERR, "Database inventory query failed\n"); exit(1); }
$inventory = ['timestamp' => gmdate('c'), 'core' => $wp_version, 'php_cli' => PHP_VERSION,
    'home' => option_value('home'), 'siteurl' => option_value('siteurl'), 'plugins' => $plugins,
    'themes' => $themes, 'active_plugins' => array_values($active), 'stylesheet' => $stylesheet,
    'template' => $template, 'flags' => $flags, 'salts_appear_set' => $salts,
    'administrator_count' => $admins, 'users_can_register' => option_value('users_can_register'),
    'default_role' => option_value('default_role'), 'cron_events' => $events,
    'config_mode' => substr(sprintf('%o', fileperms($root . '/wp-config.php')), -4),
    'mu_plugin_files' => array_map('basename', glob($root . '/wp-content/mu-plugins/*.php')),
    'dropins' => []];
foreach (['advanced-cache.php','db.php','object-cache.php','sunrise.php'] as $file) {
    if (is_file($root . '/wp-content/' . $file)) { $inventory['dropins'][] = $file; }
}
echo json_encode($inventory, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), PHP_EOL;
