#!/usr/bin/env php
<?php

$root = dirname(__DIR__);
$slug = basename($root);
$files = require __DIR__ . '/release_files.php';

function releaseFail($message)
{
    fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
    exit(1);
}

if (!extension_loaded('zip')) {
    releaseFail('需要 PHP Zip 扩展');
}
if ($slug !== 'community_group_buy') {
    releaseFail('插件目录必须命名为 community_group_buy');
}

$entry = (string) file_get_contents($root . '/CommunityGroupBuy.php');
if (preg_match("/['\"]version['\"]\s*=>\s*['\"]([0-9]+\.[0-9]+\.[0-9]+)['\"]/", $entry, $match) !== 1) {
    releaseFail('无法读取插件版本');
}
$version = $match[1];
$output = $argv[1] ?? ($root . '/dist/' . $slug . '_' . $version . '_v10_import.zip');
$outputDir = dirname($output);
if (!is_dir($outputDir) && !mkdir($outputDir, 0755, true) && !is_dir($outputDir)) {
    releaseFail('无法创建输出目录');
}

$expected = [];
foreach ($files as $relative) {
    if (!preg_match('#^[A-Za-z0-9._/-]+$#', $relative) || strpos($relative, '..') !== false) {
        releaseFail('运行时清单含不安全路径：' . $relative);
    }
    $path = $root . '/' . $relative;
    if (!is_file($path) || is_link($path)) {
        releaseFail('运行时文件缺失或为符号链接：' . $relative);
    }
    $expected[$slug . '/' . $relative] = hash_file('sha256', $path);
}
ksort($expected, SORT_STRING);

$zip = new ZipArchive();
if ($zip->open($output, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    releaseFail('无法创建发布包');
}
try {
    foreach ($files as $relative) {
        if (!$zip->addFile($root . '/' . $relative, $slug . '/' . $relative)) {
            releaseFail('无法写入：' . $relative);
        }
    }
} finally {
    $zip->close();
}

$zip = new ZipArchive();
if ($zip->open($output) !== true) {
    releaseFail('无法重新打开发布包');
}
$actual = [];
try {
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = (string) $zip->getNameIndex($i);
        if ($name === '' || substr($name, -1) === '/') {
            continue;
        }
        if (strpos($name, $slug . '/') !== 0 || strpos($name, '../') !== false) {
            releaseFail('发布包路径越界：' . $name);
        }
        $actual[$name] = hash('sha256', (string) $zip->getFromIndex($i));
    }
} finally {
    $zip->close();
}
ksort($actual, SORT_STRING);
if ($actual !== $expected) {
    releaseFail('发布包内容与运行时清单不一致');
}

echo json_encode([
    'status' => 'OK',
    'version' => $version,
    'runtime_files' => count($actual),
    'path' => realpath($output),
    'sha256' => hash_file('sha256', $output),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
