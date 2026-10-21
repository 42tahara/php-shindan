<?php
/*
 * PHP 事前診断ファイル（株式会社42）
 *
 * 読むだけのファイルです。このスクリプト自身は、次のことをしません。
 *   - 外部への通信（結果は画面に出るだけで、どこにも送られません）
 *   - データベースへの接続、wp-config.php の読み取り
 *   - このファイル自身以外の、ファイルの書き換え・削除・作成
 *
 * 読み取るもの:
 *   - PHP の版・動かし方（SAPI）・サーバーソフト名（SERVER_SOFTWARE）・関連する ini 設定
 *   - WordPress の版（wp-includes/version.php の先頭 8KB）
 *   - プラグイン・mu-plugins の各 PHP ファイル、テーマの style.css の「先頭 8KB」（ヘッダ部分）
 *     から、名前・版・親テーマだけを取り出します。ファイルの中身は出力しません。
 *   - wp-content 直下に、advanced-cache.php などのドロップインがあるかどうか（名前だけ）
 *   読む場所は、このファイルを置いた場所と、その親（最大 3 階層）で見つかった WordPress の中だけです。
 *   シンボリックリンクの先が WordPress の外、または wp-config.php のときは、読みません。
 *
 * 書き込むもの: 実行すると、このファイル自身を削除します。削除できないときだけ、
 *   このファイルの中身を「404 を返すだけ」に置き換えます。
 *
 * 使い方:
 *   1. 下の $TOKEN を、16 文字以上の、推測されにくい文字列に書き換える（例: openssl rand -hex 16）。
 *   2. 下の $EXPIRES を、数日後の日時に書き換える（例: '2026-11-05 18:00'）。期限を過ぎると 404 になります。
 *   3. サーバーに置き、 shindan.php?k=<合言葉> を開き、ボタンを押す。
 *   書き換える前の状態（__TOKEN__ のまま）では、何も表示しません。
 *   PHP 5.2 以降で動くように書いています（配列は array()、無名関数は使いません）。
 */
$TOKEN      = '__TOKEN__';
$EXPIRES    = '__EXPIRES__';   // '2026-11-05 18:00' のような日時、または UNIX 秒
$BASIS_DATE = '2026-10-22';

@ini_set('display_errors', '0');
error_reporting(0);

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function not_found() {
    header('HTTP/1.0 404 Not Found', true, 404);
    exit;
}

function same($known, $given) {
    if (!is_string($known) || !is_string($given)) { return false; }
    if (function_exists('hash_equals')) { return hash_equals($known, $given); }
    $n = strlen($known);
    if ($n !== strlen($given)) { return false; }
    $r = 0;
    for ($i = 0; $i < $n; $i++) { $r |= ord($known[$i]) ^ ord($given[$i]); }
    return $r === 0;
}

/* ---- 認証: 合言葉が違う・期限切れ・未設定の雛形は、中身のない 404 ---- */
$k = '';
if (isset($_POST['k'])) { $k = $_POST['k']; }
elseif (isset($_GET['k'])) { $k = $_GET['k']; }
$exp = preg_match('/^[0-9]+$/', $EXPIRES) ? (int)$EXPIRES : @strtotime($EXPIRES);
if (strlen($TOKEN) < 16 || !$exp || $exp < 1 || time() > $exp || !same($TOKEN, $k)) {
    not_found();
}

header('Content-Type: text/html; charset=UTF-8');
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');

/* ---- 確認画面（GET）: リンクの先読みでは実行・削除されないよう、ボタンを押すまで何もしない ---- */
if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    ?><!doctype html>
<html lang="ja"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<meta name="color-scheme" content="light">
<title>PHP 事前診断</title>
<style>body{background:#fff;color:#111;font:16px/1.7 sans-serif;max-width:720px;margin:24px auto;padding:0 16px}button{font:inherit;padding:12px 24px;cursor:pointer}</style>
</head><body>
<h1>PHP 事前診断</h1>
<p>ボタンを押すと、PHP とサイトの状況を調べて、結果を画面に表示します。<b>結果の表示と同時に、このファイルはサーバーから削除されます。</b></p>
<form method="post" action="">
<input type="hidden" name="k" value="<?php echo h($TOKEN); ?>">
<button type="submit">診断を実行する</button>
</form>
</body></html>
<?php
    exit;
}

/* ---- 安全に読むための道具 ---- */
function can($f) { return function_exists($f); }

