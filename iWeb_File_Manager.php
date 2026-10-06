<?php
ob_start();
ini_set('display_errors', '0');
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING & ~E_DEPRECATED);
session_start();

const VAULT    = __DIR__ . '/.vault.php';
const SETTINGS = __DIR__ . '/.settings.php';
const ROOT     = __DIR__;

function selfName() { static $n = null; if ($n === null) $n = basename(__FILE__); return $n; }
function appBase() {
    $doc = rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
    $file = str_replace('\\', '/', __FILE__);
    $rel = ($doc && strpos($file, $doc) === 0) ? substr($file, strlen($doc)) : '/' . basename(__FILE__);
    return preg_replace('/\.php$/', '', $rel);
}
function ensureHtaccess() {
    $htPath = __DIR__ . '/.htaccess';
    $existing = file_exists($htPath) ? @file_get_contents($htPath) : '';
    if (strpos($existing, '# BEGIN FMAccess') !== false) return;
    $self = basename(__FILE__);
    $selfBase = pathinfo($self, PATHINFO_FILENAME);
    $block = "# BEGIN FMAccess\n<IfModule mod_rewrite.c>\nRewriteEngine On\nRewriteRule ^{$selfBase}/(.+)$ {$self}?path=$1 [L,QSA]\nRewriteRule ^{$selfBase}/?$ {$self} [L,QSA]\nRewriteCond %{REQUEST_FILENAME} !-d\nRewriteCond %{REQUEST_FILENAME}\\.php -f\nRewriteRule ^(.+?)/?$ $1.php [L]\n</IfModule>\n# END FMAccess\n\n";
    @file_put_contents($htPath, $block . $existing, LOCK_EX);
}
function isCritical($name) {
    if ($name === '' || $name === '.' || $name === '..') return true;
    if ($name === selfName()) return true;
    if ($name === '.vault.php' || $name === '.settings.php') return true;
    return false;
}
function isHiddenPath($rel) {
    $rel = safeRel($rel);
    if ($rel === '') return false;
    foreach (explode('/', $rel) as $p) {
        if ($p === '') continue;
        if (isCritical($p)) return true;
        if ($p[0] === '.') return true;
    }
    return false;
}
function vaultHash() {
    if (!file_exists(VAULT)) return null;
    $raw = @file_get_contents(VAULT);
    $pos = strpos($raw, "\n");
    if ($pos === false) return null;
    $d = json_decode(substr($raw, $pos + 1), true);
    return (is_array($d) && !empty($d['hash'])) ? $d['hash'] : null;
}
function vaultSet($hash) { @file_put_contents(VAULT, "<?php exit; ?>\n" . json_encode(['hash' => $hash]), LOCK_EX); }
function settingsGet() {
    $defaults = ['quota' => 5 * 1024 * 1024 * 1024];
    if (!file_exists(SETTINGS)) return $defaults;
    $raw = @file_get_contents(SETTINGS);
    $pos = strpos($raw, "\n");
    if ($pos === false) return $defaults;
    $d = json_decode(substr($raw, $pos + 1), true);
    if (!is_array($d)) return $defaults;
    return array_merge($defaults, $d);
}
function settingsSet($data) {
    $new = array_merge(settingsGet(), $data);
    @file_put_contents(SETTINGS, "<?php exit; ?>\n" . json_encode($new), LOCK_EX);
    return $new;
}
function safeRel($s) {
    $s = str_replace('\\', '/', (string)$s);
    $out = [];
    foreach (explode('/', $s) as $p) {
        if ($p === '' || $p === '.') continue;
        if ($p === '..') { array_pop($out); continue; }
        $out[] = $p;
    }
    return implode('/', $out);
}
function joinRoot($base, $rel) {
    $rel = safeRel($rel);
    $p = ($rel === '') ? $base : $base . '/' . $rel;
    if (!file_exists($p)) return false;
    $rp = str_replace('\\', '/', realpath($p));
    $rb = str_replace('\\', '/', realpath($base));
    if ($rp === false || $rb === false) return false;
    if ($rp !== $rb && strpos($rp, $rb . '/') !== 0) return false;
    return $rp;
}
function categorize($name) {
    $e = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    static $m = [
        'image'    => ['jpg','jpeg','png','gif','webp','bmp','svg','heic','heif','avif','ico','tif','tiff'],
        'video'    => ['mp4','mkv','mov','avi','webm','flv','wmv','m4v','3gp','mpeg','mpg','ts'],
        'audio'    => ['mp3','wav','ogg','flac','aac','m4a','opus','wma','amr','mid','midi'],
        'document' => ['pdf','doc','docx','xls','xlsx','ppt','pptx','txt','md','csv','rtf','odt','ods','odp','json','xml','html','htm','epub','log','ini','yml','yaml'],
        'archive'  => ['zip','rar','7z','tar','gz','bz2','xz','iso','tgz'],
        'apk'      => ['apk','xapk','apks','aab'],
    ];
    foreach ($m as $k => $v) if (in_array($e, $v, true)) return $k;
    return 'other';
}
function rrmdir($dir) {
    if (!is_dir($dir)) return @unlink($dir);
    foreach (scandir($dir) as $f) {
        if ($f === '.' || $f === '..') continue;
        $p = $dir . '/' . $f;
        if (is_dir($p) && !is_link($p)) rrmdir($p); else @unlink($p);
    }
    return @rmdir($dir);
}
function copydir($src, $dst) {
    if (!is_dir($dst)) @mkdir($dst, 0775, true);
    foreach (scandir($src) as $f) {
        if ($f === '.' || $f === '..') continue;
        $s = $src . '/' . $f; $d = $dst . '/' . $f;
        if (is_dir($s) && !is_link($s)) copydir($s, $d);
        else @copy($s, $d);
    }
}
function uniquePath($dir, $name) {
    $pi = pathinfo($name);
    $base = $pi['filename'] ?? $name;
    $ext = isset($pi['extension']) ? '.' . $pi['extension'] : '';
    $t = $dir . '/' . $base . $ext;
    $k = 1;
    while (file_exists($t)) { $t = $dir . '/' . $base . ' (' . $k . ')' . $ext; $k++; }
    return $t;
}
function addDirToZip($zip, $dir, $prefix) {
    $scan = @scandir($dir);
    if ($scan === false) return;
    $empty = true;
    foreach ($scan as $f) {
        if ($f === '.' || $f === '..') continue;
        $empty = false;
        $p = $dir . '/' . $f;
        $rel = $prefix . '/' . $f;
        if (is_dir($p) && !is_link($p)) addDirToZip($zip, $p, $rel);
        else $zip->addFile($p, $rel);
    }
    if ($empty) $zip->addEmptyDir($prefix);
}
function scanAllFiles($base) {
    $out = [];
    if (!is_dir($base)) return $out;
    $stack = [$base];
    $guard = 0;
    while (!empty($stack) && $guard++ < 100000) {
        $cur = array_pop($stack);
        $scan = @scandir($cur);
        if ($scan === false) continue;
        foreach ($scan as $f) {
            if ($f === '.' || $f === '..') continue;
            if ($f[0] === '.') continue;
            if ($f === selfName()) continue;
            $full = $cur . '/' . $f;
            if (is_dir($full) && !is_link($full)) { $stack[] = $full; }
            elseif (is_file($full)) { $out[] = $full; }
        }
    }
    return $out;
}
function out($data) {
    ob_clean();
    header('Content-Type: application/json');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($data);
    exit;
}
function sortItems(&$items, $sort) {
    usort($items, function ($a, $b) use ($sort) {
        if ($a['dir'] !== $b['dir']) return $a['dir'] ? -1 : 1;
        switch ($sort) {
            case 'date':      return $b['mtime'] - $a['mtime'];
            case 'date_asc':  return $a['mtime'] - $b['mtime'];
            case 'size':      return $b['size'] - $a['size'];
            case 'name_desc': return strcasecmp($b['name'], $a['name']);
            default:          return strcasecmp($a['name'], $b['name']);
        }
    });
}
function xlsxColIndex($letters) {
    if ($letters === '') return -1;
    $n = 0;
    for ($i = 0, $l = strlen($letters); $i < $l; $i++) $n = $n * 26 + (ord($letters[$i]) - 64);
    return $n - 1;
}
function xlsxColName($idx) {
    $n = $idx + 1; $s = '';
    while ($n > 0) { $m = ($n - 1) % 26; $s = chr(65 + $m) . $s; $n = intval(($n - 1) / 26); }
    return $s;
}
function xlsxRead($path) {
    if (!class_exists('ZipArchive')) return null;
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) return null;
    $shared = $zip->getFromName('xl/sharedStrings.xml');
    $sheetData = $zip->getFromName('xl/worksheets/sheet1.xml');
    if ($sheetData === false) {
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $n = $zip->getNameIndex($i);
            if (strpos($n, 'xl/worksheets/') === 0 && substr($n, -4) === '.xml') {
                $sheetData = $zip->getFromName($n);
                if ($sheetData !== false) break;
            }
        }
    }
    $zip->close();
    if ($sheetData === false) return null;
    $strings = [];
    if ($shared !== false && $shared !== null) {
        if (preg_match_all('/<si[^>]*>(.*?)<\/si>/s', $shared, $m)) {
            foreach ($m[1] as $si) {
                $t = '';
                if (preg_match_all('/<t[^>]*>(.*?)<\/t>/s', $si, $mm)) {
                    $t = implode('', array_map(function ($s) { return html_entity_decode($s, ENT_QUOTES | ENT_XML1, 'UTF-8'); }, $mm[1]));
                }
                $strings[] = $t;
            }
        }
    }
    $rows = [];
    if (preg_match_all('/<row[^>]*>(.*?)<\/row>/s', $sheetData, $rowMatches)) {
        foreach ($rowMatches[1] as $rowXml) {
            $cells = [];
            if (preg_match_all('/<c([^>]*)>(.*?)<\/c>/s', $rowXml, $cellMatches, PREG_SET_ORDER)) {
                foreach ($cellMatches as $cm) {
                    $attrs = $cm[1]; $inner = $cm[2];
                    $type = '';
                    if (preg_match('/t="([^"]*)"/', $attrs, $tm)) $type = $tm[1];
                    $ref = '';
                    if (preg_match('/r="([A-Z]+)\d+"/', $attrs, $rm)) $ref = $rm[1];
                    $col = xlsxColIndex($ref);
                    $val = '';
                    if (preg_match('/<v>(.*?)<\/v>/s', $inner, $vm)) $val = $vm[1];
                    elseif (preg_match('/<t[^>]*>(.*?)<\/t>/s', $inner, $vm)) $val = $vm[1];
                    if ($type === 's') { $val = $strings[(int)$val] ?? ''; }
                    elseif ($type === 'b') { $val = $val == '1' ? 'TRUE' : 'FALSE'; }
                    else { $val = html_entity_decode($val, ENT_QUOTES | ENT_XML1, 'UTF-8'); }
                    if ($col < 0) continue;
                    while (count($cells) < $col) $cells[] = '';
                    $cells[$col] = $val;
                }
            }
            $rows[] = $cells;
        }
    }
    $maxc = 0;
    foreach ($rows as $r) $maxc = max($maxc, count($r));
    foreach ($rows as &$r) while (count($r) < $maxc) $r[] = '';
    unset($r);
    return $rows;
}
function xlsxWrite($path, $rows) {
    if (!class_exists('ZipArchive')) return false;
    $tmp = tempnam(sys_get_temp_dir(), 'xlsx_');
    @unlink($tmp);
    $zip = new ZipArchive();
    if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) return false;
    $ct = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>';
    $zip->addFromString('[Content_Types].xml', $ct);
    $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>';
    $zip->addFromString('_rels/.rels', $rels);
    $wb = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Sheet1" sheetId="1" r:id="rId1"/></sheets></workbook>';
    $zip->addFromString('xl/workbook.xml', $wb);
    $wbr = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>';
    $zip->addFromString('xl/_rels/workbook.xml.rels', $wbr);
    $styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="1"><font><sz val="11"/><name val="Calibri"/></font></fonts><fills count="1"><fill><patternFill patternType="none"/></fill></fills><borders count="1"><border/></borders><cellStyleXfs count="1"><xf/></cellStyleXfs><cellXfs count="1"><xf xfId="0"/></cellXfs></styleSheet>';
    $zip->addFromString('xl/styles.xml', $styles);
    $sd = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
    foreach ($rows as $ri => $row) {
        $r = $ri + 1;
        $sd .= '<row r="' . $r . '">';
        foreach ($row as $ci => $val) {
            if ($val === '' || $val === null) continue;
            $ref = xlsxColName($ci) . $r;
            $sd .= '<c r="' . $ref . '" t="inlineStr"><is><t xml:space="preserve">' . htmlspecialchars((string)$val, ENT_QUOTES | ENT_XML1, 'UTF-8') . '</t></is></c>';
        }
        $sd .= '</row>';
    }
    $sd .= '</sheetData></worksheet>';
    $zip->addFromString('xl/worksheets/sheet1.xml', $sd);
    $zip->close();
    $r = @rename($tmp, $path);
    if (!$r) { @unlink($tmp); return false; }
    return true;
}
function detectEncoding($raw) {
    if ($raw === '') return 'UTF-8';
    if (substr($raw, 0, 3) === "\xEF\xBB\xBF") return 'UTF-8';
    if (substr($raw, 0, 2) === "\xFF\xFE") return 'UTF-16LE';
    if (substr($raw, 0, 2) === "\xFE\xFF") return 'UTF-16BE';
    if (mb_check_encoding($raw, 'UTF-8')) return 'UTF-8';
    foreach (['Windows-1252','ISO-8859-1','SJIS','EUC-JP','GB18030','Big5','KOI8-R'] as $cand) {
        if (mb_check_encoding($raw, $cand)) return $cand;
    }
    return 'ISO-8859-1';
}
function looksBinary($raw) {
    if ($raw === '') return false;
    if (strpos($raw, "\0") !== false) return true;
    $sample = substr($raw, 0, 8192);
    $n = strlen($sample);
    if ($n === 0) return false;
    $bad = 0;
    for ($i = 0; $i < $n; $i++) {
        $o = ord($sample[$i]);
        if ($o < 9 || ($o > 13 && $o < 32)) $bad++;
    }
    return ($bad / $n) > 0.05;
}
function decodeText($raw, $enc) {
    if ($raw === '') return '';
    if ($enc === 'UTF-8') {
        if (substr($raw, 0, 3) === "\xEF\xBB\xBF") $raw = substr($raw, 3);
        return $raw;
    }
    if ($enc === 'UTF-16LE' || $enc === 'UTF-16BE') {
        $body = (strlen($raw) >= 2) ? substr($raw, 2) : $raw;
        $t = @mb_convert_encoding($body, 'UTF-8', $enc);
        return ($t === false) ? '' : $t;
    }
    $t = @mb_convert_encoding($raw, 'UTF-8', $enc);
    if ($t === false) {
        $clean = str_replace("\0", '', $raw);
        $t = @mb_convert_encoding($clean, 'UTF-8', $enc);
        if ($t === false) $t = '';
    }
    return $t;
}
function isKnownTextExt($ext) {
    static $list = null;
    if ($list === null) {
        $list = array_flip([
            'txt','md','markdown','mdx','log','ini','cfg','conf','env','yml','yaml','toml',
            'json','jsonc','json5','xml','xsl','xslt','svg','plist','rss','csv','tsv','rtf',
            'sql','properties','html','htm','xhtml','css','scss','sass','less','styl','stylus',
            'js','mjs','cjs','jsx','ts','tsx','mts','cts','vue','svelte','astro',
            'py','pyw','rb','php','phtml','java','kt','kts','scala','c','h','cpp','cc','cxx',
            'hpp','hh','hxx','ino','cs','go','rs','swift','dart','sh','bash','zsh','fish','ksh',
            'ps1','psm1','psd1','bat','cmd','lua','pl','pm','t','r','hs','lhs','clj','cljs','cljc',
            'edn','erl','hrl','ex','exs','jl','m','mlx','groovy','gradle','gvy','tf','tfvars','hcl',
            'proto','graphql','gql','sol','asm','s','v','sv','svh','vh','vhd','vhdl',
            'tex','latex','bib','rst','rest','tcl','mk','makefile','mak','d','nim','pas','pp','f','f90',
            'f95','f03','for','cob','cbl','ada','adb','ads','applescript','scpt','ahk','nix',
            're','ml','mli','mll','mly','fs','fsx','fsi','vb','vbs','asp','jsp','ejs','hbs',
            'handlebars','twig','liquid','razor','gitignore','gitattributes','gitmodules','editorconfig',
            'htaccess','htpasswd','nginxconf','crontab','dockerfile','containerfile','readme','license',
            'changelog','authors','notice','dockerignore','npmrc','babelrc','eslintrc','prettierrc',
            'bashrc','zshrc','vimrc',
        ]);
    }
    return isset($list[$ext]);
}

