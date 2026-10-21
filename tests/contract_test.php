<?php

$root = dirname(__DIR__);

function failContract($message)
{
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}

function containsContract($text, $needle, $label)
{
    if (strpos($text, $needle) === false) {
        failContract($label . ' 缺少: ' . $needle);
    }
}

function excludesContract($text, $needle, $label)
{
    if (strpos($text, $needle) !== false) {
        failContract($label . ' 不应包含: ' . $needle);
    }
}

$entry = file_get_contents($root . '/CommunityGroupBuy.php');
$service = file_get_contents($root . '/model/GroupBuyService.php');
$routes = file_get_contents($root . '/route.php');
$adminController = file_get_contents($root . '/controller/AdminController.php');
$clientController = file_get_contents($root . '/controller/clientarea/IndexController.php');
$adminScript = file_get_contents($root . '/public/group-buy-admin.js');
$clientScript = file_get_contents($root . '/public/group-buy-client.js');
$adminTemplate = file_get_contents($root . '/template/admin/index.html');
$clientTemplate = file_get_contents($root . '/template/clientarea/index.html');
$readme = file_get_contents($root . '/README.md');
// Detailed business and upgrade guidance lives in the repository guide.
$readme .= "\n" . file_get_contents($root . '/docs/repository-guide.md');

foreach (['install', 'upgrade', 'uninstall', 'beforeOrderCreate', 'afterOrderCreate', 'orderPaid',
             'afterOrderRefund', 'afterOrderCancel', 'afterOrderDelete', 'minuteCron'] as $method) {
    containsContract($entry, 'public function ' . $method . '(', 'V10 生命周期/Hook');
}
containsContract($entry, "'name' => 'CommunityGroupBuy'", '稳定插件标识');
containsContract($entry, "'version' => '1.0.0'", '插件版本');
containsContract($entry, "'author' => '006IDC'", '唯一作者');
containsContract($entry, "where('name', 'PromoCode')", '官方优惠码依赖');
containsContract($entry, 'CREATE TABLE IF NOT EXISTS', '幂等迁移');
containsContract($entry, 'addon_community_group_buy_group', '团表');
containsContract($entry, 'addon_community_group_buy_member', '成员表');
excludesContract($entry, 'CREATE TABLE IF NOT EXISTS `{$p}addon_community_group_buy_campaign', '新安装不得创建活动表');
containsContract($entry, '$this->ensureTwoTableIndexes($prefix);', '两表索引幂等补齐');
excludesContract($entry, 'addon_community_group_buy_campaign', '独立开源身份不读取历史活动表');
excludesContract($entry, 'addon_community_group_buy_audit', '独立开源身份不读取历史审计表');
containsContract($entry, '$this->ensureHookRegistrations();', '覆盖升级 Hook 补齐');
containsContract($entry, '$this->ensureClientareaNavigation();', '覆盖升级导航补齐');
containsContract($entry, '$this->ensureClientareaSharePermission();', '覆盖升级分享权限补齐');

foreach (["Route::post('group'", "Route::post('group/:id/join'", "Route::get('group/:id/share'", "Route::get('settings'", "Route::put('settings'",
             'CheckHome::class', 'CheckAdmin::class', 'ParamFilter::class', 'RejectRepeatRequest::class'] as $contract) {
    containsContract($routes, $contract, '路由与中间件');
}
containsContract($routes, "Route::group('console/v1/community_group_buy'", '用户接口唯一前缀');
containsContract($routes, "Route::group(DIR_ADMIN . '/v1/community_group_buy'", '后台动态目录与唯一前缀');
foreach (['campaign', 'saveCampaign', 'campaignStatus'] as $legacyRoute) {
    excludesContract($routes, $legacyRoute, '路由不得保留后台活动模型');
    excludesContract($adminController, $legacyRoute, '后台控制器不得保留活动模型');
}
containsContract($clientController, "param('product_id'", '用户选商品发团');
containsContract($clientController, "param('target_size'", '用户选人数发团');
containsContract($clientController, "param('mode'", '用户选模式发团');
containsContract($clientController, "param('request_id'", '发团幂等请求标识');
containsContract($clientController, 'GroupBuyService::shareInfo', '拼团分享接口');

