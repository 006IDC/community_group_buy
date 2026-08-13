#!/usr/bin/env php
<?php

$root = dirname(__DIR__);
$entry = (string) file_get_contents($root . '/CommunityGroupBuy.php');

function sourceFail($message)
{
    fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
    exit(1);
}

if (!extension_loaded('zip')) {
    sourceFail('需要 PHP Zip 扩展');
}
if (preg_match("/['\"]version['\"]\s*=>\s*['\"]([0-9]+\.[0-9]+\.[0-9]+)['\"]/", $entry, $match) !== 1) {
    sourceFail('无法读取插件版本');
}
$version = $match[1];
$archiveRoot = 'community_group_buy-' . $version;
$output = $argv[1] ?? ($root . '/dist/community_group_buy_' . $version . '_github_source.zip');
$outputDir = dirname($output);
if (!is_dir($outputDir) && !mkdir($outputDir, 0755, true) && !is_dir($outputDir)) {
    sourceFail('无法创建输出目录');
}

$excludedDirectories = ['.git', 'dist', 'coverage'];
$excludedExtensions = ['zip', 'tar', 'gz', 'key', 'pem', 'p12', 'pfx', 'log'];
$files = [];
$iterator = new RecursiveIteratorIterator(
    new RecursiveCallbackFilterIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        function ($current) use ($excludedDirectories) {
            return !$current->isDir() || !in_array($current->getFilename(), $excludedDirectories, true);
        }
    ),
    RecursiveIteratorIterator::LEAVES_ONLY
);
foreach ($iterator as $file) {
    if ($file->isLink()) {
        sourceFail('源码目录禁止包含符号链接：' . $file->getPathname());
    }
    if (!$file->isFile() || $file->getFilename() === '.DS_Store') {
        continue;
    }
    $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen($root) + 1));
    $extension = strtolower(pathinfo($relative, PATHINFO_EXTENSION));
    if (in_array($extension, $excludedExtensions, true) || preg_match('#(^|/)\.env(?:\.|$)#', $relative)) {
        sourceFail('源码目录含禁止发布文件：' . $relative);
    }
    $files[$relative] = $file->getPathname();
}
ksort($files, SORT_STRING);

$zip = new ZipArchive();
if ($zip->open($output, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    sourceFail('无法创建源码包');
}
try {
    foreach ($files as $relative => $path) {
        if (!$zip->addFile($path, $archiveRoot . '/' . $relative)) {
            sourceFail('无法写入源码文件：' . $relative);
        }
    }
} finally {
    $zip->close();
}

echo json_encode([
    'status' => 'OK',
    'version' => $version,
    'source_files' => count($files),
    'path' => realpath($output),
    'sha256' => hash_file('sha256', $output),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
