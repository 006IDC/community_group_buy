#!/usr/bin/env php
<?php

$root = dirname(__DIR__);
$failures = [];

function auditFiles($root)
{
    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            function ($current) {
                return !$current->isDir() || !in_array($current->getFilename(), ['.git', 'dist', 'coverage'], true);
            }
        ),
        RecursiveIteratorIterator::LEAVES_ONLY
    );
    foreach ($iterator as $file) {
        if ($file->isFile()) {
            $files[str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen($root) + 1))]
                = $file->getPathname();
        }
    }
    return $files;
}

foreach (['LICENSE', 'README.md', 'AUTHOR.md', 'RELEASE_NOTES.md', 'DEPLOYMENT.md', 'CHANGELOG.md', 'SECURITY.md', 'CONTRIBUTING.md',
             'THIRD_PARTY_NOTICES.md', 'docs/ARCHITECTURE.md', 'docs/CONFIGURATION.md', 'docs/DATABASE.md',
             'docs/V10_COMPLIANCE.md', 'docs/AUDIT_REPORT.md', 'docs/RELEASE_CHECKLIST.md',
             '.github/workflows/ci.yml'] as $required) {
    if (!is_file($root . '/' . $required)) {
        $failures[] = '缺少开源仓库文件：' . $required;
    }
}

$textExtensions = ['php', 'js', 'css', 'html', 'md', 'yml', 'yaml', 'json', 'txt', 'gitattributes', 'gitignore'];
$patterns = [
    '私钥头' => '/-----BEGIN ' . '(?:RSA |EC |OPENSSH )?' . 'PRIVATE KEY-----/',
    'GitHub 令牌' => '/\b(?:ghp|github' . '_pat)_[A-Za-z0-9_]{20,}\b/',
    'AWS Access Key' => '/\bAK' . 'IA[0-9A-Z]{16}\b/',
    '本机用户路径' => '#/(?:Us' . 'ers|ho' . 'me)/[^/\s]+/#',
    'macOS 临时目录' => '#/var/fol' . 'ders/[A-Za-z0-9_/.-]+#',
    '历史品牌或内部更新标识' => '/(?:ZeroZero' . 'Six|zero_zero_' . 'six|零零' . '陆|zz' . '6gb|Private' . 'Updater)/i',
    'IPv4 地址' => '/(?<![0-9.])(?:25[0-5]|2[0-4][0-9]|1?[0-9]{1,2})(?:\.(?:25[0-5]|2[0-4][0-9]|1?[0-9]{1,2})){3}(?![0-9.])/',
];
foreach (auditFiles($root) as $relative => $path) {
    if (is_link($path)) {
        $failures[] = '仓库含符号链接：' . $relative;
        continue;
    }
    $extension = strtolower(pathinfo($relative, PATHINFO_EXTENSION));
    if (in_array($extension, ['zip', 'tar', 'gz', 'key', 'pem', 'p12', 'pfx'], true)) {
        $failures[] = '仓库含禁止提交的包或密钥：' . $relative;
        continue;
    }
    if (!in_array($extension, $textExtensions, true) && $relative[0] !== '.') {
        continue;
    }
    $content = (string) file_get_contents($path);
    foreach ($patterns as $label => $pattern) {
        if (preg_match($pattern, $content)) {
            $failures[] = $relative . ' 命中敏感信息规则：' . $label;
        }
    }
    if (preg_match_all('#https?://([A-Za-z0-9.-]+)(?::[0-9]{1,5})?#i', $content, $urls)) {
        foreach ($urls[1] as $host) {
            $host = strtolower((string) $host);
            if ($host !== '' && $host !== 'example.com' && substr($host, -12) !== '.example.com') {
                $failures[] = $relative . ' 含非示例域名：' . $host;
            }
        }
    }
    if (preg_match_all('/[A-Za-z0-9._%+-]+@([A-Za-z0-9.-]+\.[A-Za-z]{2,})/', $content, $emails)) {
        foreach ($emails[1] as $domain) {
            $domain = strtolower((string) $domain);
            if ($domain !== 'example.com' && substr($domain, -12) !== '.example.com') {
                $failures[] = $relative . ' 含非示例邮箱域名：' . $domain;
            }
        }
    }
}

$entry = (string) file_get_contents($root . '/CommunityGroupBuy.php');
$service = (string) file_get_contents($root . '/model/GroupBuyService.php');
if (substr_count($entry, 'CREATE TABLE IF NOT EXISTS') !== 2) {
    $failures[] = '新安装必须且只能创建两张插件业务表';
}
foreach (['addon_community_group_buy_group', 'addon_community_group_buy_member'] as $table) {
    if (strpos($entry, 'CREATE TABLE IF NOT EXISTS `{$p}' . $table . '`') === false) {
        $failures[] = '缺少必要业务表：' . $table;
    }
}
if (preg_match_all("/Db::name\('([^']+)'\)/", $service, $serviceTables)) {
    foreach (array_unique($serviceTables[1]) as $table) {
        if (strpos($table, 'addon_community_group_buy_') === 0
            && !in_array($table, ['addon_community_group_buy_group', 'addon_community_group_buy_member'], true)) {
            $failures[] = '运行时依赖了额外插件表：' . $table;
        }
    }
}
foreach (['private static function clientGroupView', 'private static function clientMembershipView',
             'm.order_id,m.final_amount,o.pay_time as paid_time'] as $needle) {
    if (strpos($service, $needle) === false) {
        $failures[] = '隐私边界契约缺失：' . $needle;
    }
}
if (strpos($service, "'display_name' => (string) \$member['display_name']") === false
    || strpos($service, "'order_id' => (int) \$member['order_id']") !== false) {
    $failures[] = '前台成员详情可能暴露订单字段';
}
if (strpos($service, 'publicExceptionMessage') === false || strpos($service, 'trustedShareOrigin') === false) {
    $failures[] = '公开异常或分享域名保护缺失';
}
$entryAuthor = (string) file_get_contents($root . '/CommunityGroupBuy.php');
$authorFile = (string) file_get_contents($root . '/AUTHOR.md');
if (strpos($entryAuthor, "'author' => '006IDC'") === false || substr_count($authorFile, '006IDC') !== 1) {
    $failures[] = '唯一作者声明不一致';
}
$authorLeakPattern = '/(?:Co-Authored' . '-By|共同' . '作者|Open' . 'AI|Chat' . 'GPT|Cod' . 'ex)/i';
foreach (auditFiles($root) as $relative => $path) {
    if (preg_match($authorLeakPattern, (string) file_get_contents($path))) {
        $failures[] = $relative . ' 含非唯一' . '作者标记';
    }
}

if (!empty($failures)) {
    foreach ($failures as $failure) {
        fwrite(STDERR, 'FAIL: ' . $failure . PHP_EOL);
    }
    exit(1);
}

echo json_encode([
    'status' => 'OK',
    'files_scanned' => count(auditFiles($root)),
    'plugin_tables' => 2,
    'secret_patterns_found' => 0,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
