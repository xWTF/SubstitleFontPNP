<?php
require_once('config.php');

chdir(FONTS_STORAGE);

$_INDEX = json_decode(file_get_contents('index.json'), true);

$count = 0;
foreach ($_INDEX as $types) {
    foreach ($types as $f) {
        if (!file_exists($f)) {
            echo ('Missing "' . $f . '"' . PHP_EOL);
        }
        $count++;
    }
}
echo ('Validate complete: ' . $count . PHP_EOL);

$count = 0;
$list = [];
foreach (glob('**/*.json') as $f) {
    $meta = json_decode(file_get_contents($f), true);
    foreach ($meta['fontFamily'] as $fam) {
        if (!isset($_INDEX[$fam])) {
            echo ('Unindexed "' . $f . '" family ' . $fam  . PHP_EOL);
            $list[] = dirname($f);
        }
    }
    $count++;
}
echo ('Index check complete: ' . $count . PHP_EOL);
echo (implode("\n", array_map(fn ($v) => 'cp -r "' . $v . '" ' . str_replace('\\', '/', __DIR__) . '/source/_reindex', array_values(array_unique($list)))));
