<?php

namespace addon\community_group_buy;

use addon\community_group_buy\model\GroupBuyService;
use app\common\lib\Plugin;
use think\facade\Db;

/**
 * 商品拼团。
 *
 * 公有方法只保留 V10 生命周期与真实 Hook，避免内部方法被注册为 Hook。
 */
class CommunityGroupBuy extends Plugin
{
    public $info = [
        'name' => 'CommunityGroupBuy',
        'title' => '商品拼团',
        'description' => '用户自助选商品发起3人、5人、10人团，支持白名单内10人全0.1元基础款，只优惠首次开通。',
        'author' => '006IDC',
        'version' => '1.0.0',
    ];

    public function install()
    {
        $this->assertPromoCodeDependency();
        $this->migrate();
        return true;
    }

    public function upgrade()
    {
        $this->assertPromoCodeDependency();
        $this->migrate();
        $this->ensureHookRegistrations();
        $this->ensureHookOrder();
        $this->ensureClientareaSharePermission();
        if (function_exists('lang_plugins')) {
            lang_plugins('', [], true);
        }
        $this->ensureAdminNavigationLabel();
        $this->ensureClientareaNavigation();
        return true;
    }

    public function uninstall()
    {
        // 团购、补贴和订单归因属于财务审计数据，默认保留。
        return true;
    }

    public function beforeOrderCreate($param)
    {
        return GroupBuyService::beforeOrderCreate((array) $param);
    }

    public function afterOrderCreate($param)
    {
        return GroupBuyService::afterOrderCreate((int) ($param['id'] ?? 0), (array) ($param['customfield'] ?? []));
    }

    public function orderPaid($param)
    {
        return GroupBuyService::orderPaid((int) ($param['id'] ?? 0));
    }

    public function afterOrderRefund($param)
    {
        return GroupBuyService::afterOrderRefund((int) ($param['id'] ?? 0));
    }

    public function afterOrderCancel($param)
    {
        return GroupBuyService::releaseOrder((int) ($param['id'] ?? 0), 'cancelled');
    }

    public function afterOrderDelete($param)
    {
        return GroupBuyService::releaseOrder((int) ($param['id'] ?? 0), 'deleted');
    }

    public function minuteCron($param = [])
    {
        GroupBuyService::expireDueRows();
        return true;
    }

    private function assertPromoCodeDependency()
    {
        $dependency = Db::name('plugin')
            ->where('module', 'addon')
            ->where('name', 'PromoCode')
            ->find();
        if (empty($dependency) || (int) $dependency['status'] !== 1) {
            throw new \RuntimeException('请先安装并启用 V10 官方“优惠码插件(基础版)”');
        }
    }

    private function migrate()
    {
        $prefix = $this->dbPrefix();
        foreach ($this->installSql($prefix) as $sql) {
            Db::execute($sql);
        }
        $this->ensureTwoTableIndexes($prefix);
    }

    private function dbPrefix()
    {
        foreach (['database.prefix', 'database.connections.mysql.prefix'] as $key) {
            try {
                $prefix = (string) config($key);
                if ($prefix !== '') {
                    return $prefix;
                }
            } catch (\Throwable $e) {
            }
        }
        try {
            $config = Db::getConfig();
            if (is_array($config) && !empty($config['prefix'])) {
                return (string) $config['prefix'];
            }
        } catch (\Throwable $e) {
        }
        return 'idcsmart_';
    }

