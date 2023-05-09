<?php
require_once('config.php');

$_INSTALL_TARGET = realpath($argv[1] ?? INSTALL_TARGET_DEFAULT);
echo ('Install Target: ' . $_INSTALL_TARGET . PHP_EOL);

if (!is_dir($_INSTALL_TARGET)) {
    die('Target is not a directory!');
}
if (!chdir($_INSTALL_TARGET)) {
    die('Unable to change directory!');
}

function fix_path($path, $replace = DIRECTORY_SEPARATOR)
{
    return str_replace(['/', '\\'], $replace, $path);
}

function relative_path($p1, $p2)
{
    $p1 = explode(DIRECTORY_SEPARATOR, fix_path($p1));
    $p2 = explode(DIRECTORY_SEPARATOR, fix_path($p2));

    for ($i = 0; $i < min(count($p1), count($p2)) && $p1[$i] == $p2[$i]; $i++);
    if ($i == 0) {
        return implode(DIRECTORY_SEPARATOR, $p1);
    }

    $p1 = array_splice($p1, $i);
    $p2 = array_splice($p2, $i);

    return str_repeat('..' . DIRECTORY_SEPARATOR, count($p2) - 1) . implode(DIRECTORY_SEPARATOR, $p1);
}

function symlink_fix($target, $link)
{
    if (PHP_OS_FAMILY === 'Windows') {
        exec('mklink "' . fix_path($link) . '" "' . fix_path($target) . '"', result_code: $code);
        return $code == 0;
    }
    return symlink($target, $link);
}

function readlink_fix($link)
{
    if (PHP_OS_FAMILY === 'Windows') {
        if (preg_match('/\[([^]]+)\]$/', exec('dir "' . fix_path($link) . '"|find "<SYMLINK>"'), $link) === false) {
            return null;
        }
        return $link[1];
    }
    return readlink($link);
}

function list_ass($dir, &$result)
{
    $fd = opendir($dir);
    while ($file = readdir($fd)) {
        if ($file === '.' || $file === '..') {
            continue;
        }
        if (is_dir($file)) {
            list_ass($dir . DIRECTORY_SEPARATOR . $file, $result);
        } else if (str_ends_with(strtolower($file), '.ass')) {
            $result[] = $dir . DIRECTORY_SEPARATOR . $file;
        }
    }
}

$_INDEX = json_decode(file_get_contents(FONTS_STORAGE . DIRECTORY_SEPARATOR . 'index.json'), true);

$missing = [];
$install_tasks = [];