containsContract($service, 'const DEFAULT_RATES = [3 => 9000, 5 => 8000, 10 => 7000]', '9/8/7 折方案');
containsContract($service, 'const COST_RATE_BPS = 6500', '65% 成本率');
containsContract($service, 'const SAFETY_MARGIN_BPS = 500', '5% 安全垫');
containsContract($service, "const SUBSIDY_PRICE = '0.10'", '0.1 元固定价');
containsContract($service, 'bcmul($unitLoss, \'10\', 2)', '0.1 元开团预留10人亏损');
containsContract($service, '$targetSize = 10;', '0.1 元固定10人团');
containsContract($service, 'foreach ($members as $member)', '成团为每个成员发权益');
containsContract($service, "'promo_code_id' => \$promoId", '成员只引用一个官方优惠码');
containsContract($service, "'renew' => 0, 'loop' => 0", '续费与循环优惠禁用');
containsContract($service, "'status' => 'prepared'", '下单并发准备');
containsContract($service, '->lock(true)', '并发行锁');
containsContract($service, "'request_key'", '发团幂等唯一键');
containsContract($service, '(string) $order[\'type\'] !== \'new\'', '仅新购');
containsContract($service, 'unset($config[\'promo_code\'])', '原价重算排除权益码');
containsContract($service, 'Money::min($current, $target)', '不与已有更低优惠叠加');
containsContract($service, 'subsidy_reference_price', '0.1 元基础配置价格上限');
containsContract($service, "active_log('CommunityGroupBuy ", '运行时官方审计日志');
containsContract($service, "'/plugin/' . (int) \$plugin['id'] . '/index.htm?join_group='", '动态插件上车链接');
containsContract($service, "QRcode::text", '复用 V10 官方二维码矩阵');
containsContract($service, "p.hidden as product_hidden", '分享前商品可见性校验');
containsContract($service, 'private static function clientGroupView', '前台团字段白名单');
containsContract($service, 'private static function clientMembershipView', '我的拼团字段白名单');
containsContract($service, "'display_name' => (string) \$member['display_name']", '前台成员字段白名单');
containsContract($service, 'public static function publicExceptionMessage', '公开异常映射');
containsContract($service, "configuration('clientarea_url')", '可信会员中心分享域名');
containsContract($service, "->where('client_id', \$clientId)->whereIn('group_id', \$groupIds)->column('group_id')", '参团状态批量查询');
excludesContract($service, "return '用户 #' . (int) \$member['client_id']", '匿名展示不得回退暴露客户 ID');
excludesContract($service, 'addon_community_group_buy_campaign', '运行时不得依赖活动表');
excludesContract($service, 'normal_promo_code_id', '运行时成员表不得保留双码');
excludesContract($service, 'subsidy_promo_code_id', '运行时成员表不得保留双码');

foreach (['基础拼团白名单', '10人团0.1元基础款', '每人首次开通都付0.1元', '保存影响'] as $text) {
    containsContract($adminTemplate, $text, '后台白名单界面');
}
foreach (['自己发起拼团', '选择商品', '选择成团人数', '10个成员每人首付0.1元', '上车链接', '保存宣传图'] as $text) {
    containsContract($clientTemplate, $text, '用户自助发团界面');
}
containsContract($adminScript, "request('/settings', { method: 'PUT'", '后台单接口原子保存');
containsContract($adminScript, '设置已保存，但页面刷新未完成', '主写成功与刷新失败分离');
containsContract($clientScript, 'product_id: Number(product.id)', '用户发团商品参数');
containsContract($clientScript, 'target_size:', '用户发团人数参数');
containsContract($clientScript, 'request_id: state.requestId', '用户发团幂等参数');
containsContract($clientScript, '未能确认结果，请刷新核对', '网络结果未知文案');
containsContract($clientScript, "window.addEventListener('cgb:client-shell-mounted', boot)", 'Vue 挂载后初始化');
containsContract($clientScript, "createControl.setAttribute('data-cgb-bound', '1')", '当前按钮绑定标记');
containsContract($clientScript, "request('/group/' + Number(groupId) + '/share')", '邀请卡读取');
containsContract($clientScript, "new URLSearchParams(window.location.search).get('join_group')", '上车链接定位');
containsContract($clientScript, "canvas.toBlob", '宣传图下载');
containsContract($clientScript, "document.execCommand('copy')", 'HTTP 复制降级');
containsContract($clientScript, "buy.rel = 'noopener noreferrer'", '新窗口隔离');
excludesContract($clientScript, "document.addEventListener('DOMContentLoaded', boot)", '不得在 Vue 挂载前抢先绑定');
excludesContract($adminScript, '/campaign', '后台脚本不得请求活动接口');
excludesContract($clientScript, 'campaign_id', '用户脚本不得依赖活动 ID');

foreach (['用户自助选择商品', '每人预计亏损', '1013.00 元', 'plugin.config',
             '新安装只创建', 'GitHub 自动生成的 Source code 压缩包'] as $text) {
    containsContract($readme, $text, '业务/升级文档');
}

foreach (['LICENSE', 'SECURITY.md', 'CONTRIBUTING.md', 'THIRD_PARTY_NOTICES.md', 'docs/ARCHITECTURE.md',
             'AUTHOR.md', 'RELEASE_NOTES.md', 'DEPLOYMENT.md', 'docs/CONFIGURATION.md', 'docs/DATABASE.md', 'docs/V10_COMPLIANCE.md',
             'docs/AUDIT_REPORT.md', '.github/workflows/ci.yml', 'tools/build_release.php', 'tools/build_source_archive.php',
             'tools/repository_audit.php'] as $repositoryFile) {
    if (!is_file($root . '/' . $repositoryFile)) failContract('缺少开源仓库文件: ' . $repositoryFile);
}

foreach (['template/clientarea/index.html', 'template/clientarea/pc/default/index.html', 'template/clientarea/mobile/default/index.html'] as $template) {
    if (!is_file($root . '/' . $template)) failContract('缺少前台模板兼容路径: ' . $template);
}
if (file_get_contents($root . '/template/clientarea/pc/default/index.html') !== $clientTemplate
    || file_get_contents($root . '/template/clientarea/mobile/default/index.html') !== $clientTemplate) {
    failContract('PC/Mobile 模板必须与主模板字节一致');
}

$zhCn = require $root . '/lang/zh-cn.php';
if (($zhCn['nav_plugin_addon_community_group_buy'] ?? '') !== '商品拼团') {
    failContract('后台导航语言键缺失或文案错误');
}

echo "contract_test: OK\n";