function rd($path, $len) {
    if (!can('file_get_contents')) { return false; }
    return @file_get_contents($path, false, null, 0, $len);
}

function isf($p) { return can('is_file') && @is_file($p); }
function isd($p) { return can('is_dir') && @is_dir($p); }

function ls($dir) {
    if (!can('opendir') || !can('readdir')) { return false; }
    $dh = @opendir($dir);
    if (!$dh) { return false; }
    $names = array();
    while (($f = readdir($dh)) !== false) {
        if ($f === '.' || $f === '..') { continue; }
        $names[] = $f;
        if (count($names) >= 1000) { break; }
    }
    closedir($dh);
    sort($names);
    return $names;
}

/* WordPress の外・wp-config.php を指すシンボリックリンクは読まない */
function inside($path, $rootReal) {
    if (!can('realpath')) { return basename($path) !== 'wp-config.php'; }
    $real = @realpath($path);
    if ($real === false) { return false; }
    if (basename($real) === 'wp-config.php') { return false; }
    $root = rtrim($rootReal, '/\\');
    return strpos($real, $root . DIRECTORY_SEPARATOR) === 0;
}

function hdr($text, $name) {
    $text = str_replace(array("\r\n", "\r"), "\n", $text);
    if (preg_match('/^[ \t\/*#@]*' . $name . ':(.*)$/mi', $text, $m)) {
        return trim(preg_replace('/\s*(?:\*\/|\?>).*$/', '', $m[1]));
    }
    return '';
}

/* 1 つの PHP ファイルからプラグインのヘッダを読む。見つかれば array(名前, 版)、無ければ false */
function plugin_header($path, $rootReal) {
    if (!isf($path) || !inside($path, $rootReal)) { return false; }
    $head = rd($path, 8192);
    if ($head === false) { return false; }
    $name = hdr($head, 'Plugin Name');
    if ($name === '') { return false; }
    $ver = hdr($head, 'Version');
    return array($name, $ver);
}

function cell($s) { return $s === '' ? '-' : $s; }

$lines = array();
$notes = array();

/* ---- PHP ---- */
$phpv = PHP_VERSION;
$sapi = php_sapi_name();
$lines[] = '[meta]';
$lines[] = 'tool=shindan.php';
$lines[] = 'basis_date=' . $BASIS_DATE;
$lines[] = '';
$lines[] = '[PHP]';
$lines[] = 'version=' . $phpv;
$lines[] = 'sapi=' . $sapi;
$lines[] = 'server=' . (isset($_SERVER['SERVER_SOFTWARE']) ? $_SERVER['SERVER_SOFTWARE'] : '-');
foreach (array('memory_limit', 'post_max_size', 'upload_max_filesize', 'max_file_uploads',
               'max_input_vars', 'max_input_nesting_level', 'session.upload_progress.enabled') as $key) {
    if (can('ini_get')) {
        $v = ini_get($key);
        $lines[] = $key . '=' . ($v === false ? '(unknown)' : $v);
    } else {
        $lines[] = $key . '=(unavailable)';
    }
}

/* php.net が 2026-10-21 に出した修正版（CVE-2026-109618 / 109619）。版を足すときはここだけ直す */
$fixed = array('8.2' => '8.2.35', '8.3' => '8.3.36', '8.4' => '8.4.27', '8.5' => '8.5.12');
$parts = explode('.', $phpv);
$branch = $parts[0] . '.' . (isset($parts[1]) ? (int)$parts[1] : 0);
$suffix = (bool)preg_match('/^[0-9]+\.[0-9]+\.[0-9]+[^0-9.]/', $phpv);