list_ass('.', $ass_files);
foreach ($ass_files as $file) {
    echo ('Parsing subtitle: ' . $file . PHP_EOL);
    $data = file_get_contents($file);

    // Try to detect encoding
    $encoding = mb_detect_encoding($data);
    if ($encoding != 'UTF-8') {
        if (empty($encoding)) {
            foreach (['UTF-16LE', 'UTF-16BE'] as $c) {
                $try = mb_convert_encoding($data, 'UTF-8', $c);
                if (stripos($try, '[Script Info]') !== false) {
                    $encoding = $c;
                    $data = $try;
                    break;
                }
            }
        } else if ($encoding == 'CP936') {
            $data = mb_convert_encoding($data, 'UTF-8', $encoding);
        }
        echo ('	unusual encoding: ' . $encoding . PHP_EOL);

        if (empty($encoding)) {
            die('	unknown encoding');
        }
    }

    // Find styles sections
    if (!preg_match_all('/\[V4\+ Styles\]\s*\n([^[]+)\n\[/im', str_replace("\r", "\n", $data), $v4_styles)) {
        die('	bad ass file: V4+ styles not found');
    }
    $v4_styles = implode("\n", $v4_styles[1]);

    // Parse fonts
    $fonts = [];
    foreach (explode("\n", $v4_styles) as $line) {
        $line = trim($line);
        if ($line == '' || $line[0] == ';') {
            continue;
        }
        if (str_starts_with($line, 'Style: ')) {
            $data = explode(',', $line);
            $fonts[] = [trim($data[1], " \t\n\r\0\x0B@"), boolval($data[7]), boolval($data[8])];
        } else if (!str_starts_with(strtolower(str_replace(' ', '', $line)), 'format:name,fontname,fontsize,primarycolour,secondarycolour,outlinecolour,backcolour,bold,italic')) {
            die('	bad format or line: ' . $line);
        }
    }
    $fonts = array_unique($fonts, SORT_REGULAR);
    if (count($fonts) == 0) {
        die('	no fonts defined!');
    }
    $fonts = array_filter($fonts, fn ($v) => !in_array($v[0], IGNORED_FONTS));
    if (count($fonts) == 0) {
        continue;
    }

    // Create fonts dir
    $install_dir = dirname($file) . DIRECTORY_SEPARATOR . 'fonts';
    @mkdir($install_dir);
    $install_dir = realpath($install_dir) . DIRECTORY_SEPARATOR;

    // Prepare for installation tasks
    foreach ($fonts as [$name, $bold, $italic]) {
        if (!isset($_INDEX[$name])) {
            $k = $name . ' -- ' . ($bold ? 'B' : '_') . ($italic ? 'I' : '_');
            if (isset($missing[$k])) {
                $missing[$k][] = $file;
            } else {
                $missing[$k] = [$file];
            }
            continue;
        }
        $storage = $_INDEX[$name];
        if (isset($storage['X-PNP-IGNORED']) && $storage['X-PNP-IGNORED']) {
            continue;
        }

        $font = null;
        if (!$font && $bold && $italic) {
            $font = $storage['Bold Italic'] ?? $storage['Italic Bold'] ?? null;
        }
        if (!$font && $bold) {
            $font = $storage['Bold'] ?? $storage['Heavy'] ?? null;
        }
        if (!$font && $italic) {
            $font = $storage['Italic'] ?? $storage['Bold Italic'] ?? null;
        }
        if (!$font) {
            $font = $storage['Regular'] ?? $storage['Normal'] ?? null;
        }
        if (!$font) {
            $font = reset($storage);
            echo ('	No font matched! fallback: ' . $font . PHP_EOL);
        }

        $source = realpath(FONTS_STORAGE . DIRECTORY_SEPARATOR . $font);
        $target = $install_dir . fix_path($font, '.');

        $install_tasks[$target] = USE_RELATIVE_LINK ? relative_path($source, $target) : $source;
    }
}

echo ('Total tasks: ' . count($install_tasks) . ', start install...' . PHP_EOL);
if (DRY_RUN) {
    echo (' Dry run, skipping install' . PHP_EOL);
} else {
    foreach ($install_tasks as $target => $source) {
        if (file_exists($target)) {
            if (!is_link($target)) {
                echo (' File is not link: ' . $target . PHP_EOL);
                continue;
            }

            $link = readlink_fix($target);
            if (!$link) {
                echo (' Unable to read link: ' . $target . PHP_EOL);
                continue;
            }

            if ($link == $source) {
                continue;
            }
            echo (' Removed old link to ' . $link . PHP_EOL);
            unlink($target);
        }

        echo (' Installing ' . $target . ' => ' . $source . '...  ');
        if (!symlink_fix($source, $target)) {
            echo ('Failed' . PHP_EOL);
        } else {
            echo ('Success' . PHP_EOL);
        }
    }
}

$keys = array_keys($missing);
sort($keys);
file_put_contents(__DIR__ . '/missing.txt', implode("\n", $keys));
file_put_contents(__DIR__ . '/missing.json', json_encode($missing, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

echo ('Install complete, missing fonts: ' . count($missing) . PHP_EOL);
echo (implode(PHP_EOL, array_map(fn ($v) => "\t" . $v, array_keys($missing))) . PHP_EOL);
