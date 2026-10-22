<?php
// Only inactive, unmodified official components; never update the active theme/plugin.
error_reporting(E_ALL);
ini_set('display_errors', 'stderr');
umask(0077);
$root = getenv('HOME') . '/web/goatmusic.jp';
define('SHORTINIT', true);
require $root . '/wp-load.php';
global $wpdb;
function goat_opt($key) {
    global $wpdb;
    $raw = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $key));
    if (is_serialized($raw)) { return unserialize($raw, ['allowed_classes' => false]); }
    return $raw;
}
function goat_fail($message) { throw new RuntimeException($message); }
function goat_download($url, $path) {
    $out = fopen($path, 'xb');
    if (!$out) { goat_fail('Cannot create download'); }
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_FILE => $out, CURLOPT_FAILONERROR => true,
        CURLOPT_CONNECTTIMEOUT => 15, CURLOPT_TIMEOUT => 120, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_USERAGENT => 'GOATMusicConservativeUpdate/1.0']);
    $ok = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    fclose($out);
    if (!$ok || $status !== 200) { goat_fail('Official package download failed: HTTP ' . $status); }
}
function goat_unpack($zipPath, $destination, $slug) {
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CHECKCONS) !== true) { goat_fail('Invalid ZIP'); }
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        if (!str_starts_with($name, $slug . '/') || preg_match('~(^|/)\.{1,2}(/|$)|[\\\\\x00]~', $name)) {
            goat_fail('Unsafe package path');
        }
        $zip->getExternalAttributesIndex($i, $opsys, $attr);
        if ((($attr >> 16) & 0170000) === 0120000) { goat_fail('Package symlink rejected'); }
    }
    if (!mkdir($destination, 0700) || !$zip->extractTo($destination)) { goat_fail('ZIP extraction failed'); }
    $zip->close();
    $path = $destination . '/' . $slug;
    if (!is_dir($path)) { goat_fail('Package root missing'); }
    return $path;
}
function goat_tree($directory) {
    $hashes = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if ($file->isLink()) { goat_fail('Local symlink rejected'); }
        if (!$file->isFile()) { continue; }
        $hash = hash_file('sha256', $file->getPathname());
        if ($hash === false) { goat_fail('Unreadable file'); }
        $hashes[substr($file->getPathname(), strlen($directory) + 1)] = $hash;
    }
    ksort($hashes);
    return $hashes;
}
function goat_permissions_and_lint($directory) {
    chmod($directory, 0755);
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    foreach ($it as $file) {
        if (!chmod($file->getPathname(), $file->isDir() ? 0755 : 0644)) { goat_fail('Cannot set package permissions'); }
        if ($file->isFile() && strtolower($file->getExtension()) === 'php') {
            $output = []; $status = 0;
            exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file->getPathname()) . ' 2>&1', $output, $status);
            if ($status !== 0) { goat_fail('New package PHP syntax check failed'); }
        }
    }
}
function goat_health() {
    $result = [];
    foreach (['/', '/contact/', '/artists/', '/goat-works/'] as $route) {
        $ch = curl_init('https://goatmusic.jp' . $route);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_FOLLOWLOCATION => false]);
        $body = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($status !== 200 || !is_string($body) || !str_contains($body, 'GOAT MUSIC') || !str_contains($body, '/themes/abletone/')) {
            goat_fail('Page health failed: ' . $route);
        }
        if (!preg_match('~<title>(.*?)</title>~is', $body, $m)) { goat_fail('Page title missing'); }
        $result[$route] = [$m[1], substr_count($body, '<form'), substr_count($body, '<img')];
    }
    return $result;
}
function goat_version_in($file) {
    $header = file_get_contents($file, false, null, 0, 8192);
    if (!preg_match('/^[ \t\/*#@]*Version:\s*(.+)$/mi', $header, $m)) { goat_fail('Version header missing'); }
    return trim($m[1]);
}
function goat_config_snapshot() {
    $s = [];
    foreach (['active_plugins','stylesheet','template','home','siteurl'] as $key) { $s[$key] = goat_opt($key); }
    return $s;
}
if (!extension_loaded('curl') || !class_exists('ZipArchive') || !is_callable('exec')) {
    fwrite(STDERR, "Required PHP curl, ZipArchive or exec unavailable. Nothing changed.\n"); exit(1);
}
if (rtrim((string)goat_opt('home'), '/') !== 'https://goatmusic.jp' || rtrim((string)goat_opt('siteurl'), '/') !== 'https://goatmusic.jp') { goat_fail('Wrong site'); }
if (is_file($root . '/.maintenance')) { goat_fail('Site is already in maintenance'); }
foreach (['auto_updater.lock','core_updater.lock'] as $key) {
    $lock = (int)goat_opt($key);
    if ($lock > time() - 3600) { goat_fail('WordPress updater lock detected'); }
}
$private = getenv('HOME') . '/goat-maintenance-private';
if (!is_dir($private) && !mkdir($private, 0700)) { goat_fail('Cannot create private backup root'); }
if (is_link($private) || !chmod($private, 0700)) { goat_fail('Unsafe private backup root'); }
$lock = fopen($private . '/update.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) { goat_fail('Another maintenance process is running'); }
$session = $private . '/' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(5));
if (!mkdir($session, 0700)) { goat_fail('Cannot create backup session'); }
$initialConfig = goat_config_snapshot();
$baseline = goat_health();
$apply = getenv('GOAT_APPLY') === 'true';
$targets = [
    ['plugin', 'wpforms-lite', 'wpforms.php', '2.0.2.2', '2.0.3'],
    ['theme', 'twentytwentyfive', 'style.css', '1.0', '1.5'],
    ['theme', 'twentytwentyfour', 'style.css', '1.0', '1.6'],
    ['theme', 'twentytwentyone', 'style.css', '1.6', '2.9'],
    ['theme', 'twentytwentythree', 'style.css', '1.0', '1.7'],
    ['theme', 'twentytwentytwo', 'style.css', '1.3', '2.2'],
];
$changes = [];
$errors = [];
echo "# goatmusic.jp 保守結果\n\n";
echo $apply ? "実行モード: 更新\n\n" : "実行モード: 事前検査のみ\n\n";
echo "復元用コピー: ホーム直下の非公開 goat-maintenance-private 配下\n\n";
foreach ($targets as [$kind, $slug, $main, $oldVersion, $newVersion]) {
    $target = $root . '/wp-content/' . $kind . 's/' . $slug;
    $backup = $session . '/original-' . $slug;
    $moved = false;
    $installed = false;
    try {
        $active = (array)goat_opt('active_plugins');
        if (($kind === 'plugin' && in_array($slug . '/' . $main, $active, true)) ||
            ($kind === 'theme' && in_array($slug, [goat_opt('stylesheet'), goat_opt('template')], true))) { goat_fail('Active component; skipped'); }
        if (!is_file($target . '/' . $main)) { goat_fail('Not installed; skipped'); }
        $current = goat_version_in($target . '/' . $main);
        if ($current === $newVersion) { echo '- ' . $slug . ': already ' . $newVersion . "\n"; continue; }
        if ($current !== $oldVersion) { goat_fail('Unexpected current version; skipped'); }
        $hashes = goat_tree($target);
        $oldZip = $session . '/' . $slug . '-old.zip';
        $newZip = $session . '/' . $slug . '-new.zip';
        goat_download('https://downloads.wordpress.org/' . $kind . '/' . $slug . '.' . $oldVersion . '.zip', $oldZip);
        goat_download('https://downloads.wordpress.org/' . $kind . '/' . $slug . '.' . $newVersion . '.zip', $newZip);
        $officialOld = goat_unpack($oldZip, $session . '/' . $slug . '-old', $slug);
        if ($hashes !== goat_tree($officialOld)) { goat_fail('Local customizations detected; skipped'); }
        $prepared = goat_unpack($newZip, $session . '/' . $slug . '-new', $slug);
        if (goat_version_in($prepared . '/' . $main) !== $newVersion) { goat_fail('Package version mismatch'); }
        goat_permissions_and_lint($prepared);
        if (!$apply) { echo '- ' . $slug . ': ready ' . $oldVersion . ' -> ' . $newVersion . "\n"; continue; }
        if (goat_config_snapshot() !== $initialConfig || goat_tree($target) !== $hashes) { goat_fail('Concurrent change detected'); }
        file_put_contents($session . '/manifest-' . $slug . '.json', json_encode(['target' => $target, 'backup' => $backup,
            'old_version' => $oldVersion, 'new_version' => $newVersion, 'original_hashes' => $hashes], JSON_PRETTY_PRINT));
        if (!rename($target, $backup)) { goat_fail('Could not preserve original'); }
        $moved = true;
        if (!rename($prepared, $target)) { goat_fail('Could not install new directory'); }
        $installed = true;
        if (goat_health() !== $baseline || goat_config_snapshot() !== $initialConfig) { goat_fail('Post-update verification failed'); }
        $changes[] = $slug;
        echo '- UPDATED ' . $slug . ': ' . $oldVersion . ' -> ' . $newVersion . "; HTTP/config checks passed\n";
    } catch (Throwable $e) {
        if ($moved) {
            if ($installed && !rename($target, $session . '/rejected-' . $slug)) { goat_fail('Cannot move rejected update; manual recovery required'); }
            if (!rename($backup, $target)) { goat_fail('Rollback failed; manual recovery required'); }
            if (goat_tree($target) !== $hashes || goat_health() !== $baseline) { goat_fail('Rollback verification failed'); }
        }
        $errors[] = $slug;
        echo '- SKIPPED ' . $slug . ': ' . $e->getMessage() . "\n";
    }
}
// Low-impact hardening: disable the built-in file editor; do not change plugins, HTTPS or CSP.
if ($apply && !defined('DISALLOW_FILE_EDIT')) {
    $config = $root . '/wp-config.php';
    $old = file_get_contents($config);
    $saved = $session . '/wp-config.php.original';
    $candidate = $session . '/wp-config.php.prepared';
    if (!str_starts_with($old, '<?php')) { goat_fail('Unexpected config format; hardening skipped'); }
    if (!copy($config, $saved) || hash_file('sha256', $saved) !== hash('sha256', $old)) { goat_fail('Config backup failed'); }
    $new = substr_replace($old, "\n/* GOAT: disallow dashboard PHP/theme editing. */\ndefine('DISALLOW_FILE_EDIT', true);\n", 5, 0);
    if (file_put_contents($candidate, $new) === false) { goat_fail('Cannot prepare config'); }
    $output = []; $status = 0;
    exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($candidate) . ' 2>&1', $output, $status);
    if ($status !== 0 || hash_file('sha256', $config) !== hash('sha256', $old)) { goat_fail('Config syntax or concurrency check failed; original unchanged'); }
    chmod($candidate, fileperms($config) & 0777);
    if (!rename($candidate, $config)) { goat_fail('Cannot atomically install config'); }
    try {
        if (goat_health() !== $baseline || goat_config_snapshot() !== $initialConfig) { goat_fail('Config health check failed'); }
        echo "- HARDENED DISALLOW_FILE_EDIT=true; original config preserved; HTTP/config checks passed\n";
        $changes[] = 'DISALLOW_FILE_EDIT';
    } catch (Throwable $e) {
        if (!rename($saved, $config)) { goat_fail('Config rollback failed'); }
        if (goat_health() !== $baseline) { goat_fail('Config rollback health failed'); }
        echo "- ROLLED BACK config hardening\n";
        $errors[] = 'config';
    }
} else {
    echo "- Config hardening: unchanged (inspection mode or existing explicit setting)\n";
}
if (goat_health() !== $baseline || goat_config_snapshot() !== $initialConfig) { goat_fail('Final health check failed'); }
echo PHP_EOL . 'Updated/hardened: ' . count($changes) . '; skipped/error: ' . count($errors) . "\n";
echo "- Abletone and all active plugins were left unchanged.\n";
echo "- WordPress database content, menus and activation settings were left unchanged.\n";
echo "- Browser visual checks, mail delivery and complete restore testing were not performed.\n";
exit($errors ? 2 : 0);