if (isset($_GET['api'])) {
    $api = $_GET['api'];

    if ($api === 'state') out(['setup' => vaultHash() === null, 'unlocked' => !empty($_SESSION['unlocked'])]);

    if ($api === 'unlock') {
        $p = (string)($_POST['pattern'] ?? '');
        $h = vaultHash();
        if ($h === null) out(['ok' => false, 'error' => 'nosetup']);
        if (strlen($p) < 4) out(['ok' => false, 'error' => 'short']);
        if (password_verify($p, $h)) {
            session_regenerate_id(true);
            $_SESSION['unlocked'] = true;
            ensureHtaccess();
            out(['ok' => true]);
        }
        usleep(450000);
        out(['ok' => false, 'error' => 'wrong']);
    }

    if ($api === 'setup') {
        if (vaultHash() !== null) out(['ok' => false, 'error' => 'exists']);
        $p = (string)($_POST['pattern'] ?? '');
        $c = (string)($_POST['confirm'] ?? '');
        if (strlen($p) < 4 || $p !== $c) out(['ok' => false, 'error' => 'mismatch']);
        $algo = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
        vaultSet(password_hash($p, $algo));
        session_regenerate_id(true);
        $_SESSION['unlocked'] = true;
        ensureHtaccess();
        out(['ok' => true]);
    }

    if ($api === 'logout') { $_SESSION = []; session_destroy(); out(['ok' => true]); }

    if (empty($_SESSION['unlocked'])) { http_response_code(403); out(['ok' => false, 'error' => 'locked']); }

    if ($api === 'pattern_reset') {
        @unlink(VAULT);
        $_SESSION = [];
        session_destroy();
        out(['ok' => true]);
    }

    if ($api === 'settings_get') out(['ok' => true, 'settings' => settingsGet()]);

    if ($api === 'settings_set') {
        $q = (int)($_POST['quota'] ?? 0);
        if ($q < 1024 * 1024) out(['ok' => false, 'error' => 'toosmall']);
        settingsSet(['quota' => $q]);
        out(['ok' => true, 'settings' => settingsGet()]);
    }

    if ($api === 'list') {
        $rel = safeRel($_GET['path'] ?? '');
        $dir = joinRoot(ROOT, $rel);
        if ($dir === false || !is_dir($dir)) out(['ok' => false, 'error' => 'badpath']);
        $filter = $_GET['filter'] ?? 'all';
        $sort = $_GET['sort'] ?? 'name';
        $showHidden = !empty($_GET['show_hidden']);
        $items = [];
        $scan = @scandir($dir);
        if ($scan === false) out(['ok' => false, 'error' => 'unreadable']);
        foreach ($scan as $f) {
            if ($f === '.' || $f === '..') continue;
            if (isCritical($f)) continue;
            if (!$showHidden && $f[0] === '.') continue;
            $full = $dir . '/' . $f;
            $isDir = is_dir($full);
            $cat = $isDir ? 'folder' : categorize($f);
            if ($filter === 'folder') { if (!$isDir) continue; }
            elseif ($filter !== 'all') { if ($isDir || $cat !== $filter) continue; }
            $items[] = [
                'name' => $f, 'dir' => $isDir,
                'size' => $isDir ? 0 : (int)@filesize($full),
                'mtime' => (int)@filemtime($full),
                'cat' => $cat,
                'path' => ($rel === '' ? '' : $rel . '/') . $f,
            ];
        }
        sortItems($items, $sort);
        out(['ok' => true, 'items' => $items, 'path' => $rel]);
    }

    if ($api === 'category') {
        $cat = $_GET['cat'] ?? 'image';
        $limit = (int)($_GET['limit'] ?? 800);
        $items = [];
        $rb = rtrim(str_replace('\\', '/', realpath(ROOT)), '/');
        foreach (scanAllFiles(ROOT) as $full) {
            if (count($items) >= $limit) break;
            $name = basename($full);
            if (categorize($name) !== $cat) continue;
            $rel = ltrim(substr(str_replace('\\', '/', $full), strlen($rb)), '/');
            if (isHiddenPath($rel)) continue;
            $items[] = [
                'name' => $name, 'dir' => false,
                'size' => (int)@filesize($full),
                'mtime' => (int)@filemtime($full),
                'cat' => $cat, 'path' => $rel,
            ];
        }
        $sort = $_GET['sort'] ?? 'date';
        if ($sort === 'date' || $sort === 'date_asc') usort($items, function ($a, $b) use ($sort) { return $sort === 'date' ? $b['mtime'] - $a['mtime'] : $a['mtime'] - $b['mtime']; });
        elseif ($sort === 'size') usort($items, function ($a, $b) { return $b['size'] - $a['size']; });
        elseif ($sort === 'name_desc') usort($items, function ($a, $b) { return strcasecmp($b['name'], $a['name']); });
        else usort($items, function ($a, $b) { return strcasecmp($a['name'], $b['name']); });
        out(['ok' => true, 'items' => $items, 'cat' => $cat]);
    }

    if ($api === 'search') {
        $q = trim((string)($_GET['q'] ?? ''));
        if ($q === '') out(['ok' => true, 'items' => []]);
        $items = []; $limit = 300;
        $rb = rtrim(str_replace('\\', '/', realpath(ROOT)), '/');
        foreach (scanAllFiles(ROOT) as $full) {
            if (count($items) >= $limit) break;
            $name = basename($full);
            if (stripos($name, $q) === false) continue;
            $rel = ltrim(substr(str_replace('\\', '/', $full), strlen($rb)), '/');
            if (isHiddenPath($rel)) continue;
            $items[] = [
                'name' => $name, 'dir' => false,
                'size' => (int)@filesize($full),
                'mtime' => (int)@filemtime($full),
                'cat' => categorize($name), 'path' => $rel,
            ];
        }
        out(['ok' => true, 'items' => $items]);
    }

    if ($api === 'stats') {
        $total = 0; $count = 0; $byCat = []; $catCount = [];
        foreach (scanAllFiles(ROOT) as $full) {
            $s = (int)@filesize($full);
            if ($s < 0) $s = 0;
            $total += $s; $count++;
            $c = categorize(basename($full));
            $byCat[$c] = ($byCat[$c] ?? 0) + $s;
            $catCount[$c] = ($catCount[$c] ?? 0) + 1;
        }
        $st = settingsGet();
        out(['ok' => true, 'total' => $total, 'count' => $count, 'byCat' => $byCat, 'catCount' => $catCount, 'quota' => (int)$st['quota']]);
    }

    if ($api === 'upload') {
        $dir = joinRoot(ROOT, $_POST['path'] ?? '');
        if ($dir === false || !is_dir($dir)) out(['ok' => false, 'error' => 'badpath']);
        $n = 0;
        $names = $_FILES['files']['name'] ?? [];
        foreach ($names as $i => $name) {
            if (($_FILES['files']['error'][$i] ?? 1) !== UPLOAD_ERR_OK) continue;
            $name = basename($name);
            $name = preg_replace('/[^\w\s\.\-\(\)\[\]]/u', '_', $name);
            if ($name === '' || $name[0] === '.') $name = 'file_' . bin2hex(random_bytes(4));
            if (isCritical($name)) $name = 'f_' . $name;
            $target = uniquePath($dir, $name);
            if (move_uploaded_file($_FILES['files']['tmp_name'][$i], $target)) $n++;
        }
        out(['ok' => true, 'count' => $n]);
    }

    if ($api === 'mkdir') {
        $dir = joinRoot(ROOT, $_POST['path'] ?? '');
        $name = trim((string)($_POST['name'] ?? ''));
        $name = preg_replace('/[\/\\\\:*?"<>|]/', '_', $name);
        if ($dir === false || !is_dir($dir) || $name === '' || $name[0] === '.' || isCritical($name)) out(['ok' => false, 'error' => 'bad']);
        @mkdir(uniquePath($dir, $name), 0775);
        out(['ok' => true]);
    }

    if ($api === 'file_create') {
        $dir = joinRoot(ROOT, $_POST['path'] ?? '');
        $name = trim((string)($_POST['name'] ?? ''));
        $name = preg_replace('/[\/\\\\:*?"<>|]/', '_', $name);
        if ($dir === false || !is_dir($dir) || $name === '' || $name[0] === '.' || isCritical($name)) out(['ok' => false, 'error' => 'bad']);
        $target = uniquePath($dir, $name);
        $r = @file_put_contents($target, '');
        out(['ok' => $r !== false, 'name' => basename($target)]);
    }

    if ($api === 'rename') {
        $src = joinRoot(ROOT, $_POST['path'] ?? '');
        $name = trim((string)($_POST['name'] ?? ''));
        $name = preg_replace('/[\/\\\\:*?"<>|]/', '_', $name);
        if ($src === false || !file_exists($src) || $name === '' || $name[0] === '.' || isCritical($name)) out(['ok' => false, 'error' => 'bad']);
        $dir = dirname($src);
        if (basename($src) === $name) out(['ok' => true]);
        @rename($src, uniquePath($dir, $name));
        out(['ok' => true]);
    }

    if ($api === 'delete') {
        $paths = json_decode($_POST['paths'] ?? '[]', true);
        $n = 0;
        $rootReal = str_replace('\\', '/', realpath(ROOT));
        foreach ((array)$paths as $rel) {
            if (isHiddenPath($rel)) continue;
            $p = joinRoot(ROOT, $rel);
            if ($p === false || $p === $rootReal) continue;
            if (isCritical(basename($p))) continue;
            if (is_dir($p)) { rrmdir($p); $n++; }
            elseif (is_file($p)) { @unlink($p); $n++; }
        }
        out(['ok' => true, 'count' => $n]);
    }

    if ($api === 'bulk') {
        $action = $_POST['action'] ?? '';
        $paths = json_decode($_POST['paths'] ?? '[]', true);
        $rootReal = str_replace('\\', '/', realpath(ROOT));
        $resolved = [];
        foreach ((array)$paths as $rel) {
            if (isHiddenPath($rel)) continue;
            $p = joinRoot(ROOT, $rel);
            if ($p === false || $p === $rootReal) continue;
            if (isCritical(basename($p))) continue;
            $resolved[] = $p;
        }
        if (!$resolved) out(['ok' => false, 'error' => 'empty']);
        if ($action === 'delete') {
            $n = 0;
            foreach ($resolved as $p) {
                if (is_dir($p)) { rrmdir($p); $n++; }
                elseif (is_file($p)) { @unlink($p); $n++; }
            }
            out(['ok' => true, 'count' => $n]);
        }
        if ($action === 'hide') {
            $n = 0;
            foreach ($resolved as $p) {
                $dir = dirname($p); $name = basename($p);
                if ($name[0] === '.') continue;
                @rename($p, $dir . '/.' . $name);
                $n++;
            }
            out(['ok' => true, 'count' => $n]);
        }
        if ($action === 'rename') {
            $pattern = preg_replace('/[\/\\\\:*?"<>|]/', '_', (string)($_POST['pattern'] ?? 'file'));
            if ($pattern === '') $pattern = 'file';
            $start = max(1, (int)($_POST['start'] ?? 1));
            $n = 0;
            foreach ($resolved as $p) {
                $dir = dirname($p);
                $ext = is_dir($p) ? '' : (pathinfo($p, PATHINFO_EXTENSION) ? '.' . pathinfo($p, PATHINFO_EXTENSION) : '');
                $newName = $pattern . '_' . $start . $ext;
                $start++;
                @rename($p, uniquePath($dir, $newName));
                $n++;
            }
            out(['ok' => true, 'count' => $n]);
        }
        if ($action === 'copy' || $action === 'move') {
            $destDir = joinRoot(ROOT, $_POST['dest'] ?? '');
            if ($destDir === false || !is_dir($destDir)) out(['ok' => false, 'error' => 'baddest']);
            $n = 0;
            foreach ($resolved as $p) {
                if (strpos($p . '/', $destDir . '/') === 0) continue;
                $target = uniquePath($destDir, basename($p));
                if (is_dir($p)) {
                    if ($action === 'move') @rename($p, $target); else copydir($p, $target);
                    $n++;
                } else {
                    if ($action === 'move') @rename($p, $target); else @copy($p, $target);
                    $n++;
                }
            }
            out(['ok' => true, 'count' => $n]);
        }
        if ($action === 'archive' || $action === 'download') {
            if (!class_exists('ZipArchive')) out(['ok' => false, 'error' => 'nozip']);
            $tmp = tempnam(sys_get_temp_dir(), 'arc_');
            @unlink($tmp);
            $zipPath = $tmp . '.zip';
            $zip = new ZipArchive();
            if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) out(['ok' => false, 'error' => 'nozip']);
            foreach ($resolved as $p) {
                $name = basename($p);
                if (is_dir($p)) addDirToZip($zip, $p, $name); else $zip->addFile($p, $name);
            }
            $zip->close();
            if ($action === 'download') {
                ob_clean();
                session_write_close();
                header('Content-Type: application/zip');
                header('Content-Disposition: attachment; filename="files_' . date('Ymd_His') . '.zip"');
                header('Content-Length: ' . filesize($zipPath));
                readfile($zipPath);
                @unlink($zipPath);
                exit;
            } else {
                $destName = 'archive_' . date('Ymd_His') . '.zip';
                $target = uniquePath(ROOT, $destName);
                @rename($zipPath, $target);
                out(['ok' => true, 'name' => basename($target)]);
            }
        }
        out(['ok' => false, 'error' => 'unknown']);
    }

    if ($api === 'file_read') {
        $p = joinRoot(ROOT, $_GET['path'] ?? '');
        if ($p === false || !is_file($p)) out(['ok' => false, 'error' => 'notfound']);
        if (isCritical(basename($p))) out(['ok' => false, 'error' => 'notfound']);
        $ext = strtolower(pathinfo($p, PATHINFO_EXTENSION));
        $knownText = isKnownTextExt($ext);
        $max = 8 * 1024 * 1024;
        $size = @filesize($p);
        if ($size === false) out(['ok' => false]);
        if ($size > $max) out(['ok' => false, 'error' => 'toobig', 'size' => $size]);
        $raw = @file_get_contents($p);
        if ($raw === false) out(['ok' => false]);
        $enc = detectEncoding($raw);
        $bin = looksBinary($raw);
        if ($knownText) $bin = false;
        $text = '';
        if (!$bin) {
            $text = decodeText($raw, $enc);
            if ($text === '' && $raw !== '') $bin = false;
        }
        out([
            'ok' => true, 'size' => $size, 'encoding' => $enc, 'binary' => $bin,
            'text' => $bin ? '' : $text,
            'base64' => $bin ? base64_encode($raw) : '',
        ]);
    }

    if ($api === 'file_write') {
        $p = joinRoot(ROOT, $_POST['path'] ?? '');
        if ($p === false || !is_file($p)) out(['ok' => false, 'error' => 'notfound']);
        if (isCritical(basename($p))) out(['ok' => false, 'error' => 'notfound']);
        $content = (string)($_POST['content'] ?? '');
        $enc = $_POST['encoding'] ?? 'UTF-8';
        if ($enc !== 'UTF-8') {
            $converted = @mb_convert_encoding($content, $enc, 'UTF-8');
            if ($converted === false) out(['ok' => false, 'error' => 'encode']);
            $content = $converted;
        }
        $r = @file_put_contents($p, $content, LOCK_EX);
        out(['ok' => $r !== false]);
    }

    if ($api === 'file_write_bin') {
        $p = joinRoot(ROOT, $_POST['path'] ?? '');
        if ($p === false || !is_file($p)) out(['ok' => false, 'error' => 'notfound']);
        if (isCritical(basename($p))) out(['ok' => false, 'error' => 'notfound']);
        $b64 = (string)($_POST['base64'] ?? '');
        $raw = base64_decode($b64, true);
        if ($raw === false) out(['ok' => false, 'error' => 'decode']);
        $r = @file_put_contents($p, $raw, LOCK_EX);
        out(['ok' => $r !== false]);
    }

    if ($api === 'zip_list') {
        $p = joinRoot(ROOT, $_GET['path'] ?? '');
        if ($p === false || !is_file($p)) out(['ok' => false, 'error' => 'notfound']);
        if (isCritical(basename($p))) out(['ok' => false, 'error' => 'notfound']);
        if (!class_exists('ZipArchive')) out(['ok' => false, 'error' => 'nozip']);
        $pw = (string)($_GET['password'] ?? '');
        $zip = new ZipArchive();
        if ($zip->open($p) !== true) out(['ok' => false, 'error' => 'open']);
        if ($pw !== '') @$zip->setPassword($pw);
        $items = []; $needsPw = false;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $st = $zip->statIndex($i);
            if ($st === false) continue;
            $name = $st['name'];
            $isDir = substr($name, -1) === '/';
            if (!$isDir) {
                $test = @$zip->getFromIndex($i, 16);
                if ($test === false) $needsPw = true;
            }
            $items[] = ['name' => $name, 'size' => (int)$st['size'], 'mtime' => (int)$st['mtime'], 'dir' => $isDir];
        }
        $zip->close();
        out(['ok' => true, 'items' => $items, 'needsPassword' => $needsPw]);
    }

    if ($api === 'zip_read') {
        $p = joinRoot(ROOT, $_GET['path'] ?? '');
        $inner = (string)($_GET['inner'] ?? '');
        if ($p === false || !is_file($p)) out(['ok' => false, 'error' => 'notfound']);
        if (isCritical(basename($p))) out(['ok' => false, 'error' => 'notfound']);
        if (!class_exists('ZipArchive')) out(['ok' => false, 'error' => 'nozip']);
        if ($inner === '' || strpos($inner, '..') !== false) out(['ok' => false, 'error' => 'bad']);
        $pw = (string)($_GET['password'] ?? '');
        $zip = new ZipArchive();
        if ($zip->open($p) !== true) out(['ok' => false, 'error' => 'open']);
        if ($pw !== '') @$zip->setPassword($pw);
        $st = $zip->statName($inner);
        if ($st === false) { $zip->close(); out(['ok' => false, 'error' => 'notfound']); }
        if ($st['size'] > 20 * 1024 * 1024) { $zip->close(); out(['ok' => false, 'error' => 'toobig']); }
        $data = $zip->getFromName($inner);
        $zip->close();
        if ($data === false) out(['ok' => false, 'error' => 'password']);
        out(['ok' => true, 'base64' => base64_encode($data), 'size' => strlen($data), 'name' => basename($inner)]);
    }

    if ($api === 'sheet_read') {
        $p = joinRoot(ROOT, $_GET['path'] ?? '');
        if ($p === false || !is_file($p)) out(['ok' => false, 'error' => 'notfound']);
        if (isCritical(basename($p))) out(['ok' => false, 'error' => 'notfound']);
        $ext = strtolower(pathinfo($p, PATHINFO_EXTENSION));
        $rows = [];
        if ($ext === 'csv' || $ext === 'tsv') {
            $delim = $ext === 'tsv' ? "\t" : ',';
            $fh = @fopen($p, 'r');
            if ($fh === false) out(['ok' => false, 'error' => 'open']);
            while (($row = fgetcsv($fh, 0, $delim)) !== false) $rows[] = array_map(function ($v) { return $v === null ? '' : $v; }, $row);
            fclose($fh);
        } elseif ($ext === 'xlsx') {
            $rows = xlsxRead($p);
            if ($rows === null) out(['ok' => false, 'error' => 'parse']);
        } else {
            out(['ok' => false, 'error' => 'unsupported']);
        }
        $maxc = 0;
        foreach ($rows as $r) $maxc = max($maxc, count($r));
        foreach ($rows as &$r) while (count($r) < $maxc) $r[] = '';
        unset($r);
        out(['ok' => true, 'rows' => $rows, 'ext' => $ext]);
    }

    if ($api === 'sheet_write') {
        $p = joinRoot(ROOT, $_POST['path'] ?? '');
        if ($p === false || !is_file($p)) out(['ok' => false, 'error' => 'notfound']);
        if (isCritical(basename($p))) out(['ok' => false, 'error' => 'notfound']);
        $rows = json_decode($_POST['rows'] ?? '[]', true);
        if (!is_array($rows)) out(['ok' => false, 'error' => 'bad']);
        $ext = strtolower(pathinfo($p, PATHINFO_EXTENSION));
        if ($ext === 'csv' || $ext === 'tsv') {
            $delim = $ext === 'tsv' ? "\t" : ',';
            $fh = @fopen($p, 'w');
            if ($fh === false) out(['ok' => false, 'error' => 'open']);
            foreach ($rows as $row) fputcsv($fh, array_map('strval', (array)$row), $delim);
            fclose($fh);
        } elseif ($ext === 'xlsx') {
            if (!xlsxWrite($p, $rows)) out(['ok' => false, 'error' => 'write']);
        } else {
            out(['ok' => false, 'error' => 'unsupported']);
        }
        out(['ok' => true]);
    }

    if ($api === 'raw') {
        $rel = $_GET['path'] ?? '';
        $showHidden = !empty($_GET['show_hidden']);
        if (!$showHidden && isHiddenPath($rel)) { http_response_code(404); exit; }
        if ($showHidden && safeRel($rel) === '') { http_response_code(404); exit; }
        if ($showHidden) { foreach (explode('/', safeRel($rel)) as $p) { if (isCritical($p)) { http_response_code(404); exit; } } }
        $p = joinRoot(ROOT, $rel);
        if ($p === false || !is_file($p)) { http_response_code(404); exit; }
        if (isCritical(basename($p))) { http_response_code(404); exit; }
        ob_clean();
        session_write_close();
        $ext = strtolower(pathinfo($p, PATHINFO_EXTENSION));
        $force = in_array($ext, ['svg','html','htm','xml','xhtml','php','phtml','phar'], true);
        $mime = function_exists('mime_content_type') ? (@mime_content_type($p) ?: 'application/octet-stream') : 'application/octet-stream';
        header('Content-Type: ' . ($force ? 'application/octet-stream' : $mime));
        header('Content-Length: ' . filesize($p));
        header('Content-Disposition: ' . ((isset($_GET['dl']) || $force) ? 'attachment' : 'inline') . '; filename="' . rawurlencode(basename($p)) . '"');
        readfile($p);
        exit;
    }

    out(['ok' => false, 'error' => 'unknown']);
}