if (version_compare($phpv, '8.2', '<')) {
    $notes[] = array('high', 'PHP ' . $phpv . ' は、php.net が今回の修正版を出している系統（8.2 以降）ではありません。'
        . 'Linux ディストリビューションやホスティング会社が、版番号を上げずに修正を取り込んでいる場合は、この版番号だけでは判定できません。'
        . '契約中のホスティング会社の告知を確認するか、PHP の更新・移行を検討してください。');
} elseif (isset($fixed[$branch])) {
    if (version_compare($phpv, $fixed[$branch], '>=')) {
        $notes[] = array('ok', 'PHP ' . $phpv . ' は、php.net の修正版（' . $fixed[$branch] . '）以降です。');
    } else {
        $notes[] = array('high', 'PHP ' . $phpv . ' は、php.net の修正版（' . $fixed[$branch] . '）より前です。更新が必要です。');
    }
    if ($suffix) {
        $notes[] = array('mid', '版番号に配布元の付記があります。修正の取り込み方は配布元によって異なるため、配布元の告知もご確認ください。');
    }
} else {
    $notes[] = array('mid', 'PHP ' . $phpv . ' は、このファイルの判定表にない系統です（判定基準日: ' . $BASIS_DATE . '）。判定できません。');
}
if (version_compare($phpv, '8.2', '>=') && version_compare($phpv, '8.3', '<')) {
    $notes[] = array('mid', 'PHP 8.2 は、2026-12-31 でセキュリティサポートが終了します。');
}
if ($sapi === 'apache2handler' || $sapi === 'apache') {
    $notes[] = array('high', 'Apache の mod_php で動いています。php.net は、この動かし方が最も危険としています。');
}
if (can('ini_get') && ini_get('session.upload_progress.enabled')) {
    $notes[] = array('mid', 'session.upload_progress.enabled が有効です。php.net が挙げる緩和策では、無効にします。');
}

/* ---- WordPress ---- */
$root = null;
$dir = dirname(__FILE__);
for ($i = 0; $i < 4; $i++) {
    if (isf($dir . '/wp-includes/version.php')) { $root = $dir; break; }
    $parent = dirname($dir);
    if ($parent === $dir) { break; }
    $dir = $parent;
}
$lines[] = '';
$lines[] = '[WordPress]';
if ($root === null) {
    $lines[] = 'found=no (searched this directory and up to 3 parents)';
} else {
    $rootReal = can('realpath') ? @realpath($root) : $root;
    if ($rootReal === false) { $rootReal = $root; }
    $src = rd($root . '/wp-includes/version.php', 8192);
    $wpv = '';
    if ($src !== false && preg_match('/\$wp_version\s*=\s*\'([0-9a-zA-Z.\-]+)\'/', $src, $m)) { $wpv = $m[1]; }
    $lines[] = 'found=yes';
    $lines[] = 'version=' . ($wpv === '' ? '(unknown)' : $wpv);

    $content = $root . '/wp-content';
    $cap = 200;

    /* plugins */
    $lines[] = '';
    $lines[] = '[plugins] (installed; active/inactive is not determined)';
    $count = 0; $truncated = false;
    $entries = ls($content . '/plugins');
    if ($entries === false) {
        $lines[] = '(plugins directory not found or unreadable: layout may be non-standard)';
    } else {
        foreach ($entries as $e) {
            if ($e === 'index.php') { continue; }
            if ($count >= $cap) { $truncated = true; break; }
            $path = $content . '/plugins/' . $e;
            if (isd($path)) {
                if (!inside($path, $rootReal)) {
                    $lines[] = $e . ' | (skipped: outside this WordPress) | -';
                    $count++;
                    continue;
                }
                $files = ls($path);
                $found = 0;
                if ($files !== false) {
                    $n = 0;
                    foreach ($files as $f) {
                        if (substr($f, -4) !== '.php') { continue; }
                        if (++$n > 100) { break; }
                        $ph = plugin_header($path . '/' . $f, $rootReal);
                        if ($ph !== false) {
                            $lines[] = $e . '/' . $f . ' | ' . $ph[0] . ' | ' . cell($ph[1]);
                            $found++;
                            $count++;
                        }
                    }
                }
                if ($found === 0) {
                    $lines[] = $e . ' | (no plugin header found) | -';
                    $count++;
                }
            } elseif (substr($e, -4) === '.php') {
                $ph = plugin_header($path, $rootReal);
                if ($ph !== false) {
                    $lines[] = $e . ' | ' . $ph[0] . ' | ' . cell($ph[1]);
                    $count++;
                }
            }
        }
        if ($count === 0) { $lines[] = '(none)'; }
        if ($truncated) { $lines[] = '(truncated at ' . $cap . ')'; }
    }

    /* mu-plugins と ドロップイン */
    $lines[] = '';
    $lines[] = '[mu-plugins]';
    $mu = ls($content . '/mu-plugins');
    $count = 0;
    if ($mu !== false) {
        foreach ($mu as $f) {
            if (substr($f, -4) !== '.php' || $count >= $cap) { continue; }
            $ph = plugin_header($content . '/mu-plugins/' . $f, $rootReal);
            if ($ph !== false) {
                $lines[] = $f . ' | ' . $ph[0] . ' | ' . cell($ph[1]);
            } else {
                $lines[] = $f . ' | (no plugin header found) | -';
            }
            $count++;
        }
    }
    if ($count === 0) { $lines[] = '(none)'; }

    $lines[] = '';
    $lines[] = '[drop-ins] (names only)';
    $drop = array();
    foreach (array('advanced-cache.php', 'object-cache.php', 'db.php', 'db-error.php', 'maintenance.php') as $d) {
        if (isf($content . '/' . $d)) { $drop[] = $d; }
    }
    $lines[] = count($drop) ? implode(', ', $drop) : '(none)';

    /* themes */
    $lines[] = '';
    $lines[] = '[themes] (name | version | parent theme)';
    $count = 0; $truncated = false;
    $tl = ls($content . '/themes');
    if ($tl === false) {
        $lines[] = '(themes directory not found or unreadable)';
    } else {
        foreach ($tl as $f) {
            if ($count >= 50) { $truncated = true; break; }
            $css = $content . '/themes/' . $f . '/style.css';
            if (!isf($css) || !inside($css, $rootReal)) { continue; }
            $head = rd($css, 8192);
            if ($head === false) { continue; }
            $lines[] = $f . ' | ' . cell(hdr($head, 'Version')) . ' | ' . cell(hdr($head, 'Template'));
            $count++;
        }
        if ($count === 0) { $lines[] = '(none)'; }
        if ($truncated) { $lines[] = '(truncated at 50)'; }
    }
}

