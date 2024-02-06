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

function exec_fix($command, &$result_code = 0)
{
    $process = proc_open($command, [
        1 => ['pipe', 'w'],
        2 => ['file', 'php://stderr', 'w'],
    ], $pipes);
    if (!is_resource($process)) {
        return null;
    }

    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);

    $result_code = proc_close($process);
    return trim($stdout);
}

function symlink_fix($target, $link)
{
    if (PHP_OS_FAMILY === 'Windows') {
        exec_fix('mklink "' . fix_path($link) . '" "' . fix_path($target) . '"', result_code: $code);
        return $code == 0;
    }
    return symlink($target, $link);
}

function readlink_fix($link)
{
    if (PHP_OS_FAMILY === 'Windows') {
        if (preg_match('/\[([^]]+)\]$/', exec_fix('dir "' . fix_path($link) . '"|findstr "<SYMLINK>"'), $link) === false) {
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
        } else if (str_ends_with(strtolower($file), '.ass') || str_ends_with(strtolower($file), '.ssa')) {
            $result[] = $dir . DIRECTORY_SEPARATOR . $file;
        }
    }
}

$_BOM_MAPPING = [
    "\xEF\xBB\xBF" => 'UTF-8',
    "\xFE\xFF" => 'UTF-16BE',
    "\xFF\xFE" => 'UTF-16LE',
    "\x00\x00\xFE\xFF" => 'UTF-32BE',
    "\xFF\xFE\x00\x00" => 'UTF-32LE',
    "\x2B\x2F\x76" => 'UTF-7',
    "\xF7\x64\x4C" => 'UTF-1',
    "\x84\x31\x95\x33" => 'GB18030',
];

$_INDEX = json_decode(file_get_contents(FONTS_STORAGE . DIRECTORY_SEPARATOR . 'index.json'), true);

$missing = [];
$install_tasks = [];

list_ass('.', $ass_files);
foreach ($ass_files as $file) {
    echo ('Parsing subtitle: ' . $file . PHP_EOL);
    $data = file_get_contents($file);

    // Check for BOMs first
    $encoding = '';
    foreach ($_BOM_MAPPING as $bom => $e) {
        if (str_starts_with($data, $bom)) {
            $encoding = $e;
            $data = substr($data, strlen($bom));

            if ($e !== 'UTF-8') {
                $data = mb_convert_encoding($data, 'UTF-8', $e);
            }
            break;
        }
    }

    // BOM not present, detection required
    if (empty($encoding) && !mb_check_encoding($data, 'UTF-8')) {
        // Test UTF-16 first
        foreach (['UTF-16LE', 'UTF-16BE'] as $e) {
            $try = mb_convert_encoding($data, 'UTF-8', $e);
            if (str_starts_with($try, '[Script Info]')) {
                $encoding = $e;
                $data = $try;
                break;
            }
        }

        if (empty($encoding)) {
            if (empty($encoding = mb_detect_encoding($data, [
                'CP936', 'BIG-5', 'SJIS',
            ], true))) {
                die('	unknown encoding');
            }
            $data = mb_convert_encoding($data, 'UTF-8', $encoding);
            echo ('	unusual encoding: ' . $encoding . PHP_EOL);
        }
    }

    // Parse SSA v4.00 / ASS
    if (!str_starts_with($data, '[Script Info]')) {
        die('	malformed file: not start with [Script Info]');
    }

    $fonts = [];
    $embedded_fonts = [];

    $styles = [];
    $current_section = '';
    $current_format = null;
    foreach (explode("\n", str_replace("\r", "\n", $data)) as $line) {
        $line = trim($line);
        if ($line == '' || $line[0] == ';' || str_starts_with($line, '!:')) {
            continue;
        }
        if ($line[0] == '[') {
            $current_section = strtolower($line);
            $current_format = null;
            continue;
        }

        if (!in_array($current_section, ['[v4 styles]', '[v4+ styles]', '[events]', '[fonts]'])) {
            continue;
        }

        $l1 = $line;
        $line = explode(':', $line, 2);
        if (count($line) !== 2) {
            die('	malformed file: unrecognized line');
        }
        [$type, $line] = $line;
        $line = array_map('trim', explode(',', $line, $current_format === null ? PHP_INT_MAX : count($current_format)));

        if ($type === 'Format') {
            $current_format = $line;
            continue;
        } else if ($current_format === null) {
            die('	malformed file: undefined format');
        }
        if (count($current_format) !== count($line)) {
            die('	malformed file: format mismatch');
        }
        $line = array_combine($current_format, $line);

        switch ($current_section) {
            case '[v4 styles]':
            case '[v4+ styles]':
                if ($type !== 'Style') {
                    die('	malformed file: unrecognized type ' . $type . ' in ' . $current_section);
                }
                if (!isset($line['Name'], $line['Fontname'], $line['Bold'], $line['Italic'])) {
                    die('	malformed file: Name / Fontname / Bold / Italic not found');
                }
                $fonts[] = $styles[$line['Name']] = [
                    trim($line['Fontname'], '@'),
                    boolval($line['Bold']),
                    boolval($line['Italic']),
                ];
                break;
            case '[events]':
                if ($type === 'Comment') {
                    break;
                } else if ($type !== 'Dialogue') {
                    // Will die when Picture / Sound / Movie / Command found
                    // Nobody use these anyway :)
                    die('	malformed file: unrecognized type ' . $type . ' in ' . $current_section);
                }
                if (!isset($line['Text'], $line['Style'])) {
                    die('	malformed file: Text / Style not found');
                }

                $style = $styles[$line['Style']] ?? $styles['Default'] ?? null;
                if ($style === null) {
                    // style not found and no default style, the player will use default font
                    break;
                }
                $style_fn = $style[0];

                if (preg_match_all('/{(\\\\[^}]+)}/', $line['Text'], $matches) === false) {
                    die('	malformed file: unable to parse override sequences');
                }
                foreach ($matches[1] as $match) {
                    $match = substr($match, 1);
                    foreach (explode('\\', $match) as $m) {
                        if (strlen($m) < 2) {
                            continue;
                        }
                        switch (substr($m, 0, 2)) {
                            case 'b1':
                                $style[1] = true;
                                break;
                            case 'b0':
                                $style[1] = false;
                                break;
                            case 'i1':
                                $style[2] = true;
                                break;
                            case 'i0':
                                $style[2] = false;
                                break;
                            case 'fn':
                                $style[0] = $m === 'fn' ? $style_fn : trim(substr($m, 2), '@');
                                break;
                            default:
                                continue 2;
                        }
                        $fonts[] = $style;
                    }
                }
                break;
            case '[fonts]':
                // TODO: we can't parse the font file yet
                break;
        }
    }

    // Filter fonts
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