    private function installSql($prefix)
    {
        $p = str_replace('`', '', (string) $prefix);
        return [
            "CREATE TABLE IF NOT EXISTS `{$p}addon_community_group_buy_group` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `group_no` varchar(40) NOT NULL DEFAULT '',
  `request_key` varchar(96) NULL DEFAULT NULL,
  `leader_client_id` int(11) unsigned NOT NULL DEFAULT 0,
  `product_id` int(11) unsigned NOT NULL DEFAULT 0,
  `mode` varchar(16) NOT NULL DEFAULT 'normal',
  `target_size` tinyint(3) unsigned NOT NULL DEFAULT 3,
  `joined_count` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `price_rate_bps` smallint(5) unsigned NOT NULL DEFAULT 9000 COMMENT '普通团实付比例',
  `cost_rate_bps` smallint(5) unsigned NOT NULL DEFAULT 6500,
  `safety_margin_bps` smallint(5) unsigned NOT NULL DEFAULT 500,
  `subsidy_price` decimal(18,2) unsigned NOT NULL DEFAULT '0.10',
  `subsidy_reference_price` decimal(18,2) unsigned NOT NULL DEFAULT '0.00',
  `subsidy_unit_loss` decimal(18,2) unsigned NOT NULL DEFAULT '0.00',
  `reserved_loss` decimal(18,2) unsigned NOT NULL DEFAULT '0.00',
  `status` varchar(20) NOT NULL DEFAULT 'gathering',
  `expire_time` int(11) unsigned NOT NULL DEFAULT 0,
  `success_time` int(11) unsigned NOT NULL DEFAULT 0,
  `claim_expire_time` int(11) unsigned NOT NULL DEFAULT 0,
  `create_time` int(11) unsigned NOT NULL DEFAULT 0,
  `update_time` int(11) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_group_no` (`group_no`),
  UNIQUE KEY `uk_request_key` (`request_key`),
  KEY `idx_product_mode_status` (`product_id`,`mode`,`status`),
  KEY `idx_expire_status` (`expire_time`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='商品拼团团与风控快照';",

            "CREATE TABLE IF NOT EXISTS `{$p}addon_community_group_buy_member` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `group_id` bigint(20) unsigned NOT NULL DEFAULT 0,
  `client_id` int(11) unsigned NOT NULL DEFAULT 0,
  `role` varchar(12) NOT NULL DEFAULT 'member',
  `join_order` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `status` varchar(20) NOT NULL DEFAULT 'joined',
  `promo_code_id` int(11) unsigned NULL DEFAULT NULL,
  `reserved_loss` decimal(18,2) unsigned NOT NULL DEFAULT '0.00',
  `order_id` int(11) unsigned NOT NULL DEFAULT 0,
  `final_amount` decimal(18,2) unsigned NOT NULL DEFAULT '0.00',
  `prepare_time` int(11) unsigned NOT NULL DEFAULT 0,
  `expire_time` int(11) unsigned NOT NULL DEFAULT 0,
  `create_time` int(11) unsigned NOT NULL DEFAULT 0,
  `update_time` int(11) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_group_client` (`group_id`,`client_id`),
  UNIQUE KEY `uk_promo_code_id` (`promo_code_id`),
  KEY `idx_client_status` (`client_id`,`status`),
  KEY `idx_order_id` (`order_id`),
  KEY `idx_status_expire` (`status`,`expire_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='商品拼团成员与官方一次性权益引用';",
        ];
    }

    private function ensureTwoTableIndexes($prefix)
    {
        $groupTable = $this->tableName($prefix, 'addon_community_group_buy_group');
        $memberTable = $this->tableName($prefix, 'addon_community_group_buy_member');
        $groupIndexes = [
            'uk_request_key' => 'UNIQUE KEY `uk_request_key` (`request_key`)',
            'idx_product_mode_status' => 'KEY `idx_product_mode_status` (`product_id`,`mode`,`status`)',
        ];
        foreach ($groupIndexes as $index => $definition) {
            if (!$this->indexExists($groupTable, $index)) {
                Db::execute("ALTER TABLE `{$groupTable}` ADD {$definition}");
            }
        }
        $memberIndexes = [
            'uk_promo_code_id' => 'UNIQUE KEY `uk_promo_code_id` (`promo_code_id`)',
            'idx_client_status' => 'KEY `idx_client_status` (`client_id`,`status`)',
        ];
        foreach ($memberIndexes as $index => $definition) {
            if (!$this->indexExists($memberTable, $index)) {
                Db::execute("ALTER TABLE `{$memberTable}` ADD {$definition}");
            }
        }
    }

    private function indexExists($table, $index)
    {
        $rows = Db::query('SELECT COUNT(*) AS total FROM information_schema.STATISTICS '
            . 'WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=?', [$table, $index]);
        return !empty($rows) && (int) $rows[0]['total'] > 0;
    }

    private function tableName($prefix, $name)
    {
        return str_replace('`', '', (string) $prefix . (string) $name);
    }

    private function ensureHookRegistrations()
    {
        $hooks = [
            'before_order_create',
            'after_order_create',
            'order_paid',
            'after_order_refund',
            'after_order_cancel',
            'after_order_delete',
            'minute_cron',
        ];
        foreach ($hooks as $hook) {
            $rows = Db::name('plugin_hook')
                ->where('name', $hook)
                ->where('plugin', 'CommunityGroupBuy')
                ->where('module', 'addon')
                ->order('id', 'asc')
                ->select()
                ->toArray();
            if (empty($rows)) {
                Db::name('plugin_hook')->insert([
                    'name' => $hook,
                    'status' => 1,
                    'plugin' => 'CommunityGroupBuy',
                    'module' => 'addon',
                    'order' => 0,
                ]);
                continue;
            }
            $keepId = (int) $rows[0]['id'];
            Db::name('plugin_hook')->where('id', $keepId)->update(['status' => 1]);
            if (count($rows) > 1) {
                Db::name('plugin_hook')->whereIn('id', array_map('intval', array_column(array_slice($rows, 1), 'id')))->delete();
            }
        }
    }

    private function ensureHookOrder()
    {
        // 让本插件的 after_order_create 在官方优惠码之后做最终金额对账。
        Db::name('plugin')->where('module', 'addon')->where('name', 'CommunityGroupBuy')
            ->update(['hook_order' => 1000, 'update_time' => time()]);
    }

    private function ensureClientareaNavigation()
    {
        $pluginId = (int) Db::name('plugin')
            ->where('module', 'addon')
            ->where('name', 'CommunityGroupBuy')
            ->value('id');
        if ($pluginId <= 0) {
            return;
        }
        $name = 'nav_plugin_addon_community_group_buy_clientarea';
        $url = 'plugin/' . $pluginId . '/index.htm';
        $nav = Db::name('nav')
            ->where('type', 'home')
            ->where('module', 'addon')
            ->where('plugin', 'CommunityGroupBuy')
            ->where('name', $name)
            ->find();
        if (empty($nav)) {
            $navId = (int) Db::name('nav')->insertGetId([
                'type' => 'home', 'name' => $name, 'url' => $url, 'icon' => 'shopping-cart',
                'parent_id' => 0, 'order' => (int) Db::name('nav')->max('order') + 1,
                'module' => 'addon', 'plugin' => 'CommunityGroupBuy',
            ]);
        } else {
            $navId = (int) $nav['id'];
            Db::name('nav')->where('id', $navId)->update(['url' => $url, 'icon' => 'shopping-cart']);
        }
        if ($navId <= 0) {
            throw new \RuntimeException('团购用户中心导航补齐失败');
        }
        $label = function_exists('lang_plugins') ? (string) lang_plugins($name) : '拼团优惠';
        if ($label === '' || $label === $name) {
            $label = '拼团优惠';
        }
        $menu = Db::name('menu')->where('type', 'home')->where('nav_id', $navId)->find();
        if (empty($menu)) {
            Db::name('menu')->insert([
                'type' => 'home', 'menu_type' => 'plugin', 'name' => $label,
                'language' => json_encode([], JSON_UNESCAPED_UNICODE), 'url' => $url,
                'icon' => 'shopping-cart', 'nav_id' => $navId, 'parent_id' => 0,
                'product_id' => '', 'order' => (int) Db::name('menu')->max('order') + 1,
                'create_time' => time(),
            ]);
        } else {
            Db::name('menu')->where('id', (int) $menu['id'])->update([
                'name' => $label, 'url' => $url, 'icon' => 'shopping-cart',
            ]);
        }
    }

    /**
     * 10.4.6 的 PluginModel::pluginUpgradeAuth() 不同步 auth_clientarea.php，
     * 因此新增只读分享动作需要由插件自身幂等补到官方权限表。
     */
    private function ensureClientareaSharePermission()
    {
        $plugin = 'CommunityGroupBuy';
        $ruleName = 'addon\\community_group_buy\\controller\\clientarea\\IndexController::share';
        $ruleId = (int) Db::name('clientarea_auth_rule')->where('name', $ruleName)->value('id');
        if ($ruleId <= 0) {
            $ruleId = (int) Db::name('clientarea_auth_rule')->insertGetId([
                'name' => $ruleName,
                'title' => 'clientarea_auth_community_group_buy_share',
                'module' => 'addon',
                'plugin' => $plugin,
            ]);
        } else {
            Db::name('clientarea_auth_rule')->where('id', $ruleId)->update([
                'title' => 'clientarea_auth_community_group_buy_share',
                'module' => 'addon',
                'plugin' => $plugin,
            ]);
        }
        $authId = (int) Db::name('clientarea_auth')->where('module', 'addon')->where('plugin', $plugin)
            ->where('title', 'clientarea_auth_community_group_buy_access')->value('id');
        if ($authId > 0 && Db::name('clientarea_auth_rule_link')
            ->where('clientarea_auth_rule_id', $ruleId)->where('clientarea_auth_id', $authId)->count() === 0) {
            Db::name('clientarea_auth_rule_link')->insert([
                'clientarea_auth_rule_id' => $ruleId,
                'clientarea_auth_id' => $authId,
            ]);
        }
    }

    private function ensureAdminNavigationLabel()
    {
        $name = 'nav_plugin_addon_community_group_buy';
        $navId = (int) Db::name('nav')->where('type', 'admin')->where('module', 'addon')
            ->where('plugin', 'CommunityGroupBuy')->where('name', $name)->value('id');
        if ($navId <= 0) {
            return;
        }
        $label = function_exists('lang_plugins') ? (string) lang_plugins($name) : '商品拼团';
        if ($label === '' || $label === $name) {
            $label = '商品拼团';
        }
        Db::name('menu')->where('type', 'admin')->where('nav_id', $navId)->update(['name' => $label]);
    }
}