/* ---- 後始末: 自分自身を削除。できなければ、中身を 404 を返すだけにする ---- */
$state = 'no';
if (can('unlink') && @unlink(__FILE__)) {
    $state = 'deleted';
} elseif (can('file_put_contents')
    && @file_put_contents(__FILE__, "<?php header('HTTP/1.0 404 Not Found', true, 404); exit;\n") !== false) {
    $state = 'neutralized';
}
if (can('opcache_invalidate')) { @opcache_invalidate(__FILE__, true); }

$lines[] = '';
$lines[] = '[file]';
$lines[] = 'self_cleanup=' . $state;

$report = implode("\n", $lines);
$labels = array('high' => '要対応', 'mid' => '確認', 'ok' => '問題なし');
?><!doctype html>
<html lang="ja"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<meta name="color-scheme" content="light">
<title>PHP 事前診断</title>
<style>body{background:#fff;color:#111;font:16px/1.7 sans-serif;max-width:720px;margin:24px auto;padding:0 16px}textarea{width:100%;height:320px;font:13px/1.5 monospace}.n{padding:8px 12px;margin:6px 0;border-left:6px solid #999;background:#f4f4f4}.high{border-color:#d92d20}.mid{border-color:#e0a800}.ok{border-color:#067647}.okt{color:#067647}.ng{color:#d92d20;font-weight:bold}</style>
</head><body>
<h1>PHP 事前診断</h1>
<?php if ($state === 'deleted') { ?>
<p class="okt">このファイルはサーバーから削除されました。</p>
<?php } elseif ($state === 'neutralized') { ?>
<p class="ng">このファイルは削除できなかったため、中身を無効にしました（開いても何も表示されません）。FTP などで shindan.php を、いますぐ削除してください。</p>
<?php } else { ?>
<p class="ng">このファイルを削除も無効化もできませんでした。合言葉と有効期限が切れるまで、結果を再表示できます。FTP などで shindan.php を、いますぐ削除してください。</p>
<?php } ?>
<h2>目安</h2>
<?php foreach ($notes as $n) { ?>
<div class="n <?php echo h($n[0]); ?>"><b><?php echo h($labels[$n[0]]); ?></b>　<?php echo h($n[1]); ?></div>
<?php } ?>
<p style="font-size:14px;color:#555">これは設定と版から見た目安で、脆弱性の有無の断定ではありません。判定基準日: <?php echo h($BASIS_DATE); ?>。プラグイン・テーマは「インストール済み」で、有効かどうかは判定していません。</p>
<h2>診断結果（テキスト）</h2>
<textarea readonly onclick="this.select()"><?php echo h($report); ?></textarea>
<p style="font-size:13px;color:#555">この内容は、サイトの構成を示す情報です。診断を依頼している場合は、依頼先から指定された方法でお渡しください。公開の掲示板やチャットには、貼らないでください。<?php if ($state === 'deleted') { ?>この画面を閉じると、内容は再表示できません（ファイルは削除済みのため）。<?php } ?></p>
</body></html>