$hasVault = vaultHash() !== null;
$unlocked = !empty($_SESSION['unlocked']);
if ($unlocked) ensureHtaccess();
$initialPath = isset($_GET['path']) ? safeRel($_GET['path']) : '';
$appBaseJson = json_encode(appBase());
?>
<!doctype html>
<html lang="en" class="h-full" data-theme="light">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no, viewport-fit=cover">
<meta name="theme-color" content="#f8f9fa">
<title>iWebFM</title>
<script src="https://cdn.tailwindcss.com"></script>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;700&display=swap" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@24,400,0,0&display=swap" rel="stylesheet">
<script src="https://cdnjs.cloudflare.com/ajax/libs/ace/1.32.6/ace.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/ace/1.32.6/ext-language_tools.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/ace/1.32.6/theme-github_dark.min.js"></script>
<script>
tailwind.config = { theme: { extend: {
  fontFamily: { sans: ['Roboto', 'system-ui', 'sans-serif'] },
  colors: { gblue: '#1a73e8', gsurf: '#f8f9fa', gtext: '#202124', gsub: '#5f6368' }
}}}
</script>
<style>
  :root {
    --bg: #f8f9fa;
    --surface: #ffffff;
    --text: #202124;
    --sub: #5f6368;
    --border: rgba(0,0,0,0.04);
    --hover: rgba(0,0,0,0.04);
    --accent-soft: #e8f0fe;
    --accent-soft-hover: #d2e3fc;
  }
  html[data-theme="dark"] {
    --bg: #0f1115;
    --surface: #1c1e23;
    --text: #e8eaed;
    --sub: #9aa0a6;
    --border: rgba(255,255,255,0.08);
    --hover: rgba(255,255,255,0.06);
    --accent-soft: #1a3a5f;
    --accent-soft-hover: #234a7a;
  }
  html[data-theme="eyecare"] {
    --bg: #f5ecd9;
    --surface: #fdf6e6;
    --text: #3a2e1f;
    --sub: #7a6850;
    --border: rgba(90,70,40,0.10);
    --hover: rgba(90,70,40,0.05);
    --accent-soft: #ffe9b5;
    --accent-soft-hover: #ffd98a;
  }
  html, body { -webkit-tap-highlight-color: transparent; overscroll-behavior-y: none; }
  body { background: var(--bg); color: var(--text); }
  .bg-gsurf { background: var(--bg) !important; }
  .bg-white { background: var(--surface) !important; }
  .text-gtext { color: var(--text) !important; }
  .text-gsub { color: var(--sub) !important; }
  .border-black\/\[0\.04\] { border-color: var(--border) !important; }
  .border-black\/\[0\.05\] { border-color: var(--border) !important; }
  .border-black\/\[0\.08\] { border-color: var(--border) !important; }
  .divide-black\/\[0\.05\] > * + * { border-color: var(--border) !important; }
  .hover\:bg-black\/5:hover { background: var(--hover) !important; }
  .hover\:bg-black\/\[0\.03\]:hover { background: var(--hover) !important; }
  .hover\:bg-black\/\[0\.04\]:hover { background: var(--hover) !important; }
  .hover\:bg-black\/\[0\.02\]:hover { background: var(--hover) !important; }
  .bg-\[\#e8f0fe\] { background: var(--accent-soft) !important; }
  .bg-gsurf\/90 { background: color-mix(in srgb, var(--bg) 90%, transparent) !important; }
  .msr { font-family: 'Material Symbols Rounded'; font-weight: normal; font-style: normal; line-height: 1; letter-spacing: normal; text-transform: none; display: inline-block; white-space: nowrap; word-wrap: normal; direction: ltr; -webkit-font-feature-settings: 'liga'; -webkit-font-smoothing: antialiased; font-variation-settings: 'FILL' 0, 'wght' 400, 'GRAD' 0, 'opsz' 24; }
  .msr.fill { font-variation-settings: 'FILL' 1, 'wght' 400, 'GRAD' 0, 'opsz' 24; }
  .no-sb::-webkit-scrollbar { display: none; }
  .no-sb { -ms-overflow-style: none; scrollbar-width: none; }
  #dotsG circle { transition: fill .16s ease, stroke .16s ease, r .2s cubic-bezier(.34,1.56,.64,1); }
  @keyframes shake { 0%,100%{transform:translateX(0)} 15%{transform:translateX(-9px)} 30%{transform:translateX(9px)} 45%{transform:translateX(-7px)} 60%{transform:translateX(7px)} 80%{transform:translateX(-3px)} }
  .shake { animation: shake .5s ease; }
  @keyframes pop { 0%{transform:scale(1)} 45%{transform:scale(1.12)} 100%{transform:scale(1)} }
  .pop { animation: pop .35s ease; }
  @keyframes rise { from{opacity:0;transform:translateY(10px)} to{opacity:1;transform:translateY(0)} }
  .rise { animation: rise .28s ease both; }
  .chip { transition: background .18s ease, color .18s ease, border-color .18s ease; }
  .card-tile { transition: transform .16s ease, box-shadow .2s ease; }
  .card-tile:active { transform: scale(.97); }
  @keyframes spin { to{transform:rotate(360deg)} }
  .spin { animation: spin .9s linear infinite; }
  [data-i] { position: relative; }
  [data-i].selected::after {
    content: ''; position: absolute; inset: 0;
    background: rgba(26,115,232,0.14);
    border: 2.5px solid #1a73e8;
    border-radius: 16px;
    pointer-events: none;
  }
  [data-i].selected .check-badge { display: flex; }
  .check-badge { display: none; position: absolute; top: 8px; left: 8px; width: 22px; height: 22px; border-radius: 50%; background: #1a73e8; color: white; align-items: center; justify-content: center; z-index: 2; }
  .check-badge .msr { font-size: 15px; }
  .list-row:active { background: var(--hover); }
  .img-dense { aspect-ratio: 1 / 1; }
  .sel-active [data-more] { display: none !important; }

  #imgStage img { max-width: 100%; max-height: 100%; object-fit: contain; transition: transform .3s ease; }

  .hex-body { font-family: ui-monospace, 'SF Mono', Menlo, Consolas, 'Liberation Mono', monospace; padding: 6px 0 24px; }
  .hex-container { max-width: 1040px; margin: 0 auto; padding: 0 10px; }
  .hex-row { display: flex; gap: 12px; align-items: center; padding: 2px 8px; border-radius: 4px; transition: background .1s ease; }
  .hex-row:hover { background: rgba(138,180,248,0.05); }
  .hex-offset { flex: 0 0 auto; width: 74px; color: rgba(138,180,248,0.55); user-select: none; font-size: 11.5px; letter-spacing: 0.5px; }
  .hex-bytes { flex: 1 1 auto; display: flex; gap: 2px; min-width: 0; }
  .hex-ascii { flex: 0 0 auto; width: 130px; text-align: right; color: #8ab4f8; letter-spacing: 1px; user-select: none; font-size: 11.5px; }
  .hex-cell {
    display: inline-block; min-width: 24px; text-align: center;
    padding: 1px 2px; border-radius: 3px; cursor: pointer;
    user-select: none; -webkit-user-select: none;
    transition: background 0.1s ease, color 0.1s ease;
  }
  .hex-cell:hover { background: rgba(138,180,248,0.22); }
  .hex-cell.sel { background: rgba(26,115,232,0.6); color: #fff; }
  .hex-cell.hit { background: rgba(251,188,4,0.6); color: #fff; }
  @media (min-width: 768px) {
    .hex-body { font-size: 13.5px; padding: 10px 0 32px; }
    .hex-container { padding: 0 20px; }
    .hex-row { gap: 20px; padding: 4px 14px; }
    .hex-offset { width: 100px; font-size: 13px; }
    .hex-bytes { gap: 4px; }
    .hex-cell { min-width: 32px; font-size: 13px; padding: 2px 3px; }
    .hex-ascii { width: 175px; font-size: 13px; letter-spacing: 1.5px; }
  }
  @media (min-width: 1200px) {
    .hex-container { max-width: 1200px; }
    .hex-offset { width: 120px; font-size: 13.5px; }
    .hex-cell { min-width: 36px; font-size: 14px; }
    .hex-ascii { width: 210px; font-size: 13.5px; letter-spacing: 2px; }
  }
  @media (max-width: 560px) {
    .hex-body { font-size: 11px; }
    .hex-offset { width: 62px; font-size: 10.5px; }
    .hex-cell { min-width: 21px; font-size: 10.5px; padding: 1px; }
    .hex-ascii { width: 80px; font-size: 10.5px; letter-spacing: 0.3px; }
    .hex-row { padding: 1px 4px; gap: 6px; }
  }

  .table-cell { min-width: 90px; }
  .table-cell:focus { outline: 2px solid #1a73e8; outline-offset: -2px; }

  .med-ctrl { transition: transform .12s ease, background .15s ease; }
  .med-ctrl:active { transform: scale(.9); }
  .seek-bar { cursor: pointer; }
  .audio-vis { display: flex; align-items: flex-end; gap: 2px; height: 40px; }
  .audio-vis span { flex: 1; background: linear-gradient(180deg,#8ab4f8,#1a73e8); border-radius: 2px 2px 0 0; transition: height .08s linear; }

  .theme-swatch { width: 100%; aspect-ratio: 1.6/1; border-radius: 14px; border: 2px solid transparent; cursor: pointer; overflow: hidden; transition: border-color .18s ease, transform .16s ease; }
  .theme-swatch.active { border-color: #1a73e8; }
  .theme-swatch:active { transform: scale(.98); }
</style>
</head>
<body class="h-full font-sans antialiased">

<div id="lockScreen" class="fixed inset-0 z-[70] flex flex-col items-center justify-center px-5 bg-[#0a1018] overflow-hidden <?= $unlocked ? 'hidden' : '' ?>">
  <div class="pointer-events-none absolute inset-0">
    <div class="absolute -top-40 -left-32 w-[420px] h-[420px] rounded-full bg-[#1a73e8]/25 blur-[120px]"></div>
    <div class="absolute -bottom-40 -right-24 w-[460px] h-[460px] rounded-full bg-[#8ab4f8]/20 blur-[130px]"></div>
    <div class="absolute top-1/3 left-1/2 -translate-x-1/2 w-[300px] h-[300px] rounded-full bg-[#34a853]/10 blur-[110px]"></div>
  </div>
  <div class="relative z-10 flex flex-col items-center w-full">
    <div id="lockBadge" class="w-16 h-16 rounded-[20px] bg-white/[0.06] border border-white/10 flex items-center justify-center mb-6 backdrop-blur-xl shadow-[0_8px_40px_rgba(26,115,232,0.25)]">
      <span id="lockIcon" class="msr text-white" style="font-size:28px">lock</span>
    </div>
    <h1 id="lockTitle" class="text-white text-[21px] font-medium tracking-tight text-center">Draw your pattern</h1>
    <p id="lockSub" class="text-white/45 text-[12.5px] mt-2 mb-7 text-center leading-relaxed px-4">Connect at least 4 dots</p>
    <div id="lockWrap" class="relative w-full max-w-[min(320px,82vw,50vh)] aspect-square">
      <svg id="lockSvg" viewBox="0 0 300 300" class="w-full h-full select-none" style="touch-action:none" oncontextmenu="return false">
        <g id="linesG"></g>
        <line id="trailEl" x1="0" y1="0" x2="0" y2="0" stroke="#8ab4f8" stroke-width="6" stroke-linecap="round" opacity="0"/>
        <g id="dotsG"></g>
      </svg>
    </div>
    <div id="lockMsg" class="mt-6 h-6 text-[12.5px] font-medium text-white/40 transition-colors duration-200"></div>
  </div>
</div>

<main id="app" class="<?= $unlocked ? '' : 'hidden' ?> min-h-full pb-28">
  <header id="headerMain" class="max-w-6xl mx-auto px-4 pt-5 pb-2 items-center justify-between hidden">
    <div class="flex items-center gap-2.5 min-w-0">
      <button id="mainBack" class="hidden w-10 h-10 -ml-2 rounded-full hover:bg-black/5 flex items-center justify-center text-gsub shrink-0">
        <span class="msr">arrow_back</span>
      </button>
      <div class="w-9 h-9 rounded-xl bg-[#e8f0fe] flex items-center justify-center shrink-0">
        <span class="msr text-gblue fill" style="font-size:22px">folder</span>
      </div>
      <h1 id="mainTitle" class="text-[20px] font-medium tracking-tight truncate">iWebFM</h1>
    </div>
    <button id="lockBtnHome" class="w-10 h-10 rounded-full hover:bg-black/5 flex items-center justify-center text-gsub shrink-0">
      <span class="msr" style="font-size:22px">lock</span>
    </button>
  </header>

  <header id="headerTree" class="sticky top-0 z-30 bg-gsurf/90 backdrop-blur-xl hidden">
    <div class="max-w-6xl mx-auto px-4 pt-3 pb-2">
      <div class="flex items-center gap-1.5">
        <button id="backBtn" class="w-10 h-10 -ml-1.5 rounded-full hover:bg-black/5 flex items-center justify-center text-gsub shrink-0">
          <span class="msr">arrow_back</span>
        </button>
        <div id="searchWrap" class="flex-1 flex items-center gap-2 bg-white rounded-full px-4 h-12 shadow-[0_1px_2px_rgba(60,64,67,.15)] border border-black/[0.03] min-w-0">
          <span class="msr text-gsub" style="font-size:21px">search</span>
          <input id="searchInput" type="text" placeholder="Search files" autocomplete="off" spellcheck="false"
                 class="flex-1 bg-transparent outline-none text-[15px] placeholder:text-gsub/70 min-w-0">
          <button id="clearSearch" class="hidden w-7 h-7 rounded-full hover:bg-black/5 flex items-center justify-center text-gsub">
            <span class="msr" style="font-size:18px">close</span>
          </button>
        </div>
        <div id="selectPill" class="hidden flex-1 items-center gap-3 bg-[#e8f0fe] rounded-full px-3 h-12 min-w-0">
          <button id="selectExitBtn" class="w-8 h-8 rounded-full hover:bg-black/5 flex items-center justify-center text-gblue shrink-0">
            <span class="msr" style="font-size:20px">close</span>
          </button>
          <span id="selectCountText" class="font-medium text-gblue text-[14px] truncate">0 selected</span>
        </div>
        <button id="sortBtn" class="w-10 h-10 rounded-full hover:bg-black/5 flex items-center justify-center text-gsub shrink-0">
          <span class="msr" style="font-size:22px">sort</span>
        </button>
        <button id="lockBtn" class="w-10 h-10 rounded-full hover:bg-black/5 flex items-center justify-center text-gsub shrink-0">
          <span class="msr" style="font-size:22px">lock</span>
        </button>
      </div>
    </div>
    <div id="chips" class="max-w-6xl mx-auto px-4 pt-3 pb-3 flex gap-2 overflow-x-auto no-sb hidden"></div>
  </header>

  <div class="max-w-6xl mx-auto px-4">
    <div id="crumb" class="hidden items-center gap-1.5 text-[13px] text-gsub mb-3 px-1"></div>
    <div id="content"></div>
  </div>
</main>

<button id="fab" class="<?= $unlocked ? '' : 'hidden' ?> hidden fixed bottom-6 right-5 md:right-8 z-40 w-14 h-14 rounded-[20px] bg-gblue text-white shadow-[0_6px_20px_rgba(26,115,232,.45)] flex items-center justify-center active:scale-95 transition-transform">
  <span class="msr" style="font-size:27px">add</span>
</button>

<div id="fabMenu" class="hidden fixed inset-0 z-[55] bg-black/25 backdrop-blur-[2px]">
  <div class="absolute bottom-[100px] right-5 md:right-8 flex flex-col items-end gap-3">
    <button data-act="empty" class="rise flex items-center gap-3 pl-5 pr-4 py-3 bg-white rounded-2xl shadow-[0_4px_20px_rgba(60,64,67,.25)] active:scale-95 transition-transform">
      <span class="text-[14px] font-medium">Empty file</span>
      <span class="msr text-gblue" style="font-size:21px">note_add</span>
    </button>
    <button data-act="folder" class="rise flex items-center gap-3 pl-5 pr-4 py-3 bg-white rounded-2xl shadow-[0_4px_20px_rgba(60,64,67,.25)] active:scale-95 transition-transform">
      <span class="text-[14px] font-medium">New folder</span>
      <span class="msr text-gblue" style="font-size:21px">create_new_folder</span>
    </button>
    <button data-act="upload" class="rise flex items-center gap-3 pl-5 pr-4 py-3 bg-white rounded-2xl shadow-[0_4px_20px_rgba(60,64,67,.25)] active:scale-95 transition-transform">
      <span class="text-[14px] font-medium">Upload files</span>
      <span class="msr text-gblue" style="font-size:21px">upload</span>
    </button>
  </div>
</div>

<input id="fileInput" type="file" multiple class="hidden">

<div id="sheet" class="hidden fixed inset-0 z-[60]">
  <div class="absolute inset-0 bg-black/40 backdrop-blur-[2px]" data-close></div>
  <div class="absolute bottom-0 inset-x-0 md:bottom-6 md:left-1/2 md:-translate-x-1/2 md:max-w-md md:rounded-3xl bg-white rounded-t-3xl overflow-hidden shadow-[0_-4px_40px_rgba(0,0,0,.25)] rise">
    <div class="w-9 h-1 bg-black/15 rounded-full mx-auto mt-3 mb-1 md:hidden"></div>
    <div id="sheetTitle" class="px-5 py-3.5 text-[15px] font-medium truncate"></div>
    <div id="sheetList" class="pb-4 max-h-[55vh] overflow-y-auto"></div>
  </div>
</div>

<div id="dialog" class="hidden fixed inset-0 z-[65] flex items-center justify-center p-6">
  <div class="absolute inset-0 bg-black/40 backdrop-blur-[2px]" data-close></div>
  <div class="relative w-full max-w-sm bg-white rounded-3xl p-6 shadow-2xl rise">
    <div id="dlgTitle" class="text-[17px] font-medium mb-1"></div>
    <div id="dlgSub" class="text-[12.5px] text-gsub mb-4"></div>
    <input id="dlgInput" type="text" class="w-full bg-gsurf rounded-xl px-4 py-3 outline-none text-[15px] border border-transparent focus:border-gblue transition-colors">
    <div class="flex justify-end gap-1 mt-5">
      <button id="dlgCancel" class="px-5 py-2.5 rounded-full text-[14px] font-medium text-gblue hover:bg-gblue/[0.08]">Cancel</button>
      <button id="dlgOk" class="px-5 py-2.5 rounded-full text-[14px] font-medium text-gblue hover:bg-gblue/[0.08]">OK</button>
    </div>
  </div>
</div>

<div id="viewer" class="hidden fixed inset-0 z-[68] bg-[#0a0a0a] flex flex-col">
  <div class="flex items-center gap-3 px-4 py-3 text-white shrink-0">
    <button id="viewerClose" class="w-10 h-10 rounded-full hover:bg-white/10 flex items-center justify-center">
      <span class="msr">close</span>
    </button>
    <div id="viewerName" class="flex-1 truncate text-[14px] font-medium"></div>
    <button id="viewerDl" class="w-10 h-10 rounded-full hover:bg-white/10 flex items-center justify-center">
      <span class="msr">download</span>
    </button>
  </div>
  <div id="viewerBody" class="flex-1 flex items-center justify-center p-4 overflow-auto"></div>
</div>

<div id="mediaViewer" class="hidden fixed inset-0 z-[68] bg-black flex flex-col text-white">
  <div class="flex items-center gap-3 px-4 py-3 shrink-0">
    <button id="mediaClose" class="w-10 h-10 rounded-full hover:bg-white/10 flex items-center justify-center">
      <span class="msr">close</span>
    </button>
    <div id="mediaName" class="flex-1 truncate text-[14px] font-medium"></div>
    <button id="mediaDl" class="w-10 h-10 rounded-full hover:bg-white/10 flex items-center justify-center">
      <span class="msr">download</span>
    </button>
  </div>
  <div id="mediaStage" class="flex-1 flex items-center justify-center overflow-hidden relative"></div>
  <div id="mediaControls" class="shrink-0 px-4 pb-5 pt-3 bg-gradient-to-t from-black/90 to-transparent"></div>
</div>

<div id="imageViewer" class="hidden fixed inset-0 z-[68] bg-black flex flex-col text-white select-none">
  <div class="flex items-center gap-3 px-4 py-3 shrink-0 absolute top-0 inset-x-0 z-10 bg-gradient-to-b from-black/85 to-transparent">
    <button id="imageClose" class="w-10 h-10 rounded-full hover:bg-white/10 flex items-center justify-center">
      <span class="msr">close</span>
    </button>
    <div id="imageName" class="flex-1 truncate text-[14px] font-medium"></div>
    <button id="imageFlip" class="w-10 h-10 rounded-full hover:bg-white/10 flex items-center justify-center" title="Flip horizontal">
      <span class="msr">flip</span>
    </button>
    <button id="imageDl" class="w-10 h-10 rounded-full hover:bg-white/10 flex items-center justify-center">
      <span class="msr">download</span>
    </button>
  </div>
  <div id="imgStage" class="flex-1 flex items-center justify-center overflow-hidden p-4">
    <img id="imgEl" src="" draggable="false">
  </div>
</div>

<div id="codeViewer" class="hidden fixed inset-0 z-[68] bg-[#0d1117] flex flex-col text-white">
  <div class="flex items-center gap-3 px-4 py-3 shrink-0 border-b border-white/10">
    <button id="codeClose" class="w-10 h-10 rounded-full hover:bg-white/10 flex items-center justify-center"><span class="msr">close</span></button>
    <div class="flex-1 min-w-0">
      <div id="codeName" class="text-[14px] font-medium truncate">file</div>
      <div id="codeMeta" class="text-[11px] text-white/50 mt-0.5">plain text</div>
    </div>
    <button id="codeEdit" class="px-4 h-9 rounded-full bg-white/10 hover:bg-white/20 text-white text-[13px] font-medium">Edit</button>
    <button id="codeSave" class="px-4 h-9 rounded-full bg-[#238636] hover:bg-[#2ea043] text-[13px] font-medium hidden">Save</button>
    <button id="codeDl" class="w-10 h-10 rounded-full hover:bg-white/10 flex items-center justify-center"><span class="msr">download</span></button>
  </div>
  <div id="codeEditor" class="flex-1 min-h-0"></div>
</div>

<div id="hexViewer" class="hidden fixed inset-0 z-[68] bg-[#0d1117] flex flex-col text-white">
  <div class="flex items-center gap-2 px-3 py-2.5 shrink-0 border-b border-white/10">
    <button id="hexClose" class="w-10 h-10 rounded-full hover:bg-white/10 flex items-center justify-center"><span class="msr">close</span></button>
    <div class="flex-1 min-w-0">
      <div id="hexName" class="text-[14px] font-medium truncate">file</div>
      <div id="hexMeta" class="text-[11px] text-white/50 mt-0.5">0 bytes</div>
    </div>
    <button id="hexSearch" class="px-3 h-9 rounded-full bg-white/10 hover:bg-white/20 text-[13px] font-medium flex items-center gap-1.5">
      <span class="msr" style="font-size:18px">search</span>
      <span class="hidden sm:inline">Search</span>
    </button>
    <button id="hexSave" class="px-3 h-9 rounded-full bg-[#238636] hover:bg-[#2ea043] text-[13px] font-medium">Save</button>
    <button id="hexDl" class="w-10 h-10 rounded-full hover:bg-white/10 flex items-center justify-center"><span class="msr">download</span></button>
  </div>
  <div class="flex items-center gap-2 px-4 py-2 shrink-0 text-[11px] text-white/60 border-b border-white/10 flex-wrap">
    <button id="hexPrev" class="px-3 py-1 rounded-full bg-white/10 hover:bg-white/20">Prev</button>
    <button id="hexNext" class="px-3 py-1 rounded-full bg-white/10 hover:bg-white/20">Next</button>
    <span class="text-white/40 ml-auto">Tap/click a byte to select, drag to extend, right-click for actions.</span>
  </div>
  <div id="hexBody" class="flex-1 overflow-auto hex-body"></div>
</div>

<div id="sheetViewer" class="hidden fixed inset-0 z-[68] bg-gsurf flex flex-col">
  <div class="flex items-center gap-3 px-4 py-3 shrink-0 bg-white border-b border-black/5">
    <button id="sheetClose" class="w-10 h-10 rounded-full hover:bg-black/5 flex items-center justify-center text-gsub"><span class="msr">close</span></button>
    <div class="flex-1 min-w-0">
      <div id="sheetName" class="text-[14px] font-medium truncate">table</div>
      <div id="sheetMeta" class="text-[11px] text-gsub mt-0.5">0 rows</div>
    </div>
    <button id="sheetRowAdd" class="px-3 h-9 rounded-full bg-[#e8f0fe] text-gblue text-[12.5px] font-medium">+ Row</button>
    <button id="sheetColAdd" class="px-3 h-9 rounded-full bg-[#e8f0fe] text-gblue text-[12.5px] font-medium">+ Col</button>
    <button id="sheetSave" class="px-4 h-9 rounded-full bg-[#188038] text-white text-[13px] font-medium">Save</button>
  </div>
  <div id="sheetBody" class="flex-1 overflow-auto"></div>
</div>

<div id="archiveViewer" class="hidden fixed inset-0 z-[68] bg-gsurf flex flex-col">
  <div class="flex items-center gap-3 px-4 py-3 shrink-0 bg-white border-b border-black/5">
    <button id="archiveClose" class="w-10 h-10 rounded-full hover:bg-black/5 flex items-center justify-center text-gsub"><span class="msr">close</span></button>
    <div class="flex-1 min-w-0">
      <div id="archiveName" class="text-[14px] font-medium truncate">archive.zip</div>
      <div id="archiveMeta" class="text-[11px] text-gsub mt-0.5">0 files</div>
    </div>
  </div>
  <div id="archivePw" class="hidden px-4 py-3 bg-[#fef7e0] border-b border-[#fbbc04]/30 flex items-center gap-3">
    <span class="msr text-[#e37400]" style="font-size:20px">lock</span>
    <input id="archivePwInput" type="password" placeholder="Archive password" class="flex-1 bg-white rounded-lg px-3 py-2 outline-none text-[13px] border border-black/10">
    <button id="archivePwGo" class="px-4 py-2 rounded-full bg-[#e37400] text-white text-[12.5px] font-medium">Unlock</button>
  </div>
  <div id="archiveBody" class="flex-1 overflow-auto"></div>
</div>

<div id="hexSearchModal" class="hidden fixed inset-0 z-[80] flex items-center justify-center p-4">
  <div class="absolute inset-0 bg-black/60" data-close></div>
  <div class="relative w-full max-w-md bg-[#161b22] rounded-2xl shadow-2xl p-5 text-white">
    <div class="text-[15px] font-medium mb-3">Search</div>
    <div class="flex gap-1 bg-white/5 rounded-lg p-1 mb-3">
      <button data-tab="offset" class="hs-tab flex-1 py-1.5 rounded-md text-[12.5px]">Offset</button>
      <button data-tab="hash" class="hs-tab flex-1 py-1.5 rounded-md text-[12.5px]">Hash</button>
      <button data-tab="ascii" class="hs-tab flex-1 py-1.5 rounded-md text-[12.5px]">Ascii</button>
    </div>
    <input id="hexSearchInput" type="text" autocomplete="off" spellcheck="false"
           class="w-full bg-white/5 text-white rounded-lg px-3 py-2.5 outline-none text-[14px] border border-white/10 focus:border-[#8ab4f8] mb-3">
    <div class="flex flex-wrap gap-3 mb-4 text-[12.5px]">
      <label class="flex items-center gap-1.5 text-white/70"><input type="radio" name="hexSearchOpt" value="backward"> Backward</label>
      <label class="flex items-center gap-1.5 text-white/70"><input type="radio" name="hexSearchOpt" value="all"> All</label>
      <label class="flex items-center gap-1.5 text-white/70"><input type="radio" name="hexSearchOpt" value="forward" checked> Forward</label>
    </div>
    <div class="flex justify-end gap-2">
      <button id="hexSearchCancel" class="px-4 py-2 rounded-full text-[13px] text-white/70 hover:bg-white/10">Cancel</button>
      <button id="hexSearchGo" class="px-4 py-2 rounded-full bg-[#1a73e8] text-white text-[13px] font-medium hover:bg-[#1557b0]">Search</button>
    </div>
  </div>
</div>

<div id="toast" class="hidden fixed bottom-24 left-1/2 -translate-x-1/2 z-[90] bg-[#202124] text-white text-[13px] px-5 py-3 rounded-xl shadow-2xl"></div>

<script>
const HAS_VAULT = <?= $hasVault ? 'true' : 'false' ?>;
const IS_UNLOCKED = <?= $unlocked ? 'true' : 'false' ?>;
const NS = 'http://www.w3.org/2000/svg';
const GB = 1024 * 1024 * 1024;
const APP_BASE = <?= $appBaseJson ?>;

function $(id) { return document.getElementById(id); }
function esc(s) { return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }
function fmtSize(b) {
  b = Number(b) || 0;
  if (b <= 0) return '0 B';
  const u = ['B','KB','MB','GB','TB','PB'];
  const i = Math.min(u.length - 1, Math.floor(Math.log(b) / Math.log(1024)));
  return (b / Math.pow(1024, i)).toFixed(i ? 2 : 0) + ' ' + u[i];
}
function fmtDate(t) {
  const d = new Date(t * 1000);
  const diff = (Date.now() - d) / 1000;
  if (diff < 60) return 'Just now';
  if (diff < 3600) return Math.floor(diff / 60) + 'm ago';
  if (diff < 86400) return Math.floor(diff / 3600) + 'h ago';
  if (diff < 604800) return Math.floor(diff / 86400) + 'd ago';
  return d.toLocaleDateString();
}
function fmtTime(s) {
  s = Math.max(0, Math.floor(s || 0));
  const m = Math.floor(s / 60), sec = s % 60;
  if (m >= 60) { const h = Math.floor(m / 60); return h + ':' + String(m % 60).padStart(2, '0') + ':' + String(sec).padStart(2, '0'); }
  return m + ':' + String(sec).padStart(2, '0');
}
function toast(msg) {
  const t = $('toast');
  t.textContent = msg;
  t.classList.remove('hidden');
  t.classList.add('rise');
  clearTimeout(t._t);
  t._t = setTimeout(() => { t.classList.add('hidden'); t.classList.remove('rise'); }, 2200);
}
function buildFolderUrl(path) {
  return APP_BASE + '/' + (path ? path.split('/').map(encodeURIComponent).join('/') : '');
}
async function post(action, data) {
  const fd = new FormData();
  for (const k in (data || {})) {
    const v = data[k];
    if (Array.isArray(v)) fd.append(k, JSON.stringify(v)); else fd.append(k, v);
  }
  try {
    const r = await fetch('?api=' + action, { method: 'POST', body: fd, credentials: 'same-origin' });
    return await r.json();
  } catch (e) { return { ok: false, error: 'network' }; }
}
async function get(action, params) {
  const q = new URLSearchParams(params || {});
  try {
    const r = await fetch('?api=' + action + '&' + q.toString(), { credentials: 'same-origin' });
    return await r.json();
  } catch (e) { return { ok: false, error: 'network' }; }
}
function extOf(name) { const i = name.lastIndexOf('.'); return i >= 0 ? name.slice(i + 1).toLowerCase() : ''; }

const CATS = {
  image:    { icon: 'image',        bg: '#e8f0fe', fg: '#1a73e8', label: 'Images',    color: '#1a73e8' },
  video:    { icon: 'movie',        bg: '#fce8e6', fg: '#d93025', label: 'Videos',    color: '#ea4335' },
  audio:    { icon: 'audiotrack',   bg: '#fef7e0', fg: '#e37400', label: 'Audio',     color: '#fbbc04' },
  document: { icon: 'description',  bg: '#e6f4ea', fg: '#188038', label: 'Documents', color: '#34a853' },
  archive:  { icon: 'folder_zip',   bg: '#f3e8fd', fg: '#8430ce', label: 'Archives',  color: '#a142f4' },
  apk:      { icon: 'android',      bg: '#e4f7fb', fg: '#12909f', label: 'Apps',      color: '#24c1e0' },
  other:    { icon: 'draft',        bg: '#f1f3f4', fg: '#5f6368', label: 'Others',    color: '#9aa0a6' },
  folder:   { icon: 'folder',       bg: '#e8f0fe', fg: '#1a73e8', label: 'Folders',   color: '#1a73e8' },
  settings: { icon: 'settings',     bg: '#f1f3f4', fg: '#5f6368', label: 'Settings',  color: '#5f6368' },
  about:    { icon: 'info',         bg: '#e8f0fe', fg: '#1a73e8', label: 'About',     color: '#1a73e8' }
};

const ACE_MODES = {
  js:'javascript', mjs:'javascript', cjs:'javascript', jsx:'jsx',
  ts:'typescript', tsx:'typescript', mts:'typescript', cts:'typescript',
  py:'python', pyw:'python', rb:'ruby', rake:'ruby', gemspec:'ruby',
  php:'php', phtml:'php', java:'java', kt:'kotlin', kts:'kotlin', scala:'scala',
  c:'c_cpp', h:'c_cpp', cpp:'c_cpp', cc:'c_cpp', cxx:'c_cpp', hpp:'c_cpp', hh:'c_cpp', ino:'c_cpp',
  cs:'csharp', go:'golang', rs:'rust', swift:'swift', dart:'dart',
  html:'html', htm:'html', xhtml:'html', vue:'html', svelte:'html', astro:'html',
  css:'css', scss:'scss', sass:'scss', less:'less', styl:'stylus', stylus:'stylus',
  json:'json', jsonc:'json', json5:'json5',
  xml:'xml', svg:'xml', xslt:'xml', xsl:'xml', plist:'xml', rss:'xml',
  yml:'yaml', yaml:'yaml', md:'markdown', markdown:'markdown', mdx:'markdown',
  sql:'sql', sh:'sh', bash:'sh', zsh:'sh', fish:'sh', ksh:'sh',
  ps1:'powershell', psm1:'powershell', psd1:'powershell',
  bat:'batchfile', cmd:'batchfile',
  dockerfile:'dockerfile', containerfile:'dockerfile',
  ini:'ini', cfg:'ini', conf:'ini', properties:'properties', toml:'toml',
  lua:'lua', pl:'perl', pm:'perl', t:'perl',
  r:'r', hs:'haskell', lhs:'haskell', clj:'clojure', cljs:'clojure', cljc:'clojure', edn:'clojure',
  erl:'erlang', hrl:'erlang', ex:'elixir', exs:'elixir', jl:'julia',
  m:'matlab', mlx:'matlab', groovy:'groovy', gradle:'groovy',
  tf:'terraform', tfvars:'terraform', hcl:'terraform',
  proto:'protobuf', graphql:'graphql', gql:'graphql', sol:'solidity',
  asm:'assembly_x86', s:'assembly_x86', v:'verilog', sv:'verilog', svh:'verilog', vh:'verilog',
  vhd:'vhdl', vhdl:'vhdl', tex:'latex', latex:'latex', bib:'latex',
  rst:'rst', tcl:'tcl', mk:'makefile', makefile:'makefile', d:'d', nim:'nim',
  pas:'pascal', pp:'pascal', f:'fortran', f90:'fortran', f95:'fortran', f03:'fortran', for:'fortran',
  cob:'cobol', cbl:'cobol', ada:'ada', adb:'ada', ads:'ada',
  applescript:'applescript', scpt:'applescript', ahk:'autohotkey', nix:'nix',
  re:'ocaml', ml:'ocaml', mli:'ocaml', fs:'fsharp', fsx:'fsharp', fsi:'fsharp',
  vb:'vbscript', vbs:'vbscript', asp:'vbscript', jsp:'jsp', ejs:'ejs',
  hbs:'handlebars', handlebars:'handlebars', twig:'twig', liquid:'liquid', razor:'razor',
  env:'dotenv', gitignore:'gitignore', gitattributes:'gitignore', gitmodules:'gitignore', editorconfig:'ini',
  htaccess:'apache_conf', htpasswd:'apache_conf', nginxconf:'nginx', crontab:'crontab',
  csv:'text', tsv:'text', txt:'text', log:'text', readme:'text', license:'text',
  authors:'text', changelog:'text', notic:'text'
};

const TEXT_EXTS = new Set(['txt','md','markdown','log','ini','cfg','conf','env','yml','yaml','toml','json','xml','csv','tsv','rtf','sql','properties','gitignore','editorconfig','htaccess','bashrc','zshrc','vimrc','npmrc','babelrc','eslintrc','prettierrc','license','readme','changelog','authors','notice','dockerignore','gitattributes','gitmodules','cmd','bat','html','htm','xhtml','css','scss','sass','less','styl','stylus','js','mjs','cjs','jsx','ts','tsx','mts','cts','vue','svelte','astro','php','phtml','py','pyw','rb','java','kt','kts','scala','c','h','cpp','cc','cxx','hpp','hh','hxx','ino','cs','go','rs','swift','dart','sh','bash','zsh','fish','ksh','ps1','psm1','psd1','lua','pl','pm','t','r','hs','lhs','clj','cljs','cljc','edn','erl','hrl','ex','exs','jl','m','mlx','groovy','gradle','gvy','tf','tfvars','hcl','proto','graphql','gql','sol','asm','s','v','sv','svh','vh','vhd','vhdl','tex','latex','bib','rst','rest','tcl','mk','makefile','mak','d','nim','pas','pp','f','f90','f95','f03','for','cob','cbl','ada','adb','ads','applescript','scpt','ahk','nix','re','ml','mli','mll','mly','fs','fsx','fsi','vb','vbs','asp','jsp','ejs','hbs','handlebars','twig','liquid','razor','nginxconf','crontab','dockerfile','containerfile','htpasswd','svelte','astro']);
const SHEET_EXTS = new Set(['csv','tsv','xlsx']);
const ARCHIVE_EXTS = new Set(['zip','rar','7z','tar','gz','tgz','bz2','xz','iso']);

function aceModeFor(name) {
  const e = extOf(name);
  if (ACE_MODES[e]) return ACE_MODES[e];
  if (e === '') {
    const n = name.toLowerCase();
    if (n === 'makefile' || n === 'gnumakefile') return 'makefile';
    if (n === 'dockerfile' || n === 'containerfile') return 'dockerfile';
    if (n === 'rakefile' || n === 'gemfile') return 'ruby';
    if (n === 'readme' || n === 'license' || n === 'changelog') return 'text';
  }
  return 'text';
}

const loadedAceModes = new Set();
function ensureAceMode(mode, cb) {
  if (loadedAceModes.has(mode)) return cb();
  if (mode === 'text' || mode === 'plain_text') { loadedAceModes.add(mode); return cb(); }
  const url = 'https://cdnjs.cloudflare.com/ajax/libs/ace/1.32.6/mode-' + mode + '.min.js';
  const s = document.createElement('script');
  s.src = url;
  s.onload = () => { loadedAceModes.add(mode); cb(); };
  s.onerror = () => { loadedAceModes.add(mode); cb(); };
  document.head.appendChild(s);
}

function applyTheme(theme) {
  document.documentElement.setAttribute('data-theme', theme);
  try { localStorage.setItem('iwebfm_theme', theme); } catch (e) {}
}
(function initTheme() {
  let t = 'light';
  try { t = localStorage.getItem('iwebfm_theme') || 'light'; } catch (e) {}
  applyTheme(t);
})();

(function initLock() {
  const svgEl = $('lockSvg');
  const dotsG = $('dotsG');
  const linesG = $('linesG');
  const trailEl = $('trailEl');
  const wrap = $('lockWrap');
  const msg = $('lockMsg');
  const title = $('lockTitle');
  const sub = $('lockSub');
  const icon = $('lockIcon');
  const badge = $('lockBadge');
  const BASE_R = 12, ACTIVE_R = 16, HIT_R = 40;
  const PTS = [];
  for (let r = 0; r < 3; r++) for (let c = 0; c < 3; c++) PTS.push({ n: r * 3 + c + 1, x: 50 + c * 100, y: 50 + r * 100 });
  PTS.forEach(p => {
    const circ = document.createElementNS(NS, 'circle');
    circ.setAttribute('cx', p.x); circ.setAttribute('cy', p.y); circ.setAttribute('r', BASE_R);
    circ.setAttribute('fill', 'rgba(255,255,255,0.07)');
    circ.setAttribute('stroke', 'rgba(255,255,255,0.22)');
    circ.setAttribute('stroke-width', '2');
    dotsG.appendChild(circ);
    p.circle = circ;
  });
  let seq = [], drawing = false;
  let mode = HAS_VAULT ? 'unlock' : 'setup';
  let firstPattern = null, busy = false;
  if (mode === 'setup') { title.textContent = 'Create your pattern'; sub.textContent = 'Connect at least 4 dots to secure your files'; icon.textContent = 'draw'; }
  function point(e) { const r = svgEl.getBoundingClientRect(); return { x: (e.clientX - r.left) / r.width * 300, y: (e.clientY - r.top) / r.height * 300 }; }
  function hitDot(pt) { let best = null, bd = 1e9; for (const p of PTS) { const d = Math.hypot(p.x - pt.x, p.y - pt.y); if (d < HIT_R && d < bd) { bd = d; best = p; } } return best; }
  function midDot(a, b) { const A = PTS.find(p => p.n === a), B = PTS.find(p => p.n === b); const mx = (A.x + B.x) / 2, my = (A.y + B.y) / 2; return PTS.find(p => p.x === mx && p.y === my) || null; }
  function paint(p, on, color) {
    p.circle.setAttribute('fill', on ? (color || '#1a73e8') : 'rgba(255,255,255,0.07)');
    p.circle.setAttribute('stroke', on ? (color || '#1a73e8') : 'rgba(255,255,255,0.22)');
    p.circle.setAttribute('r', on ? ACTIVE_R : BASE_R);
  }
  function reset() { seq = []; linesG.innerHTML = ''; trailEl.setAttribute('opacity', 0); PTS.forEach(p => paint(p, false)); }
  function addDot(n, silent) {
    if (seq.includes(n)) return;
    const p = PTS.find(x => x.n === n);
    if (seq.length) {
      const prev = PTS.find(x => x.n === seq[seq.length - 1]);
      const l = document.createElementNS(NS, 'line');
      l.setAttribute('x1', prev.x); l.setAttribute('y1', prev.y);
      l.setAttribute('x2', p.x); l.setAttribute('y2', p.y);
      l.setAttribute('stroke', '#8ab4f8'); l.setAttribute('stroke-width', '6');
      l.setAttribute('stroke-linecap', 'round'); l.setAttribute('opacity', '0.38');
      linesG.appendChild(l);
    }
    seq.push(n); paint(p, true);
    if (!silent && navigator.vibrate) { try { navigator.vibrate(8); } catch (e) {} }
  }
  function move(pt) {
    const d = hitDot(pt);
    if (d && !seq.includes(d.n)) {
      if (seq.length) { const m = midDot(seq[seq.length - 1], d.n); if (m && !seq.includes(m.n)) addDot(m.n, true); }
      addDot(d.n);
    }
    if (seq.length) {
      const last = PTS.find(x => x.n === seq[seq.length - 1]);
      trailEl.setAttribute('x1', last.x); trailEl.setAttribute('y1', last.y);
      trailEl.setAttribute('x2', pt.x); trailEl.setAttribute('y2', pt.y);
      trailEl.setAttribute('opacity', '0.55');
    }
  }
  function setMsg(text, color) { msg.textContent = text; msg.style.color = color || 'rgba(255,255,255,0.4)'; }
  function shake() { wrap.classList.remove('shake'); void wrap.offsetWidth; wrap.classList.add('shake'); if (navigator.vibrate) { try { navigator.vibrate([40, 60, 40]); } catch (e) {} } }
  function successFlash() {
    PTS.forEach(p => { if (seq.includes(p.n)) paint(p, true, '#34a853'); });
    linesG.querySelectorAll('line').forEach(l => l.setAttribute('stroke', '#34a853'));
    trailEl.setAttribute('opacity', 0); setMsg('Unlocked', '#5ecb7e'); badge.classList.add('pop');
  }
  svgEl.addEventListener('pointerdown', e => {
    if (busy) return; e.preventDefault();
    try { svgEl.setPointerCapture(e.pointerId); } catch (err) {}
    drawing = true; reset(); setMsg(''); move(point(e));
  });
  svgEl.addEventListener('pointermove', e => { if (!drawing) return; e.preventDefault(); move(point(e)); });
  function finish() {
    if (!drawing) return;
    drawing = false; trailEl.setAttribute('opacity', 0);
    const code = seq.join('');
    if (code.length < 4) { shake(); setMsg('Connect at least 4 dots', '#f28b82'); reset(); return; }
    submit(code);
  }
  svgEl.addEventListener('pointerup', finish);
  svgEl.addEventListener('pointercancel', finish);
  async function submit(code) {
    if (mode === 'unlock') {
      busy = true; setMsg('Checking…', 'rgba(255,255,255,0.5)');
      const r = await post('unlock', { pattern: code });
      if (r.ok) { successFlash(); setTimeout(() => enterApp(), 520); }
      else { busy = false; shake(); setMsg('Wrong pattern. Try again.', '#f28b82'); reset(); }
      return;
    }
    if (!firstPattern) {
      firstPattern = code; title.textContent = 'Confirm your pattern';
      sub.textContent = 'Draw the same pattern once more';
      setMsg('Now draw it again', '#8ab4f8');
      setTimeout(() => { if (!drawing) reset(); }, 260);
      return;
    }
    if (firstPattern !== code) {
      firstPattern = null; title.textContent = 'Create your pattern';
      sub.textContent = 'Connect at least 4 dots to secure your files';
      shake(); setMsg('Patterns did not match. Start over.', '#f28b82'); reset();
      return;
    }
    busy = true;
    const r = await post('setup', { pattern: code, confirm: code });
    if (r.ok) { successFlash(); setTimeout(() => enterApp(), 520); }
    else {
      busy = false; firstPattern = null;
      title.textContent = 'Create your pattern';
      sub.textContent = 'Connect at least 4 dots to secure your files';
      shake(); setMsg('Could not save. Try again.', '#f28b82'); reset();
    }
  }
  function enterApp() {
    $('lockScreen').style.transition = 'opacity .35s ease';
    $('lockScreen').style.opacity = '0';
    setTimeout(() => {
      $('lockScreen').classList.add('hidden');
      $('app').classList.remove('hidden');
      startApp();
    }, 340);
  }
})();

const state = {
  view: 'home',
  path: '',
  category: null,
  filter: 'all',
  sort: 'name',
  layout: 'list',
  showHidden: false,
  items: [],
  search: '',
  searching: false,
  selecting: false,
  selected: new Set(),
  quota: 5 * GB,
  theme: 'light'
};
try { state.theme = localStorage.getItem('iwebfm_theme') || 'light'; } catch (e) {}

const HASH_CATS = { videos: 'video', images: 'image', audio: 'audio', archives: 'archive', documents: 'document' };

function routeFromUrl() {
  const hash = (location.hash || '').replace(/^#/, '');
  let path = location.pathname || '';
  const appBaseWithPhp = APP_BASE + '.php';
  if (path.indexOf(appBaseWithPhp) === 0) {
    path = path.slice(appBaseWithPhp.length);
  } else if (path.indexOf(APP_BASE) === 0) {
    path = path.slice(APP_BASE.length);
  }
  path = path.replace(/^\/+/, '').replace(/\/+$/, '');
  try { path = decodeURIComponent(path); } catch (e) {}

  if (hash === 'settings') { state.view = 'settings'; state.path = ''; state.category = null; return; }
  if (hash === 'about') { state.view = 'about'; state.path = ''; state.category = null; return; }
  if (HASH_CATS[hash]) { state.view = 'category'; state.category = HASH_CATS[hash]; state.path = ''; return; }
  if (path) { state.view = 'folder'; state.path = path; state.category = null; return; }
  if (hash === 'files') { state.view = 'folder'; state.path = ''; state.category = null; return; }
  state.view = 'home'; state.path = ''; state.category = null;
}

function navigate(url, opts) {
  opts = opts || {};
  if (opts.replace) history.replaceState(null, '', url);
  else history.pushState(null, '', url);
  routeFromUrl();
  load();
}

function goHome() { navigate(APP_BASE + '/'); }
function goSettings() { navigate(APP_BASE + '/#settings'); }
function goAbout() { navigate(APP_BASE + '/#about'); }
function goCategory(key) { navigate(APP_BASE + '/#' + key); }
function goFolderRoot() { navigate(APP_BASE + '/#files'); }
function goFolder(path) {
  if (!path) return goFolderRoot();
  navigate(buildFolderUrl(path));
}

function renderHeader() {
  const isTree = state.view === 'folder' || state.searching;
  $('headerMain').classList.toggle('hidden', isTree);
  $('headerMain').classList.toggle('flex', !isTree);
  $('headerTree').classList.toggle('hidden', !isTree);
  $('fab').classList.toggle('hidden', state.view !== 'folder');
  if (!isTree) {
    let title = 'iWebFM';
    if (state.view === 'settings') title = 'Settings';
    else if (state.view === 'about') title = 'About';
    else if (state.view === 'category' && state.category) {
      const c = CATS[state.category];
      if (c) title = c.label;
    }
    $('mainTitle').textContent = title;
    $('mainBack').classList.toggle('hidden', state.view === 'home');
  }
}

const CHIPS = [
  { id: 'all', label: 'All' }, { id: 'folder', label: 'Folders' },
  { id: 'document', label: 'Documents' }, { id: 'image', label: 'Images' },
  { id: 'video', label: 'Videos' }, { id: 'audio', label: 'Audio' }, { id: 'other', label: 'Others' }
];

function renderChips() {
  const c = $('chips');
  const show = state.view === 'folder' && !state.searching && !state.selecting;
  if (!show) { c.classList.add('hidden'); c.classList.remove('flex'); return; }
  c.classList.remove('hidden'); c.classList.add('flex');
  c.innerHTML = CHIPS.map(ch =>
    `<button data-chip="${ch.id}" class="chip shrink-0 px-4 py-2 rounded-full text-[13px] font-medium border ${
      state.filter === ch.id ? 'bg-[#e8f0fe] text-gblue border-transparent' : 'bg-white text-gsub border-black/[0.08]'
    }">${ch.label}</button>`
  ).join('');
}

function rawUrl(it) {
  const p = new URLSearchParams();
  p.set('api', 'raw'); p.set('path', it.path);
  if (state.showHidden) p.set('show_hidden', '1');
  return '?' + p.toString();
}

function emptyState(text, subtext) {
  return `<div class="flex flex-col items-center justify-center py-20 text-center rise">
    <div class="w-20 h-20 rounded-3xl bg-white flex items-center justify-center shadow-[0_2px_12px_rgba(60,64,67,.10)] mb-4">
      <span class="msr text-gsub" style="font-size:36px">folder_open</span>
    </div>
    <div class="text-[15px] font-medium text-gtext">${esc(text)}</div>
    <div class="text-[13px] text-gsub mt-1">${esc(subtext || 'Files you add will show up here')}</div>
  </div>`;
}

function thumbFor(it) {
  const c = it.dir ? CATS.folder : (CATS[it.cat] || CATS.other);
  if (!it.dir && it.cat === 'image') {
    return `<img src="${rawUrl(it)}" loading="lazy" decoding="async" class="w-full h-full object-cover" onerror="this.replaceWith(Object.assign(document.createElement('span'),{className:'msr',style:'font-size:32px;color:${c.fg}',textContent:'${c.icon}'}))">`;
  }
  return `<span class="msr${it.dir ? ' fill' : ''}" style="font-size:32px;color:${c.fg}">${c.icon}</span>`;
}

function tile(it, i, dense) {
  const c = it.dir ? CATS.folder : (CATS[it.cat] || CATS.other);
  const aspect = dense ? 'img-dense' : 'aspect-[4/3]';
  return `<div class="relative">
    <div data-i="${i}" class="card-tile cursor-pointer bg-white rounded-2xl p-3 border border-black/[0.04] shadow-[0_1px_3px_rgba(60,64,67,.08)]">
      <div class="${aspect} rounded-xl flex items-center justify-center overflow-hidden mb-2.5" style="background:${c.bg}">${thumbFor(it)}</div>
      <div class="text-[13px] font-medium truncate text-gtext leading-tight">${esc(it.name)}</div>
      <div class="text-[11px] text-gsub mt-1 truncate">${it.dir ? 'Folder' : fmtSize(it.size)}</div>
      <div class="check-badge"><span class="msr">check</span></div>
    </div>
    <button data-more="${i}" class="absolute top-2 right-2 w-8 h-8 rounded-full bg-white/85 backdrop-blur flex items-center justify-center text-gsub hover:bg-white shadow-sm">
      <span class="msr" style="font-size:18px">more_vert</span>
    </button>
  </div>`;
}

function listItem(it, i) {
  const c = it.dir ? CATS.folder : (CATS[it.cat] || CATS.other);
  let iconHtml;
  if (!it.dir && it.cat === 'image') iconHtml = `<img src="${rawUrl(it)}" loading="lazy" class="w-full h-full object-cover">`;
  else iconHtml = `<span class="msr${it.dir ? ' fill' : ''}" style="font-size:22px;color:${c.fg}">${c.icon}</span>`;
  return `<div data-i="${i}" class="list-row flex items-center gap-3 px-3 py-2.5 cursor-pointer relative">
    <div class="w-11 h-11 rounded-xl flex items-center justify-center shrink-0 overflow-hidden" style="background:${c.bg}">${iconHtml}</div>
    <div class="flex-1 min-w-0">
      <div class="text-[14px] font-medium truncate">${esc(it.name)}</div>
      <div class="text-[11.5px] text-gsub mt-0.5">${it.dir ? 'Folder' : fmtSize(it.size) + ' · ' + fmtDate(it.mtime)}</div>
    </div>
    <button data-more="${i}" class="w-9 h-9 rounded-full hover:bg-black/5 flex items-center justify-center text-gsub shrink-0">
      <span class="msr" style="font-size:19px">more_vert</span>
    </button>
    <div class="check-badge"><span class="msr">check</span></div>
  </div>`;
}

function renderGrid(items, opts) {
  opts = opts || {};
  const el = $('content');
  if (!items.length) { el.innerHTML = emptyState(opts.emptyText || 'Nothing here yet', opts.emptySub); return; }
  if (state.layout === 'list') {
    el.innerHTML = `<div class="bg-white rounded-2xl overflow-hidden border border-black/[0.04] shadow-[0_1px_3px_rgba(60,64,67,.08)] divide-y divide-black/[0.05] pb-8">${items.map((it, i) => listItem(it, i)).join('')}</div>`;
  } else {
    const cols = opts.dense ? 'grid-cols-3 sm:grid-cols-4 md:grid-cols-6 lg:grid-cols-7' : 'grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5';
    el.innerHTML = `<div class="grid ${cols} gap-3 pb-8">${items.map((it, i) => tile(it, i, opts.dense)).join('')}</div>`;
  }
  document.body.classList.toggle('sel-active', state.selecting);
}

function renderCrumb() {
  const el = $('crumb');
  if (state.view !== 'folder' || state.searching || state.selecting || !state.path) { el.classList.add('hidden'); el.classList.remove('flex'); return; }
  const parts = state.path.split('/').filter(Boolean);
  let html = `<button data-crumb="" class="hover:text-gblue">Files</button>`;
  let acc = '';
  parts.forEach((p, i) => {
    acc += (acc ? '/' : '') + p;
    html += `<span class="opacity-40">/</span><button data-crumb="${esc(acc)}" class="${i === parts.length - 1 ? 'text-gtext font-medium' : 'hover:text-gblue'}">${esc(p)}</button>`;
  });
  el.innerHTML = html;
  el.classList.remove('hidden'); el.classList.add('flex');
  el.querySelectorAll('[data-crumb]').forEach(b => { b.onclick = () => goFolder(b.dataset.crumb); });
}

async function load() {
  renderHeader(); renderChips(); renderCrumb();
  if (state.view === 'home') return loadHome();
  if (state.view === 'settings') return loadSettings();
  if (state.view === 'about') return loadAbout();
  if (state.view === 'category') return loadCategory(state.category);
  if (state.searching) return doSearch(state.search);
  if (state.view === 'folder') return loadFolder();
}

async function loadHome() {
  $('content').innerHTML = `<div class="flex justify-center py-20"><span class="msr spin text-gsub" style="font-size:32px">progress_activity</span></div>`;
  const stats = await get('stats');
  if (!stats.ok) { $('content').innerHTML = emptyState('Storage unavailable'); return; }
  const byCat = stats.byCat || {};
  const catCount = stats.catCount || {};
  const total = stats.total || 0;
  const quota = stats.quota || state.quota;
  state.quota = quota;
  const free = Math.max(0, quota - total);
  const pct = quota ? Math.min(100, (total / quota) * 100) : 0;
  const cats = Object.entries(byCat).sort((a, b) => b[1] - a[1]);
  const bar = cats.map(([k, v]) => {
    const c = CATS[k] || CATS.other;
    return `<div style="width:${total ? (v / total * 100) : 0}%;background:${c.color}" class="h-full"></div>`;
  }).join('');

  const catRows = [
    { key: 'images', label: 'Images', icon: 'image', bg: '#e8f0fe', fg: '#1a73e8', cat: 'image' },
    { key: 'videos', label: 'Videos', icon: 'movie', bg: '#fce8e6', fg: '#d93025', cat: 'video' },
    { key: 'audio', label: 'Audio', icon: 'audiotrack', bg: '#fef7e0', fg: '#e37400', cat: 'audio' },
    { key: 'archives', label: 'Archives', icon: 'folder_zip', bg: '#f3e8fd', fg: '#8430ce', cat: 'archive' },
    { key: 'documents', label: 'Documents', icon: 'description', bg: '#e6f4ea', fg: '#188038', cat: 'document' }
  ];

  const catListHtml = catRows.map(r => {
    const sz = byCat[r.cat] || 0;
    const n = catCount[r.cat] || 0;
    return `<button data-cat="${r.key}" class="w-full flex items-center gap-3.5 py-3.5 text-left hover:bg-black/[0.02] rounded-xl px-1 transition-colors">
      <div class="w-11 h-11 rounded-2xl flex items-center justify-center shrink-0" style="background:${r.bg}">
        <span class="msr" style="font-size:21px;color:${r.fg}">${r.icon}</span>
      </div>
      <div class="flex-1 min-w-0">
        <div class="text-[14px] font-medium">${r.label}</div>
        <div class="text-[12px] text-gsub mt-0.5">${n} file${n === 1 ? '' : 's'}</div>
      </div>
      <div class="text-[13px] font-medium text-gsub shrink-0">${fmtSize(sz)}</div>
      <span class="msr text-gsub shrink-0" style="font-size:20px">chevron_right</span>
    </button>`;
  }).join('');

  const utilRows = `
    <button data-goto="settings" class="w-full flex items-center gap-3.5 py-3.5 text-left hover:bg-black/[0.02] rounded-xl px-1 transition-colors">
      <div class="w-11 h-11 rounded-2xl flex items-center justify-center shrink-0" style="background:${CATS.settings.bg}">
        <span class="msr" style="font-size:21px;color:${CATS.settings.fg}">settings</span>
      </div>
      <div class="flex-1 min-w-0">
        <div class="text-[14px] font-medium">Settings</div>
        <div class="text-[12px] text-gsub mt-0.5">Theme, pattern, quota</div>
      </div>
      <span class="msr text-gsub shrink-0" style="font-size:20px">chevron_right</span>
    </button>
    <button data-goto="about" class="w-full flex items-center gap-3.5 py-3.5 text-left hover:bg-black/[0.02] rounded-xl px-1 transition-colors">
      <div class="w-11 h-11 rounded-2xl flex items-center justify-center shrink-0" style="background:${CATS.about.bg}">
        <span class="msr" style="font-size:21px;color:${CATS.about.fg}">info</span>
      </div>
      <div class="flex-1 min-w-0">
        <div class="text-[14px] font-medium">About</div>
        <div class="text-[12px] text-gsub mt-0.5">About iWebFM</div>
      </div>
      <span class="msr text-gsub shrink-0" style="font-size:20px">chevron_right</span>
    </button>`;

  $('content').innerHTML = `
    <div class="rise pb-8">
      <button data-goto="storage" class="w-full text-left bg-white rounded-3xl p-6 border border-black/[0.04] shadow-[0_1px_3px_rgba(60,64,67,.08)] mb-5 active:scale-[.99] transition-transform">
        <div class="flex items-start justify-between">
          <div class="min-w-0">
            <div class="text-[13px] text-gsub">Storage</div>
            <div class="text-[32px] font-medium tracking-tight mt-1">${fmtSize(free)} free</div>
            <div class="text-[13px] text-gsub mt-1">${fmtSize(total)} used of ${fmtSize(quota)}</div>
          </div>
          <span class="msr text-gsub shrink-0 mt-1" style="font-size:22px">chevron_right</span>
        </div>
        <div class="h-3 w-full rounded-full bg-gsurf overflow-hidden flex mt-5">${bar}</div>
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mt-5">
          ${cats.slice(0, 4).map(([k, v]) => {
            const c = CATS[k] || CATS.other;
            return `<div class="flex items-center gap-2">
              <span class="w-2.5 h-2.5 rounded-full shrink-0" style="background:${c.color}"></span>
              <div class="min-w-0">
                <div class="text-[11px] text-gsub truncate">${c.label}</div>
                <div class="text-[13px] font-medium truncate">${fmtSize(v)}</div>
              </div>
            </div>`;
          }).join('')}
        </div>
        <div class="text-[11.5px] text-gsub mt-4">${stats.count} file${stats.count === 1 ? '' : 's'} · using ${pct.toFixed(1)}% of quota</div>
      </button>

      <div class="bg-white rounded-3xl px-5 py-2 border border-black/[0.04] shadow-[0_1px_3px_rgba(60,64,67,.08)]">
        <div class="divide-y divide-black/[0.05]">${catListHtml}</div>
      </div>

      <div class="bg-white rounded-3xl px-5 py-2 mt-4 border border-black/[0.04] shadow-[0_1px_3px_rgba(60,64,67,.08)]">
        <div class="divide-y divide-black/[0.05]">${utilRows}</div>
      </div>
    </div>`;
}

async function loadSettings() {
  const settings = await get('settings_get');
  const quota = settings.ok ? settings.settings.quota : state.quota;
  state.quota = quota;
  const theme = state.theme || 'light';
  const swatches = [
    { id: 'light', label: 'Light', bg: '#f8f9fa', fg: '#202124', accent: '#1a73e8' },
    { id: 'dark', label: 'Dark', bg: '#0f1115', fg: '#e8eaed', accent: '#8ab4f8' },
    { id: 'eyecare', label: 'Eye Care', bg: '#f5ecd9', fg: '#3a2e1f', accent: '#c98b32' }
  ];
  const swatchHtml = swatches.map(s => `
    <button data-theme="${s.id}" class="theme-swatch ${theme === s.id ? 'active' : ''}" style="background:${s.bg};color:${s.fg}">
      <div class="flex flex-col h-full p-3">
        <div class="flex items-center justify-between">
          <div class="text-[10px] font-medium">iWebFM</div>
          <div class="w-4 h-4 rounded-full" style="background:${s.accent}"></div>
        </div>
        <div class="flex-1 flex items-center justify-center">
          <span class="text-[12px] font-medium">${s.label}</span>
        </div>
        <div class="h-1 rounded-full" style="background:${s.accent};width:60%"></div>
      </div>
    </button>`).join('');

  $('content').innerHTML = `
    <div class="rise pb-8">
      <div class="bg-white rounded-3xl p-5 border border-black/[0.04] shadow-[0_1px_3px_rgba(60,64,67,.08)] mb-4">
        <div class="text-[13px] font-medium text-gsub uppercase tracking-wide mb-3">Theme</div>
        <div class="grid grid-cols-3 gap-3">${swatchHtml}</div>
        <div class="text-[11.5px] text-gsub mt-3">Default is Light.</div>
      </div>

      <div class="bg-white rounded-3xl p-5 border border-black/[0.04] shadow-[0_1px_3px_rgba(60,64,67,.08)] mb-4">
        <div class="text-[13px] font-medium text-gsub uppercase tracking-wide mb-3">Security</div>
        <button id="changePwBtn" class="w-full flex items-center gap-3 py-2 text-left">
          <div class="w-10 h-10 rounded-xl bg-[#e8f0fe] flex items-center justify-center shrink-0">
            <span class="msr text-gblue" style="font-size:20px">key</span>
          </div>
          <div class="flex-1">
            <div class="text-[14px] font-medium">Change pattern</div>
            <div class="text-[12px] text-gsub mt-0.5">You will be signed out and asked to draw a new pattern.</div>
          </div>
          <span class="msr text-gsub" style="font-size:20px">chevron_right</span>
        </button>
      </div>

      <div class="bg-white rounded-3xl p-5 border border-black/[0.04] shadow-[0_1px_3px_rgba(60,64,67,.08)]">
        <div class="text-[13px] font-medium text-gsub uppercase tracking-wide mb-3">Disk quota</div>
        <div class="flex items-center gap-2 mb-3">
          <input id="quotaInput" type="number" min="1" step="1" value="${(quota / GB).toFixed(1)}"
                 class="flex-1 bg-gsurf rounded-xl px-4 py-3 outline-none text-[15px] border border-transparent focus:border-gblue transition-colors">
          <span class="text-[14px] text-gsub font-medium">GB</span>
        </div>
        <button id="quotaSave" class="w-full py-3 rounded-full bg-gblue text-white text-[14px] font-medium">Save quota</button>
        <div class="text-[11.5px] text-gsub mt-3">Used for storage analysis on the home page.</div>
      </div>
    </div>`;

  $('content').querySelectorAll('[data-theme]').forEach(b => {
    b.onclick = () => {
      state.theme = b.dataset.theme;
      applyTheme(state.theme);
      $('content').querySelectorAll('[data-theme]').forEach(x => x.classList.toggle('active', x.dataset.theme === state.theme));
    };
  });
  $('changePwBtn').onclick = () => {
    openSheet('Change pattern?', [
      { label: 'Continue', icon: 'key', danger: true, fn: async () => {
        const r = await post('pattern_reset');
        if (r.ok) { toast('Pattern cleared'); setTimeout(() => location.reload(), 500); }
        else toast('Could not reset');
      } },
      { label: 'Cancel', icon: 'close', fn: () => {} }
    ]);
  };
  $('quotaSave').onclick = async () => {
    const gb = parseFloat($('quotaInput').value);
    if (!gb || gb <= 0) { toast('Enter a valid size'); return; }
    const bytes = Math.round(gb * GB);
    const r = await post('settings_set', { quota: bytes });
    if (r.ok) { toast('Quota saved'); state.quota = bytes; }
    else toast('Could not save');
  };
}

async function loadAbout() {
  $('content').innerHTML = `
    <div class="rise pb-8">
      <div class="bg-white rounded-3xl p-6 border border-black/[0.04] shadow-[0_1px_3px_rgba(60,64,67,.08)] mb-4 text-center">
        <div class="w-16 h-16 rounded-[20px] bg-[#e8f0fe] flex items-center justify-center mx-auto mb-4">
          <span class="msr text-gblue fill" style="font-size:32px">folder</span>
        </div>
        <div class="text-[22px] font-medium tracking-tight">iWebFM</div>
        <div class="text-[13px] text-gsub mt-1">A single-file web file manager with pattern unlock, dual editors, and a Google Files-inspired interface.</div>
        <div class="inline-flex items-center gap-1.5 mt-3 px-3 py-1 rounded-full bg-[#e8f0fe] text-gblue text-[12px] font-medium">
          <span>v1.0</span>
          <span class="opacity-50">·</span>
          <span>Released Tomorrow</span>
        </div>
      </div>

      <div class="bg-white rounded-3xl p-5 border border-black/[0.04] shadow-[0_1px_3px_rgba(60,64,67,.08)] mb-4">
        <div class="text-[13px] font-medium text-gsub uppercase tracking-wide mb-3">Author</div>
        <div class="flex items-center gap-3">
          <div class="w-11 h-11 rounded-2xl bg-[#e6f4ea] flex items-center justify-center shrink-0">
            <span class="msr" style="font-size:20px;color:#188038">person</span>
          </div>
          <div>
            <div class="text-[14px] font-medium">Icii</div>
            <div class="text-[12px] text-gsub">Creator and maintainer</div>
          </div>
        </div>
      </div>

      <div class="bg-white rounded-3xl px-5 py-2 border border-black/[0.04] shadow-[0_1px_3px_rgba(60,64,67,.08)]">
        <a href="https://github.com/iciiwhite/iwebfm" target="_blank" rel="noopener noreferrer"
           class="w-full flex items-center gap-3 py-3.5 text-left hover:bg-black/[0.02] rounded-xl px-1 transition-colors">
          <div class="w-11 h-11 rounded-2xl bg-[#f1f3f4] flex items-center justify-center shrink-0">
            <span class="msr" style="font-size:20px;color:#202124">code</span>
          </div>
          <div class="flex-1 min-w-0">
            <div class="text-[14px] font-medium">GitHub repository</div>
            <div class="text-[12px] text-gsub truncate mt-0.5">github.com/iciiwhite/iwebfm</div>
          </div>
          <span class="msr text-gsub shrink-0" style="font-size:20px">open_in_new</span>
        </a>
        <a href="mailto:mail.iciiwhite@gmail.com"
           class="w-full flex items-center gap-3 py-3.5 text-left hover:bg-black/[0.02] rounded-xl px-1 transition-colors border-t border-black/[0.05]">
          <div class="w-11 h-11 rounded-2xl bg-[#fce8e6] flex items-center justify-center shrink-0">
            <span class="msr" style="font-size:20px;color:#d93025">mail</span>
          </div>
          <div class="flex-1 min-w-0">
            <div class="text-[14px] font-medium">Contact</div>
            <div class="text-[12px] text-gsub truncate mt-0.5">mail.iciiwhite@gmail.com</div>
          </div>
          <span class="msr text-gsub shrink-0" style="font-size:20px">open_in_new</span>
        </a>
      </div>
    </div>`;
}

async function loadFolder() {
  $('content').innerHTML = `<div class="flex justify-center py-20"><span class="msr spin text-gsub" style="font-size:32px">progress_activity</span></div>`;
  const d = await get('list', { path: state.path, filter: state.filter, sort: state.sort, show_hidden: state.showHidden ? 1 : 0 });
  if (!d.ok) { $('content').innerHTML = emptyState('Could not open this folder'); return; }
  state.items = d.items || [];
  renderGrid(state.items);
  attachLongPress();
}

async function loadCategory(cat) {
  $('content').innerHTML = `<div class="flex justify-center py-20"><span class="msr spin text-gsub" style="font-size:32px">progress_activity</span></div>`;
  const d = await get('category', { cat, sort: state.sort });
  state.items = d.items || [];
  const c = CATS[cat] || CATS.other;
  const dense = cat === 'image';
  const header = `<div class="flex items-center justify-between mb-3 px-1">
    <div class="flex items-center gap-2">
      <span class="msr" style="font-size:22px;color:${c.fg}">${c.icon}</span>
      <span class="text-[18px] font-medium">${c.label}</span>
    </div>
    <span class="text-[12px] text-gsub">${state.items.length} item${state.items.length === 1 ? '' : 's'}</span>
  </div>`;
  const el = $('content');
  if (!state.items.length) { el.innerHTML = header + emptyState('No ' + c.label.toLowerCase() + ' yet'); }
  else { renderGrid(state.items, { dense }); el.innerHTML = header + el.innerHTML; }
  attachLongPress();
}

async function doSearch(q) {
  state.searching = true; state.search = q;
  renderHeader(); renderChips(); renderCrumb();
  $('content').innerHTML = `<div class="flex justify-center py-20"><span class="msr spin text-gsub" style="font-size:32px">progress_activity</span></div>`;
  const d = await get('search', { q });
  state.items = d.items || [];
  renderGrid(state.items, { emptyText: 'No matches for "' + q + '"' });
  attachLongPress();
}

function openSheet(title, actions) {
  $('sheetTitle').textContent = title;
  const list = $('sheetList');
  list.innerHTML = actions.map((a, i) =>
    `<button data-a="${i}" class="w-full flex items-center gap-4 px-5 py-3.5 hover:bg-black/[0.04] text-left transition-colors">
      <span class="msr ${a.danger ? 'text-[#d93025]' : 'text-gsub'}" style="font-size:22px">${a.icon}</span>
      <span class="text-[15px] ${a.danger ? 'text-[#d93025]' : ''}">${esc(a.label)}</span>
    </button>`
  ).join('');
  list.onclick = e => {
    const b = e.target.closest('[data-a]');
    if (!b) return;
    closeSheet();
    setTimeout(() => actions[+b.dataset.a].fn(), 60);
  };
  $('sheet').classList.remove('hidden');
}
function closeSheet() { $('sheet').classList.add('hidden'); }

function askDialog(title, value, cb, subtext) {
  $('dlgTitle').textContent = title;
  $('dlgSub').textContent = subtext || '';
  const input = $('dlgInput');
  input.value = value || '';
  $('dialog').classList.remove('hidden');
  setTimeout(() => { input.focus(); input.select(); }, 80);
  const done = ok => {
    $('dialog').classList.add('hidden');
    $('dlgOk').onclick = null; $('dlgCancel').onclick = null; input.onkeydown = null;
    if (ok) cb(input.value.trim());
  };
  $('dlgOk').onclick = () => done(true);
  $('dlgCancel').onclick = () => done(false);
  input.onkeydown = e => { if (e.key === 'Enter') done(true); if (e.key === 'Escape') done(false); };
}
$('dialog').querySelectorAll('[data-close]').forEach(el => el.onclick = () => $('dialog').classList.add('hidden'));
$('sheet').querySelectorAll('[data-close]').forEach(el => el.onclick = closeSheet);

function openItem(i) {
  const it = state.items[i];
  if (!it) return;
  if (state.selecting) { toggleSelect(i); return; }
  if (it.dir) { goFolder(it.path); return; }
  openFile(it);
}

async function openFile(it) {
  const ext = extOf(it.name);
  if (SHEET_EXTS.has(ext)) return openSheetEditor(it);
  if (ARCHIVE_EXTS.has(ext) && ext === 'zip') return openArchiveViewer(it);
  if (it.cat === 'image') return openImageViewer(it);
  if (it.cat === 'video') return openMediaViewer(it, 'video');
  if (it.cat === 'audio') return openMediaViewer(it, 'audio');
  if (ext === 'pdf') return openPdfFallback(it);
  const r = await get('file_read', { path: it.path });
  if (r.ok && !r.binary) return openCodeViewer(it, r);
  if (r.ok && r.binary) return promptDownload(it, 'Binary file');
  return promptDownload(it, r.error === 'toobig' ? 'File too large to preview' : 'Preview unavailable');
}

function promptDownload(it, reason) {
  const url = rawUrl(it);
  $('viewerName').textContent = it.name;
  $('viewerDl').onclick = () => window.open(url + '&dl=1', '_blank');
  $('viewerBody').innerHTML = `<div class="text-center">
    <span class="msr text-white/50" style="font-size:64px">${(CATS[it.cat] || CATS.other).icon}</span>
    <div class="text-white/80 text-[15px] mt-4 mb-2 break-all max-w-xs mx-auto">${esc(it.name)}</div>
    <div class="text-white/40 text-[12px] mb-6">${esc(reason || '')} · ${fmtSize(it.size)}</div>
    <button onclick="window.open('${url}&dl=1','_blank')" class="px-6 py-3 rounded-full bg-white text-[#202124] text-[14px] font-medium">Download</button>
  </div>`;
  $('viewer').classList.remove('hidden');
}
$('viewerClose').onclick = () => { $('viewer').classList.add('hidden'); $('viewerBody').innerHTML = ''; };

function openPdfFallback(it) {
  const url = rawUrl(it);
  $('viewerName').textContent = it.name;
  $('viewerDl').onclick = () => window.open(url + '&dl=1', '_blank');
  $('viewerBody').innerHTML = `<iframe src="${url}" class="w-full h-full bg-white rounded-lg"></iframe>`;
  $('viewer').classList.remove('hidden');
}

let imgFlipped = false;
function openImageViewer(it) {
  const url = rawUrl(it);
  $('imageName').textContent = it.name;
  $('imageDl').onclick = () => window.open(url + '&dl=1', '_blank');
  imgFlipped = false;
  const el = $('imgEl');
  el.style.transform = '';
  el.src = url;
  $('imageViewer').classList.remove('hidden');
}
$('imageClose').onclick = () => { $('imageViewer').classList.add('hidden'); $('imgEl').src = ''; };
$('imageFlip').onclick = () => {
  imgFlipped = !imgFlipped;
  $('imgEl').style.transform = imgFlipped ? 'scaleX(-1)' : '';
};

let mediaEl = null;
let mediaRaf = 0;
function openMediaViewer(it, kind) {
  const url = rawUrl(it);
  $('mediaName').textContent = it.name;
  $('mediaDl').onclick = () => window.open(url + '&dl=1', '_blank');
  const stage = $('mediaStage');
  const controls = $('mediaControls');
  stage.innerHTML = ''; controls.innerHTML = '';
  if (kind === 'video') {
    const v = document.createElement('video');
    v.src = url; v.autoplay = true; v.playsInline = true;
    v.className = 'max-w-full max-h-full';
    stage.appendChild(v);
    mediaEl = v;
    controls.innerHTML = `
      <div class="flex items-center gap-3">
        <button class="med-ctrl w-12 h-12 rounded-full bg-white/10 hover:bg-white/20 flex items-center justify-center" data-mv="play"><span class="msr fill" id="mvPlay" style="font-size:24px">pause</span></button>
        <span class="text-[12px] tabular-nums w-12" id="mvCur">0:00</span>
        <div class="flex-1 seek-bar select-none" id="mvSeek"><div class="h-1.5 rounded-full bg-white/15 relative"><div class="absolute inset-y-0 left-0 rounded-full bg-gblue" id="mvBar" style="width:0"></div><div class="seek-handle absolute top-1/2 -translate-y-1/2 -translate-x-1/2 w-3 h-3 rounded-full bg-white shadow" id="mvHandle" style="left:0%"></div></div></div>
        <span class="text-[12px] tabular-nums w-12 text-right" id="mvTot">0:00</span>
        <button class="med-ctrl w-10 h-10 rounded-full bg-white/10 hover:bg-white/20 flex items-center justify-center" data-mv="mute"><span class="msr" id="mvMute" style="font-size:20px">volume_up</span></button>
        <input type="range" min="0" max="1" step="0.05" value="1" class="w-20 accent-gblue" id="mvVol">
        <button class="med-ctrl w-10 h-10 rounded-full bg-white/10 hover:bg-white/20 flex items-center justify-center" data-mv="fs"><span class="msr" style="font-size:20px">fullscreen</span></button>
      </div>`;
    setupVideoControls(v);
  } else {
    const audioVis = document.createElement('div');
    audioVis.className = 'w-full max-w-2xl';
    audioVis.innerHTML = `
      <div class="bg-white/5 rounded-3xl p-6 flex items-center gap-5">
        <div class="w-24 h-24 rounded-2xl bg-gradient-to-br from-[#1a73e8] to-[#8ab4f8] flex items-center justify-center shrink-0">
          <span class="msr text-white" style="font-size:44px">audiotrack</span>
        </div>
        <div class="flex-1 min-w-0">
          <div class="text-white/50 text-[12px] mb-1">Now playing</div>
          <div class="text-white text-[15px] font-medium truncate">${esc(it.name)}</div>
          <div class="audio-vis mt-3" id="avBars"></div>
        </div>
      </div>`;
    stage.appendChild(audioVis);
    const a = document.createElement('audio');
    a.src = url; a.autoplay = true;
    mediaEl = a;
    controls.innerHTML = `
      <div class="max-w-2xl mx-auto">
        <div class="flex items-center gap-3">
          <span class="text-[12px] tabular-nums w-12" id="mvCur">0:00</span>
          <div class="flex-1 seek-bar select-none" id="mvSeek"><div class="h-1.5 rounded-full bg-white/15 relative"><div class="absolute inset-y-0 left-0 rounded-full bg-gblue" id="mvBar" style="width:0"></div><div class="seek-handle absolute top-1/2 -translate-y-1/2 -translate-x-1/2 w-3 h-3 rounded-full bg-white shadow" id="mvHandle" style="left:0%"></div></div></div>
          <span class="text-[12px] tabular-nums w-12 text-right" id="mvTot">0:00</span>
        </div>
        <div class="flex items-center justify-center gap-4 mt-2">
          <button class="med-ctrl w-10 h-10 rounded-full bg-white/10 hover:bg-white/20 flex items-center justify-center" data-mv="back"><span class="msr" style="font-size:22px">replay_10</span></button>
          <button class="med-ctrl w-14 h-14 rounded-full bg-white text-black flex items-center justify-center" data-mv="play"><span class="msr fill" id="mvPlay" style="font-size:30px">pause</span></button>
          <button class="med-ctrl w-10 h-10 rounded-full bg-white/10 hover:bg-white/20 flex items-center justify-center" data-mv="fwd"><span class="msr" style="font-size:22px">forward_10</span></button>
          <button class="med-ctrl w-10 h-10 rounded-full bg-white/10 hover:bg-white/20 flex items-center justify-center" data-mv="mute"><span class="msr" id="mvMute" style="font-size:20px">volume_up</span></button>
          <input type="range" min="0" max="1" step="0.05" value="1" class="w-24 accent-gblue" id="mvVol">
        </div>
      </div>`;
    setupAudioControls(a);
  }
  $('mediaViewer').classList.remove('hidden');
}
function setupVideoControls(v) {
  const play = $('mediaControls').querySelector('[data-mv="play"]');
  const mute = $('mediaControls').querySelector('[data-mv="mute"]');
  const fs = $('mediaControls').querySelector('[data-mv="fs"]');
  const seek = $('mediaControls').querySelector('#mvSeek');
  const vol = $('mediaControls').querySelector('#mvVol');
  play.onclick = () => { if (v.paused) v.play(); else v.pause(); };
  mute.onclick = () => { v.muted = !v.muted; };
  fs.onclick = () => { const w = $('mediaViewer'); if (document.fullscreenElement) document.exitFullscreen(); else if (w.requestFullscreen) w.requestFullscreen(); };
  vol.oninput = () => { v.volume = +vol.value; v.muted = v.volume === 0; };
  let seeking = false;
  const seekTo = (e) => {
    const r = seek.getBoundingClientRect();
    const x = (e.touches ? e.touches[0].clientX : e.clientX) - r.left;
    const p = Math.max(0, Math.min(1, x / r.width));
    if (v.duration) v.currentTime = v.duration * p;
  };
  seek.addEventListener('pointerdown', e => { seeking = true; seekTo(e); });
  window.addEventListener('pointermove', e => { if (seeking) seekTo(e); });
  window.addEventListener('pointerup', () => { seeking = false; });
  const upd = () => {
    if (mediaEl !== v) return;
    $('mvPlay').textContent = v.paused ? 'play_arrow' : 'pause';
    $('mvMute').textContent = (v.muted || v.volume === 0) ? 'volume_off' : 'volume_up';
    if (v.duration && isFinite(v.duration)) {
      const p = (v.currentTime / v.duration) * 100;
      $('mvBar').style.width = p + '%';
      $('mvHandle').style.left = p + '%';
      $('mvCur').textContent = fmtTime(v.currentTime);
      $('mvTot').textContent = fmtTime(v.duration);
    } else $('mvCur').textContent = fmtTime(v.currentTime);
    mediaRaf = requestAnimationFrame(upd);
  };
  mediaRaf = requestAnimationFrame(upd);
}
function setupAudioControls(a) {
  const bars = $('avBars');
  for (let i = 0; i < 40; i++) { const s = document.createElement('span'); s.style.height = '3px'; bars.appendChild(s); }
  const play = $('mediaControls').querySelector('[data-mv="play"]');
  const back = $('mediaControls').querySelector('[data-mv="back"]');
  const fwd = $('mediaControls').querySelector('[data-mv="fwd"]');
  const mute = $('mediaControls').querySelector('[data-mv="mute"]');
  const seek = $('mediaControls').querySelector('#mvSeek');
  const vol = $('mediaControls').querySelector('#mvVol');
  play.onclick = () => { if (a.paused) a.play(); else a.pause(); };
  back.onclick = () => { a.currentTime = Math.max(0, a.currentTime - 10); };
  fwd.onclick = () => { a.currentTime = Math.min(a.duration || a.currentTime + 10, a.currentTime + 10); };
  mute.onclick = () => { a.muted = !a.muted; };
  vol.oninput = () => { a.volume = +vol.value; a.muted = a.volume === 0; };
  let seeking = false;
  const seekTo = (e) => {
    const r = seek.getBoundingClientRect();
    const x = (e.touches ? e.touches[0].clientX : e.clientX) - r.left;
    const p = Math.max(0, Math.min(1, x / r.width));
    if (a.duration) a.currentTime = a.duration * p;
  };
  seek.addEventListener('pointerdown', e => { seeking = true; seekTo(e); });
  window.addEventListener('pointermove', e => { if (seeking) seekTo(e); });
  window.addEventListener('pointerup', () => { seeking = false; });
  let ctx, analyser, data;
  const initAudio = () => {
    if (ctx) return;
    try {
      ctx = new (window.AudioContext || window.webkitAudioContext)();
      const src = ctx.createMediaElementSource(a);
      analyser = ctx.createAnalyser(); analyser.fftSize = 128;
      data = new Uint8Array(analyser.frequencyBinCount);
      src.connect(analyser); analyser.connect(ctx.destination);
    } catch (e) {}
  };
  a.addEventListener('play', initAudio);
  const upd = () => {
    if (mediaEl !== a) return;
    $('mvPlay').textContent = a.paused ? 'play_arrow' : 'pause';
    $('mvMute').textContent = (a.muted || a.volume === 0) ? 'volume_off' : 'volume_up';
    if (a.duration && isFinite(a.duration)) {
      const p = (a.currentTime / a.duration) * 100;
      $('mvBar').style.width = p + '%';
      $('mvHandle').style.left = p + '%';
      $('mvCur').textContent = fmtTime(a.currentTime);
      $('mvTot').textContent = fmtTime(a.duration);
    }
    if (analyser && data && bars) {
      analyser.getByteFrequencyData(data);
      const kids = bars.children;
      for (let i = 0; i < kids.length; i++) {
        const v = data[i % data.length] / 255;
        kids[i].style.height = (3 + v * 34) + 'px';
      }
    }
    mediaRaf = requestAnimationFrame(upd);
  };
  mediaRaf = requestAnimationFrame(upd);
}
function closeMedia() {
  if (mediaRaf) cancelAnimationFrame(mediaRaf);
  mediaRaf = 0;
  if (mediaEl) { try { mediaEl.pause(); mediaEl.src = ''; mediaEl.load(); } catch (e) {} mediaEl = null; }
  $('mediaStage').innerHTML = ''; $('mediaControls').innerHTML = '';
  $('mediaViewer').classList.add('hidden');
}
$('mediaClose').onclick = closeMedia;

let aceEditor = null;
let currentCode = null;
let codeEditMode = false;
function openCodeViewer(it, r) {
  const mode = aceModeFor(it.name);
  currentCode = { path: it.path, encoding: r.encoding || 'UTF-8', mode: mode, size: r.size };
  codeEditMode = false;
  $('codeName').textContent = it.name;
  $('codeMeta').textContent = (mode === 'text' ? 'plain text' : mode) + ' · ' + (r.encoding || 'UTF-8') + ' · ' + fmtSize(r.size);
  $('codeEdit').classList.remove('hidden');
  $('codeSave').classList.add('hidden');
  $('codeViewer').classList.remove('hidden');
  ensureAceMode(mode, () => {
    if (!aceEditor) {
      aceEditor = ace.edit('codeEditor');
      aceEditor.setTheme('ace/theme/github_dark');
      aceEditor.setOptions({
        fontSize: '13px', showPrintMargin: false, useWorker: false,
        enableBasicAutocompletion: true, enableLiveAutocompletion: true, enableSnippets: true,
        wrap: true, tabSize: 4, useSoftTabs: true, scrollPastEnd: 0.3,
      });
      aceEditor.session.on('change', () => { if (codeEditMode) $('codeSave').classList.remove('hidden'); });
    }
    try { aceEditor.session.setMode('ace/mode/' + mode); } catch (e) { aceEditor.session.setMode('ace/mode/text'); }
    aceEditor.setReadOnly(true);
    aceEditor.setValue(r.text || '', -1);
    aceEditor.clearSelection();
    setTimeout(() => aceEditor.resize(), 40);
  });
}
$('codeClose').onclick = () => {
  $('codeViewer').classList.add('hidden');
  currentCode = null; codeEditMode = false;
  $('codeSave').classList.add('hidden');
};
$('codeEdit').onclick = () => {
  if (!aceEditor || !currentCode) return;
  codeEditMode = true;
  aceEditor.setReadOnly(false);
  aceEditor.focus();
  $('codeEdit').classList.add('hidden');
  $('codeMeta').textContent = (currentCode.mode === 'text' ? 'plain text' : currentCode.mode) + ' · ' + currentCode.encoding + ' · editing';
};
$('codeDl').onclick = () => { if (currentCode) window.open('?api=raw&path=' + encodeURIComponent(currentCode.path) + '&dl=1', '_blank'); };
$('codeSave').onclick = async () => {
  if (!aceEditor || !currentCode) return;
  const btn = $('codeSave');
  const originalText = btn.textContent;
  btn.textContent = 'Saving…';
  const r = await post('file_write', { path: currentCode.path, content: aceEditor.getValue(), encoding: currentCode.encoding });
  btn.textContent = originalText;
  if (r.ok) { toast('Saved'); btn.classList.add('hidden'); }
  else { toast('Save failed'); }
};

let hexData = null;
let hexPath = null;
let hexName = '';
let hexDirty = false;
let hexPage = 0;
const HEX_PAGE = 65536;
const HEX_COLS_MOBILE = 8;
const HEX_COLS_DESKTOP = 16;
let hexSelectionStart = -1;
let hexSelectionEnd = -1;
let hexMatches = [];
let hexDragStart = -1;
let hexDragActive = false;

function hexCols() { return window.innerWidth < 640 ? HEX_COLS_MOBILE : HEX_COLS_DESKTOP; }

function openHexViewer(it) {
  $('hexViewer').classList.remove('hidden');
  $('hexName').textContent = it.name;
  $('hexMeta').textContent = 'Loading…';
  $('hexBody').innerHTML = '<div class="flex justify-center py-20"><span class="msr spin" style="font-size:32px">progress_activity</span></div>';
  hexPath = it.path; hexName = it.name;
  hexDirty = false; hexSelectionStart = -1; hexSelectionEnd = -1; hexMatches = []; hexPage = 0;
  get('file_read', { path: it.path }).then(r => {
    if (!r.ok) {
      $('hexBody').innerHTML = '<div class="text-center py-20 text-white/60">Could not read file</div>';
      return;
    }
    let raw;
    try {
      if (r.binary) {
        const bin = atob(r.base64);
        const len = bin.length;
        const arr = new Uint8Array(len);
        for (let i = 0; i < len; i++) arr[i] = bin.charCodeAt(i);
        raw = arr;
      } else {
        raw = new TextEncoder().encode(r.text || '');
      }
    } catch (e) {
      $('hexBody').innerHTML = '<div class="text-center py-20 text-white/60">Could not decode file</div>';
      return;
    }
    hexData = raw;
    renderHex();
  });
}

function renderHex() {
  if (!hexData) return;
  const total = hexData.length;
  const cols = hexCols();
  const totalPages = Math.max(1, Math.ceil(total / HEX_PAGE));
  const start = hexPage * HEX_PAGE;
  const end = Math.min(total, start + HEX_PAGE);
  $('hexMeta').textContent = total + ' bytes · page ' + (hexPage + 1) + ' of ' + totalPages + (hexDirty ? ' · edited' : '');
  $('hexPrev').disabled = hexPage === 0;
  $('hexNext').disabled = end >= total;
  $('hexPrev').style.opacity = hexPage === 0 ? '.4' : '1';
  $('hexNext').style.opacity = end >= total ? '.4' : '1';

  const matchSet = new Set(hexMatches);
  const rows = [];
  for (let i = start; i < end; i += cols) {
    const off = i.toString(16).padStart(8, '0');
    const hexParts = [];
    const asciiParts = [];
    for (let j = 0; j < cols; j++) {
      const idx = i + j;
      if (idx < end) {
        const b = hexData[idx];
        const hs = b.toString(16).padStart(2, '0');
        const ch = (b >= 32 && b < 127) ? String.fromCharCode(b) : '.';
        let cls = 'hex-cell';
        if (hexSelectionStart !== -1 && idx >= hexSelectionStart && idx <= hexSelectionEnd) cls += ' sel';
        else if (matchSet.has(idx)) cls += ' hit';
        hexParts.push(`<span class="${cls}" data-off="${idx}">${hs}</span>`);
        asciiParts.push(esc(ch));
      } else {
        hexParts.push('<span class="hex-cell" style="opacity:.15">··</span>');
        asciiParts.push(' ');
      }
    }
    rows.push(`<div class="hex-row"><span class="hex-offset">${off}</span><span class="hex-bytes">${hexParts.join('')}</span><span class="hex-ascii">${asciiParts.join('')}</span></div>`);
  }
  $('hexBody').innerHTML = `<div class="hex-container">${rows.join('')}</div>`;
}

function hexSetSelection(a, b) {
  if (a === -1) { hexSelectionStart = -1; hexSelectionEnd = -1; return; }
  hexSelectionStart = Math.min(a, b);
  hexSelectionEnd = Math.max(a, b);
  renderHex();
}

(function initHexInteractions() {
  const body = $('hexBody');
  body.addEventListener('pointerdown', e => {
    const cell = e.target.closest('.hex-cell[data-off]');
    if (!cell) return;
    const off = +cell.dataset.off;
    hexDragStart = off;
    hexDragActive = true;
    hexSetSelection(off, off);
    e.preventDefault();
  });
  body.addEventListener('pointermove', e => {
    if (!hexDragActive) return;
    const el = document.elementFromPoint(e.clientX, e.clientY);
    const cell = el && el.closest('.hex-cell[data-off]');
    if (!cell) return;
    hexSetSelection(hexDragStart, +cell.dataset.off);
  });
  body.addEventListener('pointerup', () => { hexDragActive = false; });
  body.addEventListener('pointercancel', () => { hexDragActive = false; });
  body.addEventListener('contextmenu', e => {
    const cell = e.target.closest('.hex-cell[data-off]');
    if (!cell) return;
    if (hexSelectionStart === -1) return;
    e.preventDefault();
    showHexContextMenu(e.clientX, e.clientY);
  });
  $('hexClose').onclick = () => { $('hexViewer').classList.add('hidden'); hexData = null; };
  $('hexPrev').onclick = () => { if (hexPage > 0) { hexPage--; renderHex(); } };
  $('hexNext').onclick = () => { if ((hexPage + 1) * HEX_PAGE < hexData.length) { hexPage++; renderHex(); } };
  $('hexSave').onclick = async () => {
    if (!hexData || !hexPath) return;
    const btn = $('hexSave'); const t = btn.textContent; btn.textContent = 'Saving…';
    let b64 = '';
    const chunk = 0x8000;
    for (let i = 0; i < hexData.length; i += chunk) {
      b64 += String.fromCharCode.apply(null, hexData.subarray(i, Math.min(i + chunk, hexData.length)));
    }
    b64 = btoa(b64);
    const r = await post('file_write_bin', { path: hexPath, base64: b64 });
    btn.textContent = t;
    if (r.ok) { toast('Saved'); hexDirty = false; renderHex(); }
    else toast('Save failed');
  };
  $('hexDl').onclick = () => { if (hexPath) window.open('?api=raw&path=' + encodeURIComponent(hexPath) + '&dl=1', '_blank'); };
  window.addEventListener('resize', () => {
    if (hexData && !$('hexViewer').classList.contains('hidden')) renderHex();
  });
})();

let hexCtxMenu = null;
function showHexContextMenu(x, y) {
  if (hexCtxMenu) hexCtxMenu.remove();
  const menu = document.createElement('div');
  menu.className = 'fixed z-[85] bg-white rounded-xl shadow-2xl py-1 min-w-[180px] overflow-hidden border border-black/[0.08]';
  const w = 200, h = 100;
  const left = Math.min(x, window.innerWidth - w - 8);
  const top = Math.min(y, window.innerHeight - h - 8);
  menu.style.left = left + 'px';
  menu.style.top = top + 'px';
  menu.innerHTML = `
    <button data-a="fill" class="w-full text-left px-4 py-2.5 hover:bg-black/5 text-[13px] text-gtext">Fill Selection</button>
    <button data-a="copy" class="w-full text-left px-4 py-2.5 hover:bg-black/5 text-[13px] text-gtext border-t border-black/[0.05]">Copy Block</button>
  `;
  document.body.appendChild(menu);
  hexCtxMenu = menu;
  const close = (ev) => {
    if (menu.contains(ev.target)) return;
    menu.remove(); hexCtxMenu = null;
    document.removeEventListener('pointerdown', close);
  };
  setTimeout(() => document.addEventListener('pointerdown', close), 0);
  menu.onclick = e => {
    const b = e.target.closest('[data-a]');
    if (!b) return;
    menu.remove(); hexCtxMenu = null;
    if (b.dataset.a === 'fill') hexFillSelection();
    else if (b.dataset.a === 'copy') hexCopyBlock();
  };
}
function hexFillSelection() {
  if (hexSelectionStart === -1) return;
  const dlg = document.createElement('div');
  dlg.className = 'fixed inset-0 z-[86] flex items-center justify-center p-6';
  dlg.innerHTML = `
    <div class="absolute inset-0 bg-black/60"></div>
    <div class="relative w-full max-w-sm bg-[#161b22] rounded-2xl p-5 text-white shadow-2xl">
      <div class="text-[15px] font-medium mb-1">Fill Selection</div>
      <div class="text-[12px] text-white/50 mb-3">Byte value (hex, 00-FF)</div>
      <input id="hexFillInput" type="text" maxlength="2" value="00" autocomplete="off" spellcheck="false"
             class="w-full bg-white/5 text-white rounded-lg px-3 py-2.5 outline-none text-[15px] font-mono text-center uppercase tracking-widest border border-white/10 focus:border-[#8ab4f8] mb-4">
      <div class="flex justify-end gap-2">
        <button data-cancel class="px-4 py-2 rounded-full text-[13px] text-white/70 hover:bg-white/10">Cancel</button>
        <button data-ok class="px-4 py-2 rounded-full bg-[#1a73e8] text-white text-[13px] font-medium">Fill</button>
      </div>
    </div>`;
  document.body.appendChild(dlg);
  const input = dlg.querySelector('#hexFillInput');
  setTimeout(() => { input.focus(); input.select(); }, 40);
  const close = () => dlg.remove();
  dlg.querySelector('[data-cancel]').onclick = close;
  dlg.querySelector('.absolute').onclick = close;
  dlg.querySelector('[data-ok]').onclick = () => {
    let v = input.value.trim().toLowerCase().replace(/[^0-9a-f]/g, '');
    if (v.length === 0) v = '00';
    if (v.length === 1) v = '0' + v;
    v = v.slice(0, 2);
    const nb = parseInt(v, 16);
    if (isNaN(nb)) { close(); return; }
    for (let i = hexSelectionStart; i <= hexSelectionEnd; i++) hexData[i] = nb;
    hexDirty = true;
    const s = hexSelectionStart, e = hexSelectionEnd;
    renderHex();
    hexSelectionStart = s; hexSelectionEnd = e;
    close();
  };
  input.onkeydown = e => { if (e.key === 'Enter') { e.preventDefault(); dlg.querySelector('[data-ok]').click(); } };
}
function hexCopyBlock() {
  if (hexSelectionStart === -1) return;
  const parts = [];
  for (let i = hexSelectionStart; i <= hexSelectionEnd; i++) parts.push(hexData[i].toString(16).padStart(2, '0').toUpperCase());
  const text = parts.join(' ');
  if (navigator.clipboard && navigator.clipboard.writeText) {
    navigator.clipboard.writeText(text).then(() => toast('Copied ' + parts.length + ' bytes')).catch(() => toast('Copy failed'));
  } else {
    const ta = document.createElement('textarea');
    ta.value = text; document.body.appendChild(ta); ta.select();
    try { document.execCommand('copy'); toast('Copied'); } catch (e) { toast('Copy failed'); }
    document.body.removeChild(ta);
  }
}

let hexSearchTab = 'offset';
function openHexSearch() {
  $('hexSearchModal').classList.remove('hidden');
  const tabs = $('hexSearchModal').querySelectorAll('.hs-tab');
  tabs.forEach(t => {
    const active = t.dataset.tab === hexSearchTab;
    t.style.background = active ? 'rgba(26,115,232,0.35)' : 'transparent';
    t.style.color = active ? '#ffffff' : 'rgba(255,255,255,0.6)';
    t.onclick = () => { hexSearchTab = t.dataset.tab; updateHexSearchPlaceholder(); tabs.forEach(x => {
      const a = x.dataset.tab === hexSearchTab;
      x.style.background = a ? 'rgba(26,115,232,0.35)' : 'transparent';
      x.style.color = a ? '#ffffff' : 'rgba(255,255,255,0.6)';
    }); };
  });
  updateHexSearchPlaceholder();
  setTimeout(() => $('hexSearchInput').focus(), 60);
}
function updateHexSearchPlaceholder() {
  const el = $('hexSearchInput');
  if (hexSearchTab === 'offset') el.placeholder = 'e.g., 1A2B or 0x1A2B';
  else if (hexSearchTab === 'hash') el.placeholder = 'e.g., 48 65 6C 6C 6F';
  else el.placeholder = 'e.g., Hello world';
}
$('hexSearch').onclick = openHexSearch;
$('hexSearchCancel').onclick = () => $('hexSearchModal').classList.add('hidden');
$('hexSearchModal').querySelector('[data-close]').onclick = () => $('hexSearchModal').classList.add('hidden');
$('hexSearchGo').onclick = () => {
  const input = $('hexSearchInput').value;
  const opt = $('hexSearchModal').querySelector('input[name="hexSearchOpt"]:checked').value;
  $('hexSearchModal').classList.add('hidden');
  runHexSearch(input, opt);
};
$('hexSearchInput').onkeydown = e => { if (e.key === 'Enter') { e.preventDefault(); $('hexSearchGo').click(); } };

function hexScrollToOffset(n) {
  if (n < 0 || n >= hexData.length) return;
  hexPage = Math.floor(n / HEX_PAGE);
  renderHex();
  requestAnimationFrame(() => {
    const cell = document.querySelector(`.hex-cell[data-off="${n}"]`);
    if (cell) cell.scrollIntoView({ block: 'center', behavior: 'smooth' });
  });
}
function runHexSearch(input, opt) {
  if (!hexData || !input) return;
  if (hexSearchTab === 'offset') {
    let n = NaN;
    const clean = input.replace(/^0x/i, '').trim();
    if (/^[0-9a-f]+$/i.test(clean)) n = parseInt(clean, 16);
    else if (/^\d+$/.test(input)) n = parseInt(input, 10);
    if (isNaN(n) || n < 0 || n >= hexData.length) { toast('Offset out of range'); return; }
    hexMatches = []; hexSelectionStart = -1; hexSelectionEnd = -1;
    hexScrollToOffset(n);
    return;
  }
  let bytes;
  if (hexSearchTab === 'hash') {
    const clean = input.replace(/[^0-9a-fA-F]/g, '');
    if (clean.length < 2 || clean.length % 2 !== 0) { toast('Invalid hex'); return; }
    bytes = [];
    for (let i = 0; i < clean.length; i += 2) bytes.push(parseInt(clean.substr(i, 2), 16));
  } else {
    bytes = Array.from(new TextEncoder().encode(input));
  }
  if (!bytes.length) return;
  const matchAt = (i) => {
    if (i + bytes.length > hexData.length) return false;
    for (let j = 0; j < bytes.length; j++) if (hexData[i + j] !== bytes[j]) return false;
    return true;
  };
  const currentOff = hexPage * HEX_PAGE;
  if (opt === 'all') {
    const all = [];
    for (let i = 0; i <= hexData.length - bytes.length; i++) if (matchAt(i)) all.push(i);
    hexMatches = all;
    if (all.length) { hexScrollToOffset(all[0]); toast(all.length + ' match' + (all.length === 1 ? '' : 'es')); }
    else { toast('No matches'); }
    return;
  }
  if (opt === 'forward') {
    for (let i = currentOff + 1; i <= hexData.length - bytes.length; i++) {
      if (matchAt(i)) { hexMatches = [i]; hexScrollToOffset(i); return; }
    }
    toast('No matches forward');
    return;
  }
  if (opt === 'backward') {
    for (let i = currentOff - 1; i >= 0; i--) {
      if (matchAt(i)) { hexMatches = [i]; hexScrollToOffset(i); return; }
    }
    toast('No matches backward');
  }
}

let sheetData = [];
let currentSheet = null;
function openSheetEditor(it) {
  $('sheetViewer').classList.remove('hidden');
  $('sheetName').textContent = it.name;
  $('sheetMeta').textContent = 'Loading…';
  $('sheetBody').innerHTML = '<div class="flex justify-center py-20"><span class="msr spin text-gsub" style="font-size:32px">progress_activity</span></div>';
  get('sheet_read', { path: it.path }).then(r => {
    if (!r.ok) {
      $('sheetBody').innerHTML = '<div class="text-center py-20 text-gsub">Could not read table: ' + esc(r.error || 'unknown') + '</div>';
      return;
    }
    sheetData = r.rows.map(row => row.slice());
    if (!sheetData.length) sheetData = [['']];
    currentSheet = { path: it.path, ext: r.ext };
    renderSheet();
  });
}
function renderSheet() {
  if (!sheetData.length) return;
  const maxc = Math.max.apply(null, sheetData.map(r => r.length).concat([1]));
  sheetData.forEach(r => { while (r.length < maxc) r.push(''); });
  $('sheetMeta').textContent = sheetData.length + ' rows · ' + maxc + ' cols';
  const rows = [];
  rows.push('<table class="border-collapse text-[13px] bg-white">');
  rows.push('<thead><tr><th class="sticky left-0 top-0 z-20 bg-[#f1f3f4] border border-black/10 text-gsub text-[11px] px-2 py-1 w-10">#</th>');
  for (let c = 0; c < maxc; c++) {
    rows.push(`<th class="sticky top-0 z-10 bg-[#f1f3f4] border border-black/10 px-2 py-1 text-[11px] text-gsub w-[100px]">${String.fromCharCode(65 + (c % 26))}${c >= 26 ? Math.floor(c / 26) : ''}</th>`);
  }
  rows.push('</tr></thead><tbody>');
  sheetData.forEach((row, ri) => {
    rows.push('<tr>');
    rows.push(`<td class="sticky left-0 z-10 bg-[#f1f3f4] border border-black/10 text-center text-[11px] text-gsub px-2 py-1">${ri + 1}</td>`);
    row.forEach((val, ci) => {
      rows.push(`<td class="border border-black/10 px-2 py-1 table-cell" contenteditable="true" data-r="${ri}" data-c="${ci}" onblur="window.__sheetCommit(this)">${esc(val)}</td>`);
    });
    rows.push('</tr>');
  });
  rows.push('</tbody></table>');
  $('sheetBody').innerHTML = `<div class="p-3"><div class="inline-block bg-white rounded-lg shadow overflow-auto max-w-full" style="max-height:calc(100vh - 160px)">${rows.join('')}</div></div>`;
}
window.__sheetCommit = function (el) {
  const r = +el.dataset.r, c = +el.dataset.c;
  if (sheetData[r]) sheetData[r][c] = el.textContent;
};
$('sheetClose').onclick = () => { $('sheetViewer').classList.add('hidden'); sheetData = []; currentSheet = null; };
$('sheetRowAdd').onclick = () => { const mc = sheetData[0] ? sheetData[0].length : 1; sheetData.push(new Array(mc).fill('')); renderSheet(); };
$('sheetColAdd').onclick = () => { sheetData.forEach(r => r.push('')); renderSheet(); };
$('sheetSave').onclick = async () => {
  if (!currentSheet) return;
  const btn = $('sheetSave'); btn.textContent = 'Saving…';
  const r = await post('sheet_write', { path: currentSheet.path, rows: sheetData });
  btn.textContent = 'Save';
  if (r.ok) toast('Saved'); else toast('Save failed: ' + (r.error || ''));
};

let archiveState = { path: '', name: '', password: '' };
function openArchiveViewer(it) {
  archiveState = { path: it.path, name: it.name, password: '' };
  $('archiveName').textContent = it.name;
  $('archiveMeta').textContent = 'Loading…';
  $('archivePw').classList.add('hidden');
  $('archiveBody').innerHTML = '<div class="flex justify-center py-20"><span class="msr spin text-gsub" style="font-size:32px">progress_activity</span></div>';
  $('archiveViewer').classList.remove('hidden');
  loadArchive();
}
async function loadArchive() {
  const params = { path: archiveState.path };
  if (archiveState.password) params.password = archiveState.password;
  const r = await get('zip_list', params);
  if (!r.ok) {
    if (r.error === 'password') {
      $('archivePw').classList.remove('hidden');
      $('archiveBody').innerHTML = '<div class="text-center py-20 text-gsub">Enter the archive password to list contents.</div>';
      return;
    }
    $('archiveBody').innerHTML = '<div class="text-center py-20 text-gsub">Could not open archive: ' + esc(r.error || '') + '</div>';
    return;
  }
  if (r.needsPassword && !archiveState.password) $('archivePw').classList.remove('hidden');
  else $('archivePw').classList.add('hidden');
  const items = r.items || [];
  $('archiveMeta').textContent = items.length + ' entr' + (items.length === 1 ? 'y' : 'ies');
  if (!items.length) { $('archiveBody').innerHTML = '<div class="text-center py-20 text-gsub">Archive is empty</div>'; return; }
  const rows = items.map((e, i) => {
    const isDir = e.dir;
    const ext = extOf(e.name);
    let icon = 'draft';
    if (isDir) icon = 'folder';
    else if (['jpg','jpeg','png','gif','webp','bmp','svg'].includes(ext)) icon = 'image';
    else if (['mp4','mkv','mov','avi','webm'].includes(ext)) icon = 'movie';
    else if (['mp3','wav','ogg','flac','m4a'].includes(ext)) icon = 'audiotrack';
    else if (['pdf','doc','docx','txt','md'].includes(ext)) icon = 'description';
    else if (['zip','rar','7z','tar','gz'].includes(ext)) icon = 'folder_zip';
    return `<div class="archive-row flex items-center gap-3 px-4 py-2.5 hover:bg-black/[0.03] ${isDir ? '' : 'cursor-pointer'}" data-idx="${i}">
      <span class="msr text-gsub shrink-0" style="font-size:20px">${icon}</span>
      <div class="flex-1 min-w-0">
        <div class="text-[13px] font-medium truncate">${esc(e.name)}</div>
        <div class="text-[11px] text-gsub mt-0.5">${isDir ? 'Folder' : fmtSize(e.size)}</div>
      </div>
      ${!isDir ? '<span class="msr text-gsub" style="font-size:18px">chevron_right</span>' : ''}
    </div>`;
  }).join('');
  $('archiveBody').innerHTML = `<div class="max-w-4xl mx-auto px-3 py-3 bg-white rounded-2xl m-3 shadow-[0_1px_3px_rgba(60,64,67,.08)] divide-y divide-black/[0.05]">${rows}</div>`;
  $('archiveBody').querySelectorAll('.archive-row').forEach(el => {
    el.onclick = () => {
      const idx = +el.dataset.idx;
      const e = items[idx];
      if (e.dir) return;
      previewArchiveFile(e.name);
    };
  });
}
async function previewArchiveFile(inner) {
  const params = { path: archiveState.path, inner: inner };
  if (archiveState.password) params.password = archiveState.password;
  const r = await get('zip_read', params);
  if (!r.ok) {
    if (r.error === 'password') { $('archivePw').classList.remove('hidden'); toast('Password required'); return; }
    if (r.error === 'toobig') { toast('File too large to preview'); return; }
    toast('Could not read file'); return;
  }
  const bin = atob(r.base64);
  const len = bin.length;
  const arr = new Uint8Array(len);
  for (let i = 0; i < len; i++) arr[i] = bin.charCodeAt(i);
  const blob = new Blob([arr]);
  const url = URL.createObjectURL(blob);
  const name = r.name;
  const ext = extOf(name);
  const isText = /^(txt|md|json|xml|yml|yaml|ini|cfg|conf|log|csv|tsv|html|htm|css|js|ts|py|rb|php|java|c|cpp|h|hpp|sh|bat|ps1|toml|properties|sql|go|rs|swift|kt|dart|lua|pl|r|scala|clj|ex|exs|erl|hs|jl|m|tex|rst|tcl|mk|makefile)$/i.test(ext) || ext === '';
  const dl = () => { const a = document.createElement('a'); a.href = url; a.download = name; a.click(); };
  if (['jpg','jpeg','png','gif','webp','bmp','svg'].includes(ext)) {
    $('viewerName').textContent = name;
    $('viewerDl').onclick = dl;
    $('viewerBody').innerHTML = `<img src="${url}" class="max-w-full max-h-full object-contain rounded-lg">`;
    $('viewer').classList.remove('hidden');
  } else if (['mp4','webm','mov','m4v','ogv'].includes(ext)) {
    $('viewerName').textContent = name;
    $('viewerDl').onclick = dl;
    $('viewerBody').innerHTML = `<video src="${url}" controls autoplay class="max-w-full max-h-full rounded-lg"></video>`;
    $('viewer').classList.remove('hidden');
  } else if (['mp3','wav','ogg','flac','m4a','opus'].includes(ext)) {
    $('viewerName').textContent = name;
    $('viewerDl').onclick = dl;
    $('viewerBody').innerHTML = `<div class="w-full max-w-md bg-white/5 rounded-3xl p-8 text-center"><span class="msr text-white/70" style="font-size:56px">audiotrack</span><div class="text-white text-[15px] font-medium mt-4 mb-6 break-all">${esc(name)}</div><audio src="${url}" controls autoplay class="w-full"></audio></div>`;
    $('viewer').classList.remove('hidden');
  } else if (isText) {
    $('viewerName').textContent = name;
    $('viewerDl').onclick = dl;
    let text = '';
    try { text = new TextDecoder('utf-8', { fatal: false }).decode(arr); } catch (e) {}
    $('viewerBody').innerHTML = `<pre class="w-full h-full bg-[#0d1117] text-white text-[12px] p-4 rounded-lg overflow-auto font-mono">${esc(text)}</pre>`;
    $('viewer').classList.remove('hidden');
  } else {
    $('viewerName').textContent = name;
    $('viewerDl').onclick = dl;
    $('viewerBody').innerHTML = `<div class="text-center"><span class="msr text-white/50" style="font-size:64px">draft</span><div class="text-white/80 text-[15px] mt-4 mb-6 break-all max-w-xs mx-auto">${esc(name)}</div><button id="arcDlBtn" class="px-6 py-3 rounded-full bg-white text-[#202124] text-[14px] font-medium">Download</button></div>`;
    setTimeout(() => { const b = document.getElementById('arcDlBtn'); if (b) b.onclick = dl; }, 30);
    $('viewer').classList.remove('hidden');
  }
}
$('archiveClose').onclick = () => { $('archiveViewer').classList.add('hidden'); };
$('archivePwGo').onclick = () => {
  const v = $('archivePwInput').value;
  if (!v) return;
  archiveState.password = v;
  loadArchive();
};
$('archivePwInput').addEventListener('keydown', e => { if (e.key === 'Enter') $('archivePwGo').click(); });

function fileInfo(it) {
  const c = it.dir ? CATS.folder : (CATS[it.cat] || CATS.other);
  $('sheetTitle').textContent = 'File info';
  $('sheetList').innerHTML = `<div class="px-5 pb-5">
    <div class="flex items-center gap-3 mb-4">
      <div class="w-14 h-14 rounded-2xl flex items-center justify-center shrink-0" style="background:${c.bg}">
        <span class="msr" style="font-size:26px;color:${c.fg}">${c.icon}</span>
      </div>
      <div class="min-w-0">
        <div class="text-[15px] font-medium break-all">${esc(it.name)}</div>
        <div class="text-[12px] text-gsub mt-0.5">${c.label}</div>
      </div>
    </div>
    <div class="space-y-2.5 text-[13px]">
      ${!it.dir ? `<div class="flex justify-between gap-4"><span class="text-gsub">Size</span><span class="font-medium">${fmtSize(it.size)}</span></div>` : ''}
      <div class="flex justify-between gap-4"><span class="text-gsub">Modified</span><span class="font-medium">${fmtDate(it.mtime)}</span></div>
      <div class="flex justify-between gap-4"><span class="text-gsub">Location</span><span class="font-medium truncate">${esc(it.path || '/')}</span></div>
    </div>
  </div>`;
  $('sheet').classList.remove('hidden');
}

function itemActions(i) {
  const it = state.items[i];
  if (!it) return;
  const acts = [];
  if (state.selecting) acts.push({ label: 'Deselect', icon: 'check_circle', fn: () => toggleSelect(i) });
  else {
    if (!it.dir) acts.push({ label: 'Open', icon: 'open_in_new', fn: () => openFile(it) });
    if (it.dir) acts.push({ label: 'Open', icon: 'folder_open', fn: () => openItem(i) });
  }
  if (!it.dir) acts.push({ label: 'Open in Hex editor', icon: 'data_object', fn: () => openHexViewer(it) });
  acts.push({ label: 'Info', icon: 'info', fn: () => fileInfo(it) });
  acts.push({ label: 'Select', icon: 'check_circle_outline', fn: () => enterSelectMode(i) });
  acts.push({ label: 'Rename', icon: 'edit', fn: () => askDialog('Rename', it.name, async v => {
    if (!v || v === it.name) return;
    const r = await post('rename', { path: it.path, name: v });
    if (r.ok) { toast('Renamed'); refresh(); } else toast('Rename failed');
  }) });
  if (!it.dir) acts.push({ label: 'Download', icon: 'download', fn: () => window.open(rawUrl(it) + '&dl=1', '_blank') });
  acts.push({ label: 'Delete', icon: 'delete', danger: true, fn: () => {
    openSheet('Delete "' + it.name + '"?', [
      { label: 'Delete permanently', icon: 'delete_forever', danger: true, fn: async () => {
        const r = await post('delete', { paths: [it.path] });
        if (r.ok) { toast('Deleted'); refresh(); } else toast('Delete failed');
      } },
      { label: 'Cancel', icon: 'close', fn: () => {} }
    ]);
  } });
  openSheet(it.name, acts);
}

function enterSelectMode(i) {
  state.selecting = true; state.selected.clear();
  if (i !== undefined && i !== null) state.selected.add(i);
  renderSelectionUI();
}
function toggleSelect(i) {
  if (state.selected.has(i)) state.selected.delete(i);
  else state.selected.add(i);
  if (state.selected.size === 0) state.selecting = false;
  renderSelectionUI();
}
function exitSelectMode() { state.selecting = false; state.selected.clear(); renderSelectionUI(); }
function renderSelectionUI() {
  document.querySelectorAll('[data-i]').forEach(el => {
    el.classList.toggle('selected', state.selected.has(+el.dataset.i));
  });
  document.body.classList.toggle('sel-active', state.selecting);
  const searchWrap = $('searchWrap');
  const selectPill = $('selectPill');
  const lockIcon = $('lockBtn').querySelector('.msr');
  if (state.selecting) {
    searchWrap.classList.add('hidden'); searchWrap.classList.remove('flex');
    selectPill.classList.remove('hidden'); selectPill.classList.add('flex');
    $('selectCountText').textContent = state.selected.size + ' selected';
    lockIcon.textContent = 'more_vert';
  } else {
    searchWrap.classList.remove('hidden'); searchWrap.classList.add('flex');
    selectPill.classList.add('hidden'); selectPill.classList.remove('flex');
    lockIcon.textContent = 'lock';
  }
  renderChips(); renderCrumb(); renderHeader();
}

let pressTimer = null;
let longPressed = false;
function attachLongPress() {
  const content = $('content');
  if (content._lp) return;
  content._lp = true;
  content.addEventListener('pointerdown', e => {
    const card = e.target.closest('[data-i]');
    if (!card || state.selecting) return;
    longPressed = false;
    clearTimeout(pressTimer);
    pressTimer = setTimeout(() => {
      longPressed = true;
      if (navigator.vibrate) { try { navigator.vibrate(20); } catch (_) {} }
      enterSelectMode(+card.dataset.i);
    }, 500);
  });
  ['pointerup','pointercancel','pointermove','pointerleave'].forEach(ev => {
    content.addEventListener(ev, () => clearTimeout(pressTimer));
  });
}

function openSortMenu() {
  const opts = [
    { id: 'name', label: 'Name (A–Z)' }, { id: 'name_desc', label: 'Name (Z–A)' },
    { id: 'date', label: 'Newest first' }, { id: 'date_asc', label: 'Oldest first' },
    { id: 'size', label: 'Largest first' }
  ];
  const acts = opts.map(o => ({
    label: o.label + (state.sort === o.id ? ' ✓' : ''),
    icon: 'sort_by_alpha',
    fn: () => { state.sort = o.id; refresh(); }
  }));
  acts.push({
    label: state.layout === 'grid' ? 'Switch to list view' : 'Switch to grid view',
    icon: state.layout === 'grid' ? 'view_list' : 'grid_view',
    fn: () => { state.layout = state.layout === 'grid' ? 'list' : 'grid'; refresh(); }
  });
  acts.push({
    label: state.showHidden ? 'Hide hidden files' : 'Show hidden files',
    icon: state.showHidden ? 'visibility_off' : 'visibility',
    fn: () => { state.showHidden = !state.showHidden; refresh(); }
  });
  openSheet('Sort & view', acts);
}

function getSelectedItems() { return [...state.selected].map(i => state.items[i]).filter(Boolean); }
function bulkPaths() { return getSelectedItems().map(it => it.path); }

function openBulkMenu() {
  if (!state.selected.size) return;
  const n = state.selected.size;
  const actions = [
    { label: 'Archive', icon: 'folder_zip', fn: () => bulkArchive() },
    { label: 'Download', icon: 'download', fn: () => bulkDownload() },
    { label: 'Copy', icon: 'content_copy', fn: () => bulkCopyMove('copy') },
    { label: 'Move', icon: 'drive_file_move', fn: () => bulkCopyMove('move') },
    { label: 'Delete', icon: 'delete', danger: true, fn: () => bulkDelete() },
    { label: 'Hide', icon: 'visibility_off', fn: () => bulkHide() },
    { label: 'Bulk rename', icon: 'edit_note', fn: () => bulkRename() },
    { label: 'Exit selection', icon: 'close', fn: () => exitSelectMode() }
  ];
  openSheet(n + ' item' + (n === 1 ? '' : 's') + ' selected', actions);
}
async function bulkDelete() {
  const n = state.selected.size;
  openSheet('Delete ' + n + ' item' + (n === 1 ? '' : 's') + '?', [
    { label: 'Delete permanently', icon: 'delete_forever', danger: true, fn: async () => {
      const r = await post('bulk', { action: 'delete', paths: bulkPaths() });
      exitSelectMode();
      if (r.ok) { toast('Deleted ' + r.count); refresh(); } else toast('Delete failed');
    } },
    { label: 'Cancel', icon: 'close', fn: () => {} }
  ]);
}
async function bulkHide() {
  const r = await post('bulk', { action: 'hide', paths: bulkPaths() });
  exitSelectMode();
  if (r.ok) { toast('Hidden ' + r.count); refresh(); } else toast('Could not hide');
}
async function bulkArchive() {
  toast('Archiving…');
  const r = await post('bulk', { action: 'archive', paths: bulkPaths() });
  exitSelectMode();
  if (r.ok) { toast('Created ' + r.name); refresh(); }
  else toast(r.error === 'nozip' ? 'Zip not available on server' : 'Archive failed');
}
async function bulkDownload() {
  toast('Preparing download…');
  try {
    const fd = new FormData();
    fd.append('action', 'download');
    fd.append('paths', JSON.stringify(bulkPaths()));
    const res = await fetch('?api=bulk', { method: 'POST', body: fd, credentials: 'same-origin' });
    if (!res.ok) { toast('Download failed'); return; }
    const blob = await res.blob();
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url; a.download = 'files_' + Date.now() + '.zip';
    document.body.appendChild(a); a.click(); document.body.removeChild(a);
    URL.revokeObjectURL(url);
    exitSelectMode(); toast('Downloaded');
  } catch (e) { toast('Download failed'); }
}
function bulkCopyMove(mode) {
  const verb = mode === 'copy' ? 'Copy' : 'Move';
  askDialog(verb + ' to folder', '', async v => {
    const r = await post('bulk', { action: mode, paths: bulkPaths(), dest: v });
    exitSelectMode();
    if (r.ok) { toast(verb + 'ed ' + r.count); refresh(); }
    else toast(r.error === 'baddest' ? 'Destination not found' : verb + ' failed');
  }, 'Relative to root. Leave empty for root folder.');
}
function bulkRename() {
  askDialog('Bulk rename', 'file', async v => {
    if (!v) return;
    const r = await post('bulk', { action: 'rename', paths: bulkPaths(), pattern: v, start: 1 });
    exitSelectMode();
    if (r.ok) { toast('Renamed ' + r.count); refresh(); } else toast('Rename failed');
  }, 'Files will become pattern_1, pattern_2, etc.');
}

function refresh() { load(); }

let appStarted = false;
function startApp() {
  if (appStarted) return;
  appStarted = true;
  routeFromUrl();
  load();
  window.addEventListener('popstate', () => { routeFromUrl(); load(); });
  window.addEventListener('hashchange', () => { routeFromUrl(); load(); });

  $('chips').onclick = e => {
    const b = e.target.closest('[data-chip]');
    if (!b) return;
    state.filter = b.dataset.chip;
    state.searching = false; state.search = '';
    $('searchInput').value = ''; $('clearSearch').classList.add('hidden');
    load();
  };

  $('backBtn').onclick = () => {
    if (state.selecting) { exitSelectMode(); return; }
    if (state.searching) {
      state.searching = false;
      $('searchInput').value = ''; $('clearSearch').classList.add('hidden');
      load(); return;
    }
    if (state.view === 'folder') {
      if (state.path) {
        const parts = state.path.split('/').filter(Boolean);
        parts.pop();
        if (parts.length === 0) { goFolderRoot(); return; }
        goFolder(parts.join('/'));
      } else {
        goHome();
      }
      return;
    }
    goHome();
  };
  $('mainBack').onclick = () => goHome();

  $('sortBtn').onclick = openSortMenu;
  $('lockBtn').onclick = () => {
    if (state.selecting) openBulkMenu();
    else openSheet('Lock this app?', [
      { label: 'Lock now', icon: 'lock', danger: true, fn: async () => { await post('logout'); location.reload(); } },
      { label: 'Cancel', icon: 'close', fn: () => {} }
    ]);
  };
  $('lockBtnHome').onclick = () => openSheet('Lock this app?', [
    { label: 'Lock now', icon: 'lock', danger: true, fn: async () => { await post('logout'); location.reload(); } },
    { label: 'Cancel', icon: 'close', fn: () => {} }
  ]);
  $('selectExitBtn').onclick = () => exitSelectMode();

  let searchTimer;
  $('searchInput').oninput = e => {
    const q = e.target.value.trim();
    $('clearSearch').classList.toggle('hidden', q === '');
    clearTimeout(searchTimer);
    if (q === '') { state.searching = false; load(); return; }
    searchTimer = setTimeout(() => doSearch(q), 300);
  };
  $('clearSearch').onclick = () => {
    $('searchInput').value = ''; $('clearSearch').classList.add('hidden');
    state.searching = false; load();
  };

  $('content').addEventListener('click', e => {
    if (longPressed) { longPressed = false; return; }
    const catCard = e.target.closest('[data-cat]');
    if (catCard) { goCategory(catCard.dataset.cat); return; }
    const goto = e.target.closest('[data-goto]');
    if (goto) {
      if (goto.dataset.goto === 'storage') { goFolderRoot(); return; }
      if (goto.dataset.goto === 'settings') { goSettings(); return; }
      if (goto.dataset.goto === 'about') { goAbout(); return; }
      return;
    }
    const more = e.target.closest('[data-more]');
    if (more) { e.stopPropagation(); itemActions(+more.dataset.more); return; }
    const card = e.target.closest('[data-i]');
    if (card) openItem(+card.dataset.i);
  });

  $('fab').onclick = () => {
    if (state.selecting) { exitSelectMode(); return; }
    $('fabMenu').classList.remove('hidden');
  };
  $('fabMenu').onclick = e => {
    if (e.target === $('fabMenu')) { $('fabMenu').classList.add('hidden'); return; }
    const b = e.target.closest('[data-act]');
    if (!b) return;
    $('fabMenu').classList.add('hidden');
    if (b.dataset.act === 'upload') $('fileInput').click();
    if (b.dataset.act === 'folder') askDialog('New folder', '', async v => {
      if (!v) return;
      const r = await post('mkdir', { path: state.path, name: v });
      if (r.ok) { toast('Folder created'); refresh(); } else toast('Could not create folder');
    });
    if (b.dataset.act === 'empty') askDialog('New empty file', '', async v => {
      if (!v) return;
      const r = await post('file_create', { path: state.path, name: v });
      if (r.ok) { toast('File created'); refresh(); } else toast('Could not create file');
    }, 'Enter a filename with extension, e.g. notes.txt');
  };

  $('fileInput').onchange = async e => {
    const files = e.target.files;
    if (!files || !files.length) return;
    const fd = new FormData();
    fd.append('path', state.path);
    for (const f of files) fd.append('files[]', f);
    toast('Uploading ' + files.length + ' file' + (files.length === 1 ? '' : 's') + '…');
    let r;
    try { r = await fetch('?api=upload', { method: 'POST', body: fd, credentials: 'same-origin' }).then(x => x.json()); }
    catch (err) { r = { ok: false }; }
    e.target.value = '';
    if (r.ok) { toast('Uploaded ' + r.count); refresh(); } else toast('Upload failed');
  };
}

if (IS_UNLOCKED) startApp();
</script>
</body>
</html>