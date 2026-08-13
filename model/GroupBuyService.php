<?php

namespace addon\community_group_buy\model;

use addon\community_group_buy\lib\Money;
use app\common\model\ProductModel;
use think\facade\Db;

final class GroupBuyService
{
    const DEFAULT_RATES = [3 => 9000, 5 => 8000, 10 => 7000];
    const COST_RATE_BPS = 6500;
    const SAFETY_MARGIN_BPS = 500;
    const SUBSIDY_PRICE = '0.10';
    const ACTIVE_MEMBER_STATUSES = ['joined', 'issued', 'prepared', 'locked'];

    public static function dashboard()
    {
        self::ensureRuntimeHookOrder();
        self::expireDueRows();
        $settings = self::settings();
        return [
            'normal_products' => count($settings['normal_product_ids']),
            'subsidy_products' => count($settings['subsidy_product_ids']),
            'subsidy_enabled' => $settings['subsidy_enabled'],
            'gathering_groups' => (int) Db::name('addon_community_group_buy_group')->where('status', 'gathering')->count(),
            'success_groups' => (int) Db::name('addon_community_group_buy_group')->where('status', 'success')->count(),
            'paid_members' => (int) Db::name('addon_community_group_buy_member')->where('status', 'used')->count(),
            'subsidy_members' => (int) Db::name('addon_community_group_buy_member')->alias('m')
                ->join('addon_community_group_buy_group g', 'g.id=m.group_id')
                ->whereIn('g.mode', ['subsidy', 'legacy'])->where('m.reserved_loss', '>', 0)->count(),
            'reserved_loss' => self::reservedLoss(),
            'subsidy_budget' => $settings['subsidy_budget'],
            'dependency' => self::dependencyStatus(),
            'economics' => self::economics(),
        ];
    }

    public static function economics()
    {
        $rows = [];
        foreach (self::DEFAULT_RATES as $size => $rate) {
            $gross = $rate - self::COST_RATE_BPS;
            $rows[] = [
                'target_size' => $size,
                'price_rate_bps' => $rate,
                'display_discount' => self::rateLabel($rate),
                'gross_profit_bps_of_list' => $gross,
                'gross_margin_bps_of_revenue' => (int) floor($gross * 10000 / $rate),
            ];
        }
        return [
            'cost_rate_bps' => self::COST_RATE_BPS,
            'break_even_rate_bps' => self::COST_RATE_BPS,
            'safety_margin_bps' => self::SAFETY_MARGIN_BPS,
            'recommended_floor_bps' => self::COST_RATE_BPS + self::SAFETY_MARGIN_BPS,
            'plans' => $rows,
            'note' => '普通团固定保留5%售价安全垫；10人0.1元团是可关闭的首月获客补贴。',
        ];
    }

    public static function dependencyStatus()
    {
        $plugin = Db::name('plugin')->where('module', 'addon')->where('name', 'PromoCode')->find();
        return [
            'installed' => !empty($plugin),
            'enabled' => !empty($plugin) && (int) $plugin['status'] === 1,
            'version' => !empty($plugin) ? (string) $plugin['version'] : '',
        ];
    }

    public static function products($param = [])
    {
        $keywords = trim((string) ($param['keywords'] ?? ''));
        $settings = self::settings();
        $query = Db::name('product')->alias('p')
            ->leftJoin('product_group pg', 'pg.id=p.product_group_id')
            ->where('p.product_id', 0)
            ->field('p.id,p.name,p.pay_type,p.price,p.cycle,p.hidden,p.stock_control,p.qty,pg.name as group_name');
        if ($keywords !== '') {
            $query->where(function ($q) use ($keywords) {
                $q->where('p.name', 'like', '%' . $keywords . '%');
                if (ctype_digit($keywords)) {
                    $q->whereOr('p.id', (int) $keywords);
                }
            });
        }
        $list = $query->order('p.id', 'desc')->limit(500)->select()->toArray();
        foreach ($list as &$row) {
            $row = self::decorateProduct($row);
            $row['normal_eligible'] = in_array($row['id'], $settings['normal_product_ids'], true) ? 1 : 0;
            $row['subsidy_eligible'] = in_array($row['id'], $settings['subsidy_product_ids'], true) ? 1 : 0;
            $row['subsidy_unit_loss'] = self::unitLoss($row['price']);
            $row['subsidy_group_loss'] = Money::normalize(bcmul($row['subsidy_unit_loss'], '10', 2));
        }
        return ['list' => $list, 'count' => count($list)];
    }

    public static function settings()
    {
        return self::readSettings(false);
    }

    public static function saveSettings($param)
    {
        self::ensureRuntimeHookOrder();
        try {
            $data = self::validateSettings((array) $param);
            $result = Db::transaction(function () use ($data) {
                $plugin = self::pluginRow(true);
                if (empty($plugin)) {
                    throw new \RuntimeException('团购插件尚未安装');
                }
                // 先在同一事务中终止新设置已不允许的未成团，再校验剩余已承诺预算。
                $expired = self::expireDisallowedGatheringGroups($data);
                $reserved = self::reservedLoss();
                if (bccomp($data['subsidy_budget'], $reserved, 2) < 0) {
                    throw new \DomainException('补贴总预算不能低于已占用金额 ¥' . $reserved);
                }
                Db::name('plugin')->where('id', (int) $plugin['id'])->update([
                    'config' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'update_time' => time(),
                ]);
                return ['expired_groups' => $expired, 'reserved_loss' => self::reservedLoss()];
            });
            self::audit('settings_updated', 0, 0, 0, 0, 'admin', self::adminId(), [
                'normal_product_ids' => $data['normal_product_ids'],
                'subsidy_product_ids' => $data['subsidy_product_ids'],
                'subsidy_enabled' => $data['subsidy_enabled'],
                'subsidy_budget' => $data['subsidy_budget'],
                'expired_groups' => $result['expired_groups'],
            ]);
            return ['status' => 200, 'msg' => '白名单与风控设置已保存', 'data' => $result];
        } catch (\Throwable $e) {
            return ['status' => 400, 'msg' => self::publicExceptionMessage($e, '保存失败，请稍后重试')];
        }
    }

    public static function groups($param = [], $clientView = false)
    {
        self::expireDueRows();
        $query = Db::name('addon_community_group_buy_group')->alias('g')
            ->leftJoin('product p', 'p.id=g.product_id')
            ->field('g.*,p.name as product_name,p.hidden as product_hidden');
        if (!empty($param['status'])) {
            $query->where('g.status', (string) $param['status']);
        }
        if (!empty($param['mode']) && in_array((string) $param['mode'], ['normal', 'subsidy', 'legacy'], true)) {
            $query->where('g.mode', (string) $param['mode']);
        }
        if (!empty($param['product_id'])) {
            $query->where('g.product_id', (int) $param['product_id']);
        }
        if ($clientView) {
            $query->where('g.status', 'gathering');
            $settings = self::settings();
            $normalIds = $settings['normal_product_ids'];
            $subsidyIds = $settings['subsidy_enabled'] ? $settings['subsidy_product_ids'] : [];
            $query->where(function ($q) use ($normalIds, $subsidyIds) {
                $has = false;
                if (!empty($normalIds)) {
                    $q->where(function ($normal) use ($normalIds) {
                        $normal->where('g.mode', 'normal')->whereIn('g.product_id', $normalIds);
                    });
                    $has = true;
                }
                if (!empty($subsidyIds)) {
                    if ($has) {
                        $q->whereOr(function ($subsidy) use ($subsidyIds) {
                            $subsidy->where('g.mode', 'subsidy')->whereIn('g.product_id', $subsidyIds);
                        });
                    } else {
                        $q->where('g.mode', 'subsidy')->whereIn('g.product_id', $subsidyIds);
                    }
                }
                if (empty($normalIds) && empty($subsidyIds)) {
                    $q->where('g.id', 0);
                }
            })->where('p.hidden', 0);
        }
        $list = $query->order('g.id', 'desc')->limit(500)->select()->toArray();
        $clientId = $clientView ? self::clientId() : 0;
        $joinedGroupIds = [];
        if ($clientView && $clientId > 0 && !empty($list)) {
            $groupIds = array_map('intval', array_column($list, 'id'));
            foreach (Db::name('addon_community_group_buy_member')
                         ->where('client_id', $clientId)->whereIn('group_id', $groupIds)->column('group_id') as $groupId) {
                $joinedGroupIds[(int) $groupId] = true;
            }
        }
        foreach ($list as &$row) {
            $row = self::decorateGroup($row);
            if ($clientView) {
                $row['joined_by_me'] = isset($joinedGroupIds[(int) $row['id']]);
                $row = self::clientGroupView($row);
            }
        }
        return ['list' => $list, 'count' => count($list)];
    }

    public static function groupDetail($id, $clientView = false)
    {
        self::expireDueRows();
        $group = Db::name('addon_community_group_buy_group')->alias('g')
            ->leftJoin('product p', 'p.id=g.product_id')
            ->where('g.id', (int) $id)->field('g.*,p.name as product_name,p.hidden as product_hidden')->find();
        if (empty($group)) {
            return null;
        }
        if ($clientView) {
            $clientId = self::clientId();
            $isMember = $clientId > 0 && Db::name('addon_community_group_buy_member')
                ->where('group_id', (int) $id)->where('client_id', $clientId)->count() > 0;
            $settings = self::settings();
            $mode = (string) $group['mode'];
            $allowed = $mode === 'normal'
                ? in_array((int) $group['product_id'], $settings['normal_product_ids'], true)
                : ($mode === 'subsidy' && !empty($settings['subsidy_enabled'])
                    && in_array((int) $group['product_id'], $settings['subsidy_product_ids'], true));
            $publiclyVisible = (string) $group['status'] === 'gathering'
                && (int) ($group['product_hidden'] ?? 1) === 0 && $allowed;
            if (!$isMember && !$publiclyVisible) {
                return null;
            }
            $group['joined_by_me'] = $isMember;
        }
        $members = Db::name('addon_community_group_buy_member')->alias('m')
            ->leftJoin('client cl', 'cl.id=m.client_id')
            ->leftJoin('order o', 'o.id=m.order_id')
            ->where('m.group_id', (int) $id)
            ->field('m.id,m.client_id,m.role,m.join_order,m.status,m.order_id,m.final_amount,o.pay_time as paid_time,m.create_time,cl.username,cl.email,cl.phone')
            ->order('m.join_order', 'asc')->select()->toArray();
        if ($clientView) {
            foreach ($members as &$member) {
                $member['display_name'] = self::maskedClientName($member);
                $member = [
                    'role' => (string) $member['role'],
                    'join_order' => (int) $member['join_order'],
                    'status' => (string) $member['status'],
                    'create_time' => (int) $member['create_time'],
                    'display_name' => (string) $member['display_name'],
                ];
            }
        }
        $group = self::decorateGroup($group);
        if ($clientView) {
            $group = self::clientGroupView($group);
        }
        $group['members'] = $members;
        return $group;
    }

    /**
     * 返回可直接传播的上车链接和二维码矩阵。
     *
     * 分享链接只是公开拼团广场中的定位参数，不是参团凭证；真正参团仍需登录，
     * 并重新经过白名单、人数、账号唯一性和补贴风控校验。
     */
    public static function shareInfo($id, $origin)
    {
        self::expireDueRows();
        $group = Db::name('addon_community_group_buy_group')->alias('g')
            ->leftJoin('product p', 'p.id=g.product_id')
            ->where('g.id', (int) $id)
            ->field('g.*,p.name as product_name,p.hidden as product_hidden')->find();
        if (empty($group)) {
            throw new \DomainException('该拼团不存在');
        }
        if ((string) $group['status'] !== 'gathering' || (int) $group['expire_time'] <= time()) {
            throw new \DomainException('该拼团已成团或已结束，不能继续邀请');
        }
        if ((int) $group['product_hidden'] === 1) {
            throw new \DomainException('该拼团商品已隐藏，不能继续邀请');
        }
        $settings = self::settings();
        $mode = (string) $group['mode'];
        $allowed = $mode === 'normal'
            ? in_array((int) $group['product_id'], $settings['normal_product_ids'], true)
            : ($mode === 'subsidy' && !empty($settings['subsidy_enabled'])
                && in_array((int) $group['product_id'], $settings['subsidy_product_ids'], true));
        if (!$allowed) {
            throw new \DomainException('该拼团当前不在可参与白名单中');
        }
        $plugin = self::pluginRow(false);
        if (empty($plugin) || (int) $plugin['id'] <= 0) {
            throw new \RuntimeException('团购插件尚未安装');
        }
        $origin = self::trustedShareOrigin($origin);
        $group = self::decorateGroup($group);
        $shareUrl = $origin . '/plugin/' . (int) $plugin['id'] . '/index.htm?join_group=' . (int) $group['id'];
        return [
            'group' => [
                'id' => $group['id'], 'group_no' => (string) $group['group_no'],
                'product_id' => $group['product_id'], 'product_name' => (string) $group['product_name'],
                'mode' => (string) $group['mode'], 'mode_label' => $group['mode_label'],
                'display_price' => $group['display_price'], 'target_size' => $group['target_size'],
                'joined_count' => $group['joined_count'], 'remaining' => $group['remaining'],
                'expire_time' => $group['expire_time'],
            ],
            'share_url' => $shareUrl,
            'qr_matrix' => self::qrMatrix($shareUrl),
        ];
    }

    /**
     * 业务校验信息可直接反馈；未知异常只返回稳定错误编号，避免泄露 SQL、路径或框架细节。
     */
    public static function publicExceptionMessage(\Throwable $exception, $fallback)
    {
        if ($exception instanceof \DomainException) {
            return $exception->getMessage();
        }
        $errorId = substr(hash('sha256', get_class($exception) . "\0" . $exception->getMessage()), 0, 12);
        self::audit('internal_error', 0, 0, self::clientId(), 0, 'system', 0, [
            'error_id' => $errorId,
            'exception_class' => get_class($exception),
        ]);
        return rtrim((string) $fallback, '。') . '（错误编号 ' . $errorId . '）';
    }

    public static function overview()
    {
        self::ensureRuntimeHookOrder();
        self::expireDueRows();
        $clientId = self::clientId();
        $settings = self::settings();
        $normalProducts = self::eligibleProducts($settings['normal_product_ids']);
        $subsidyProducts = $settings['subsidy_enabled'] ? self::eligibleProducts($settings['subsidy_product_ids']) : [];
        foreach ($subsidyProducts as &$product) {
            $product['subsidy_unit_loss'] = self::unitLoss($product['price']);
        }
        $groups = self::groups(['status' => 'gathering'], true);
        $memberships = Db::name('addon_community_group_buy_member')->alias('m')
            ->leftJoin('addon_community_group_buy_group g', 'g.id=m.group_id')
            ->leftJoin('product p', 'p.id=g.product_id')
            ->leftJoin('addon_promo_code pc', 'pc.id=m.promo_code_id')
            ->leftJoin('addon_promo_code_product pcp', 'pcp.addon_promo_code_id=m.promo_code_id')
            ->leftJoin('product lp', 'lp.id=pcp.product_id')
            ->where('m.client_id', $clientId)
            ->field('m.*,pc.code,g.group_no,g.product_id,g.mode,g.target_size,g.joined_count,g.status as group_status,g.price_rate_bps,g.expire_time as group_expire_time,g.claim_expire_time,p.name as product_name,pcp.product_id as legacy_product_id,lp.name as legacy_product_name')
            ->order('m.id', 'desc')->limit(100)->select()->toArray();
        foreach ($memberships as &$membership) {
            if ((string) $membership['mode'] === 'legacy' && (int) $membership['legacy_product_id'] > 0) {
                $membership['product_id'] = (int) $membership['legacy_product_id'];
                $membership['product_name'] = (string) $membership['legacy_product_name'];
            }
            $membership['buy_url'] = '/cart/goods.htm?id=' . (int) $membership['product_id'];
            $membership['display_discount'] = self::rateLabel((int) $membership['price_rate_bps']);
            $membership['mode_label'] = (string) $membership['mode'] === 'legacy'
                ? (strpos(strtoupper((string) $membership['code']), 'GBS-') === 0
                    ? '历史10人团·0.1元基础款' : '历史普通团·' . $membership['display_discount'])
                : self::modeLabel((string) $membership['mode'], (int) $membership['target_size']);
            $membership['code'] = in_array((string) $membership['status'], ['issued', 'prepared', 'locked'], true)
                ? (string) $membership['code'] : '';
            $membership = self::clientMembershipView($membership);
        }
        return [
            'products' => ['normal' => $normalProducts, 'subsidy' => $subsidyProducts],
            'open_groups' => $groups['list'],
            'memberships' => $memberships,
            'rules' => [
                'rates' => self::DEFAULT_RATES,
                'sizes' => [3, 5, 10],
                'subsidy_enabled' => $settings['subsidy_enabled'],
                'subsidy_price' => self::SUBSIDY_PRICE,
                'renewal_notice' => '团购只优惠首次开通，第二个月起按商品当时的 V10 正常续费价执行。',
                'subsidy_notice' => '10人0.1元团中每个成员的首次开通价都是0.1元，仅限后台白名单基础款。',
            ],
        ];
    }

    public static function createGroup($productId, $targetSize, $mode, $requestId = '')
    {
        self::ensureRuntimeHookOrder();
        $clientId = self::clientId();
        if ($clientId <= 0) {
            return ['status' => 401, 'msg' => '请先登录'];
        }
        self::expireDueRows();
        try {
            $data = Db::transaction(function () use ($productId, $targetSize, $mode, $requestId, $clientId) {
                $plugin = self::pluginRow(true);
                if (empty($plugin)) {
                    throw new \RuntimeException('团购插件尚未安装');
                }
                $settings = self::decodeSettings($plugin['config'] ?? '');
                self::lockClient($clientId);
                $mode = self::validateMode((string) $mode);
                $targetSize = (int) $targetSize;
                if ($mode === 'subsidy') {
                    $targetSize = 10;
                } elseif (!isset(self::DEFAULT_RATES[$targetSize])) {
                    throw new \DomainException('普通拼团人数只能选3人、5人或10人');
                }
                $product = self::assertEligibleProduct((int) $productId, $mode, $settings);
                $requestKey = self::requestKey($clientId, $requestId);
                if ($requestKey !== '') {
                    $existing = Db::name('addon_community_group_buy_group')->where('request_key', $requestKey)->find();
                    if (!empty($existing)) {
                        if ((int) $existing['leader_client_id'] !== $clientId) {
                            throw new \DomainException('请求标识已被使用，请刷新后重试');
                        }
                        return self::groupResult($existing);
                    }
                }
                self::assertNoActiveMembership($clientId, (int) $product['id'], $mode);
                if ($mode === 'subsidy') {
                    self::assertSubsidyLifetime($clientId, $settings['subsidy_lifetime_limit']);
                }
                $now = time();
                $rate = $mode === 'subsidy' ? 0 : self::DEFAULT_RATES[$targetSize];
                $reference = $mode === 'subsidy' ? Money::normalize($product['price']) : '0.00';
                if ($mode === 'subsidy' && !Money::isPositive($reference)) {
                    throw new \DomainException('0.1元基础款必须有大于0的固定基础售价');
                }
                $unitLoss = $mode === 'subsidy' ? self::unitLoss($reference) : '0.00';
                $reserve = $mode === 'subsidy' ? Money::normalize(bcmul($unitLoss, '10', 2)) : '0.00';
                if ($mode === 'subsidy') {
                    $after = Money::add(self::reservedLoss(), $reserve);
                    if (bccomp($after, $settings['subsidy_budget'], 2) > 0) {
                        throw new \DomainException('0.1元团补贴预算不足，请选择普通拼团');
                    }
                }
                $groupId = (int) Db::name('addon_community_group_buy_group')->insertGetId([
                    'group_no' => self::groupNumber(), 'request_key' => $requestKey !== '' ? $requestKey : null,
                    'leader_client_id' => $clientId, 'product_id' => (int) $product['id'], 'mode' => $mode,
                    'target_size' => $targetSize, 'joined_count' => 1, 'price_rate_bps' => $rate,
                    'cost_rate_bps' => self::COST_RATE_BPS, 'safety_margin_bps' => self::SAFETY_MARGIN_BPS,
                    'subsidy_price' => self::SUBSIDY_PRICE, 'subsidy_reference_price' => $reference,
                    'subsidy_unit_loss' => $unitLoss, 'reserved_loss' => $reserve,
                    'status' => 'gathering', 'expire_time' => $now + ($settings['group_expire_minutes'] * 60),
                    'success_time' => 0, 'claim_expire_time' => 0, 'create_time' => $now, 'update_time' => $now,
                ]);
                $memberId = (int) Db::name('addon_community_group_buy_member')->insertGetId([
                    'group_id' => $groupId, 'client_id' => $clientId, 'role' => 'leader', 'join_order' => 1,
                    'status' => 'joined', 'reserved_loss' => '0.00', 'create_time' => $now, 'update_time' => $now,
                ]);
                self::audit('group_created', $groupId, $memberId, $clientId, 0, 'client', $clientId, [
                    'product_id' => (int) $product['id'], 'mode' => $mode, 'target_size' => $targetSize,
                    'reserved_loss' => $reserve,
                ]);
                return ['group_id' => $groupId, 'status' => 'gathering', 'joined_count' => 1, 'target_size' => $targetSize];
            });
            return ['status' => 200, 'msg' => '已发起拼团，可以邀请好友参加', 'data' => $data];
        } catch (\Throwable $e) {
            return ['status' => 400, 'msg' => self::publicExceptionMessage($e, '发起拼团失败，请稍后重试')];
        }
    }

    public static function joinGroup($groupId)
    {
        self::ensureRuntimeHookOrder();
        $clientId = self::clientId();
        if ($clientId <= 0) {
            return ['status' => 401, 'msg' => '请先登录'];
        }
        self::expireDueRows();
        try {
            $data = Db::transaction(function () use ($groupId, $clientId) {
                $candidate = Db::name('addon_community_group_buy_group')->where('id', (int) $groupId)->find();
                if (empty($candidate)) {
                    throw new \DomainException('该拼团不存在');
                }
                $plugin = self::pluginRow(true);
                $settings = self::decodeSettings($plugin['config'] ?? '');
                self::lockClient($clientId);
                $group = Db::name('addon_community_group_buy_group')->where('id', (int) $groupId)->lock(true)->find();
                if (empty($group)) {
                    throw new \DomainException('该拼团不存在');
                }
                $existing = Db::name('addon_community_group_buy_member')
                    ->where('group_id', (int) $group['id'])->where('client_id', $clientId)->find();
                if (!empty($existing)) {
                    return self::groupResult($group);
                }
                if ((string) $group['status'] !== 'gathering' || (int) $group['expire_time'] <= time()) {
                    throw new \DomainException('该拼团已成团或已过期');
                }
                self::assertEligibleProduct((int) $group['product_id'], (string) $group['mode'], $settings);
                self::assertNoActiveMembership($clientId, (int) $group['product_id'], (string) $group['mode']);
                if ((string) $group['mode'] === 'subsidy') {
                    self::assertSubsidyLifetime($clientId, $settings['subsidy_lifetime_limit']);
                }
                $currentCount = (int) Db::name('addon_community_group_buy_member')
                    ->where('group_id', (int) $group['id'])->lock(true)->count();
                if ($currentCount >= (int) $group['target_size']) {
                    throw new \DomainException('该拼团已满员');
                }
                $joinOrder = $currentCount + 1;
                $now = time();
                $memberId = (int) Db::name('addon_community_group_buy_member')->insertGetId([
                    'group_id' => (int) $group['id'], 'client_id' => $clientId, 'role' => 'member',
                    'join_order' => $joinOrder, 'status' => 'joined', 'reserved_loss' => '0.00',
                    'create_time' => $now, 'update_time' => $now,
                ]);
                Db::name('addon_community_group_buy_group')->where('id', (int) $group['id'])->update([
                    'joined_count' => $joinOrder, 'update_time' => $now,
                ]);
                self::audit('member_joined', (int) $group['id'], $memberId, $clientId, 0, 'client', $clientId);
                $status = 'gathering';
                if ($joinOrder === (int) $group['target_size']) {
                    $group['joined_count'] = $joinOrder;
                    self::completeGroup($group, $settings, $now);
                    $status = 'success';
                }
                return ['group_id' => (int) $group['id'], 'status' => $status,
                    'joined_count' => $joinOrder, 'target_size' => (int) $group['target_size']];
            });
            return ['status' => 200, 'msg' => $data['status'] === 'success'
                ? '拼团成功，首次开通权益已发放' : '参团成功', 'data' => $data];
        } catch (\Throwable $e) {
            return ['status' => 400, 'msg' => self::publicExceptionMessage($e, '参团失败，请稍后重试')];
        }
    }

    public static function beforeOrderCreate($param)
    {
        $codes = self::codesFromOrderContext($param);
        if (empty($codes)) {
            return true;
        }
        if (count($codes) !== 1) {
            return ['status' => 400, 'msg' => '一个订单只能使用一个团购权益码'];
        }
        try {
            self::prepareOrder($codes[0], $param);
            return true;
        } catch (\Throwable $e) {
            return ['status' => 400, 'msg' => self::publicExceptionMessage($e, '团购权益校验失败，请稍后重试')];
        }
    }

    public static function afterOrderCreate($orderId, $customfield)
    {
        $orderId = (int) $orderId;
        if ($orderId <= 0) {
            return true;
        }
        $codes = [];
        self::collectGroupCodes($customfield, $codes);
        $codes = array_values(array_unique($codes));
        if (empty($codes)) {
            return true;
        }
        if (count($codes) !== 1) {
            throw new \RuntimeException('一个订单只能使用一个团购权益码');
        }
        $code = $codes[0];
        Db::transaction(function () use ($orderId, $code) {
            $member = self::memberByCode($code, true);
            if (empty($member)) {
                throw new \RuntimeException('团购权益码不存在');
            }
            $group = Db::name('addon_community_group_buy_group')->where('id', (int) $member['group_id'])->lock(true)->find();
            $order = Db::name('order')->where('id', $orderId)->lock(true)->find();
            if (empty($group) || empty($order) || (string) $order['type'] !== 'new') {
                throw new \RuntimeException('团购权益只能用于新购订单');
            }
            if ((int) $order['client_id'] !== (int) $member['client_id']) {
                throw new \RuntimeException('团购权益码与当前用户不匹配');
            }
            if (!in_array((string) $member['status'], ['prepared', 'issued'], true)) {
                if ((int) $member['order_id'] === $orderId) {
                    return;
                }
                throw new \RuntimeException('团购权益已被使用或已失效');
            }
            $mode = self::effectiveMode($group, $code);
            $productId = self::effectiveProductId($group, (int) $member['promo_code_id']);
            $items = Db::name('order_item')->where('order_id', $orderId)
                ->where('type', 'host')->where('product_id', $productId)->select()->toArray();
            if (count($items) !== 1) {
                throw new \RuntimeException('团购订单必须且只能包含一件对应商品');
            }
            $item = $items[0];
            $desired = Money::normalize($member['final_amount']);
            if ((string) $member['status'] !== 'prepared') {
                $desired = $mode === 'subsidy' ? self::SUBSIDY_PRICE
                    : Money::mulBps($item['amount'], (int) $group['price_rate_bps']);
            }
            $currentOrderAmount = Money::normalize($order['amount']);
            $reconciliation = Money::sub($desired, $currentOrderAmount);
            if (bccomp($reconciliation, '0.00', 2) !== 0
                && Db::name('order_item')->where('order_id', $orderId)
                    ->where('type', 'addon_community_group_buy')->where('rel_id', (int) $member['id'])->count() === 0) {
                Db::name('order_item')->insert([
                    'order_id' => $orderId, 'client_id' => (int) $member['client_id'],
                    'host_id' => (int) $item['host_id'], 'product_id' => $productId,
                    'type' => 'addon_community_group_buy', 'rel_id' => (int) $member['id'],
                    'amount' => $reconciliation, 'description' => '团购目标价对账（只调整首付）',
                    'notes' => '', 'create_time' => time(),
                ]);
            }
            Db::name('order')->where('id', $orderId)->update([
                'amount' => $desired, 'amount_unpaid' => $desired,
                'status' => Money::isPositive($desired) ? 'Unpaid' : 'Paid',
                'pay_time' => Money::isPositive($desired) ? 0 : time(), 'update_time' => time(),
            ]);
            Db::name('host')->where('id', (int) $item['host_id'])->update([
                'first_payment_amount' => $desired,
                // renew_amount/base_renew_amount 故意不写，保持 V10 原生续费价。
                'update_time' => time(),
            ]);
            Db::name('addon_community_group_buy_member')->where('id', (int) $member['id'])->update([
                'status' => 'locked', 'order_id' => $orderId, 'final_amount' => $desired, 'update_time' => time(),
            ]);
            self::audit('entitlement_locked', (int) $group['id'], (int) $member['id'],
                (int) $member['client_id'], $orderId, 'client', (int) $member['client_id'], [
                    'mode' => $mode, 'code_hint' => substr($code, -6),
                    'before_reconciliation' => $currentOrderAmount, 'reconciliation' => $reconciliation,
                    'final_amount' => $desired,
                ]);
        });
        return true;
    }

    public static function orderPaid($orderId)
    {
        $orderId = (int) $orderId;
        if ($orderId <= 0) {
            return true;
        }
        Db::transaction(function () use ($orderId) {
            $member = Db::name('addon_community_group_buy_member')->where('order_id', $orderId)->lock(true)->find();
            if (empty($member) || in_array((string) $member['status'], ['used', 'refunded'], true)) {
                return;
            }
            $order = Db::name('order')->where('id', $orderId)->find();
            if (empty($order) || (string) $order['status'] !== 'Paid') {
                return;
            }
            $promoLog = (int) $member['promo_code_id'] > 0 ? Db::name('addon_promo_code_log')
                ->where('addon_promo_code_id', (int) $member['promo_code_id'])->where('order_id', $orderId)->find() : null;
            $extra = [
                'order_amount' => Money::normalize($order['amount']),
                'expected_final_amount' => Money::normalize($member['final_amount']),
                'promo_log_found' => !empty($promoLog) ? 1 : 0,
            ];
            if (empty($promoLog)) {
                self::audit('paid_without_promo_log', (int) $member['group_id'], (int) $member['id'],
                    (int) $member['client_id'], $orderId, 'system', 0, $extra);
            }
            Db::name('addon_community_group_buy_member')->where('id', (int) $member['id'])
                ->update(['status' => 'used', 'update_time' => time()]);
            self::audit('entitlement_paid', (int) $member['group_id'], (int) $member['id'],
                (int) $member['client_id'], $orderId, 'system', 0, $extra);
        });
        return true;
    }

    public static function afterOrderRefund($orderId)
    {
        $orderId = (int) $orderId;
        $member = Db::name('addon_community_group_buy_member')->where('order_id', $orderId)->find();
        if (empty($member)) {
            return true;
        }
        $refund = Money::normalize(Db::name('order')->where('id', $orderId)->value('refund_amount'));
        Db::name('addon_community_group_buy_member')->where('id', (int) $member['id'])->update([
            'status' => Money::isPositive($refund) ? 'refunded' : (string) $member['status'], 'update_time' => time(),
        ]);
        self::audit('order_refunded', (int) $member['group_id'], (int) $member['id'],
            (int) $member['client_id'], $orderId, 'system', 0, ['refund_amount' => $refund]);
        return true;
    }

    public static function releaseOrder($orderId, $reason)
    {
        $orderId = (int) $orderId;
        if ($orderId <= 0) {
            return true;
        }
        Db::transaction(function () use ($orderId, $reason) {
            $member = Db::name('addon_community_group_buy_member')->where('order_id', $orderId)->lock(true)->find();
            if (empty($member) || (string) $member['status'] !== 'locked') {
                return;
            }
            $order = Db::name('order')->where('id', $orderId)->find();
            if (!empty($order) && (string) $order['status'] === 'Paid') {
                return;
            }
            $promoId = (int) $member['promo_code_id'];
            if ($promoId > 0) {
                Db::name('addon_promo_code_log')->where('addon_promo_code_id', $promoId)->where('order_id', $orderId)->delete();
                $used = (int) Db::name('addon_promo_code_log')->where('addon_promo_code_id', $promoId)->count();
                Db::name('addon_promo_code')->where('id', $promoId)->update([
                    'used' => $used, 'status' => (int) $member['expire_time'] > time() ? 1 : 0, 'update_time' => time(),
                ]);
            }
            $nextStatus = (int) $member['expire_time'] > time() ? 'issued' : 'expired';
            if ($nextStatus === 'expired') {
                self::releaseMemberReserve($member);
            }
            Db::name('addon_community_group_buy_member')->where('id', (int) $member['id'])->update([
                'status' => $nextStatus, 'order_id' => 0, 'prepare_time' => 0,
                'final_amount' => '0.00', 'reserved_loss' => $nextStatus === 'expired' ? '0.00' : $member['reserved_loss'],
                'update_time' => time(),
            ]);
            self::audit('entitlement_released', (int) $member['group_id'], (int) $member['id'],
                (int) $member['client_id'], $orderId, 'system', 0,
                ['reason' => (string) $reason, 'next_status' => $nextStatus]);
        });
        return true;
    }

    public static function expireDueRows()
    {
        $now = time();
        $groups = Db::name('addon_community_group_buy_group')
            ->where('status', 'gathering')->where('expire_time', '<=', $now)->column('id');
        foreach (array_map('intval', $groups) as $groupId) {
            Db::transaction(function () use ($groupId, $now) {
                $group = Db::name('addon_community_group_buy_group')->where('id', $groupId)->lock(true)->find();
                if (empty($group) || (string) $group['status'] !== 'gathering' || (int) $group['expire_time'] > $now) {
                    return;
                }
                Db::name('addon_community_group_buy_group')->where('id', $groupId)->update([
                    'status' => 'expired', 'reserved_loss' => '0.00', 'update_time' => $now,
                ]);
                Db::name('addon_community_group_buy_member')->where('group_id', $groupId)->where('status', 'joined')
                    ->update(['status' => 'expired', 'update_time' => $now]);
            });
        }
        $members = Db::name('addon_community_group_buy_member')
            ->whereIn('status', ['issued', 'prepared'])->where('expire_time', '<=', $now)->select()->toArray();
        foreach ($members as $candidate) {
            Db::transaction(function () use ($candidate, $now) {
                $member = Db::name('addon_community_group_buy_member')
                    ->where('id', (int) $candidate['id'])->lock(true)->find();
                if (empty($member) || !in_array((string) $member['status'], ['issued', 'prepared'], true)
                    || (int) $member['expire_time'] > $now) {
                    return;
                }
                self::disableMemberCode($member);
                self::releaseMemberReserve($member);
                Db::name('addon_community_group_buy_member')->where('id', (int) $member['id'])
                    ->update(['status' => 'expired', 'reserved_loss' => '0.00', 'update_time' => $now]);
            });
        }
        Db::name('addon_community_group_buy_member')->where('status', 'prepared')
            ->where('prepare_time', '<=', $now - 300)->where('expire_time', '>', $now)
            ->update(['status' => 'issued', 'prepare_time' => 0, 'update_time' => $now]);
        return ['expired_groups' => count($groups), 'expired_members' => count($members)];
    }

    private static function completeGroup($group, $settings, $now)
    {
        $groupId = (int) $group['id'];
        $claimExpire = $now + ($settings['claim_expire_minutes'] * 60);
        $members = Db::name('addon_community_group_buy_member')->where('group_id', $groupId)
            ->order('join_order', 'asc')->lock(true)->select()->toArray();
        if (count($members) !== (int) $group['target_size']) {
            throw new \RuntimeException('成团人数校验失败，请重试');
        }
        Db::name('addon_community_group_buy_group')->where('id', $groupId)->update([
            'status' => 'success', 'joined_count' => count($members), 'success_time' => $now,
            'claim_expire_time' => $claimExpire, 'update_time' => $now,
        ]);
        foreach ($members as $member) {
            $mode = (string) $group['mode'];
            $code = self::newCode($mode === 'subsidy' ? 'GBS' : 'GBN');
            $promoId = self::createNativePromoCode($code, (int) $group['product_id'], $mode, $group,
                (int) $member['client_id'], $now, $claimExpire);
            $memberReserve = $mode === 'subsidy' ? Money::normalize($group['subsidy_unit_loss']) : '0.00';
            Db::name('addon_community_group_buy_member')->where('id', (int) $member['id'])->update([
                'status' => 'issued', 'promo_code_id' => $promoId, 'reserved_loss' => $memberReserve,
                'expire_time' => $claimExpire, 'update_time' => $now,
            ]);
            self::audit('entitlement_issued', $groupId, (int) $member['id'], (int) $member['client_id'],
                0, 'system', 0, ['mode' => $mode, 'reserved_loss' => $memberReserve]);
        }
        self::audit('group_completed', $groupId, 0, 0, 0, 'system', 0, [
            'members' => count($members), 'mode' => (string) $group['mode'],
            'claim_expire_time' => $claimExpire, 'reserved_loss' => Money::normalize($group['reserved_loss']),
        ]);
    }

    private static function createNativePromoCode($code, $productId, $mode, $group, $clientId, $startTime, $endTime)
    {
        if ($mode === 'subsidy') {
            $existingDiscount = self::clientDiscount($clientId, $productId, $group['subsidy_reference_price']);
            $type = 'replace_price';
            $value = Money::add(self::SUBSIDY_PRICE, $existingDiscount);
        } else {
            $existingPerHundred = self::clientDiscount($clientId, $productId, '100.00');
            $targetReduction = bcdiv((string) (10000 - (int) $group['price_rate_bps']), '100', 2);
            $type = 'percent';
            $value = Money::max(Money::sub($targetReduction, $existingPerHundred), '0.00');
        }
        $promoId = (int) Db::name('addon_promo_code')->insertGetId([
            'code' => $code, 'type' => $type, 'value' => $value, 'status' => 1,
            'client_type' => 'all', 'start_time' => $startTime, 'end_time' => $endTime,
            'max_times' => 1, 'used' => 0, 'single_user_once' => 1,
            'upgrade' => 0, 'host_upgrade' => 0, 'renew' => 0, 'loop' => 0,
            'cycle_limit' => 0, 'cycle' => '',
            'notes' => 'CommunityGroupBuy group#' . (int) $group['id'] . ' client#' . $clientId . ' mode=' . $mode,
            'delete_time' => 0, 'create_time' => $startTime, 'update_time' => $startTime,
        ]);
        Db::name('addon_promo_code_product')->insert([
            'addon_promo_code_id' => $promoId, 'product_id' => $productId,
        ]);
        return $promoId;
    }

    private static function prepareOrder($code, $hookParam)
    {
        Db::transaction(function () use ($code, $hookParam) {
            $member = self::memberByCode($code, true);
            if (empty($member)) {
                throw new \DomainException('团购权益码不存在');
            }
            $group = Db::name('addon_community_group_buy_group')->where('id', (int) $member['group_id'])->lock(true)->find();
            if (empty($group)) {
                throw new \DomainException('团购记录不存在');
            }
            $clientId = (int) ($hookParam['client_id'] ?? self::clientId());
            if ($clientId <= 0 || $clientId !== (int) $member['client_id']) {
                throw new \DomainException('该团购权益码只能由获得者本人使用');
            }
            if ((int) $member['expire_time'] <= time()) {
                throw new \DomainException('团购权益码已过期');
            }
            if ((string) $member['status'] === 'prepared' && (int) $member['prepare_time'] > time() - 300) {
                throw new \DomainException('该权益码正在另一个下单请求中，请稍后重试');
            }
            if (!in_array((string) $member['status'], ['issued', 'prepared'], true)) {
                throw new \DomainException('团购权益码已被使用或已失效');
            }
            $mode = self::effectiveMode($group, $code);
            $expectedProductId = self::effectiveProductId($group, (int) $member['promo_code_id']);
            $orderInput = self::singleOrderInput($hookParam, $expectedProductId);
            if (empty($orderInput)) {
                throw new \DomainException('团购权益码必须单独购买对应商品，数量为1');
            }
            $config = (array) ($orderInput['config_options'] ?? []);
            unset($config['promo_code']);
            if (!empty($config['event_promotion'])) {
                throw new \DomainException('团购权益不能与其他活动促销同时使用');
            }
            $priceResult = (new ProductModel())->productCalculatePrice([
                'product_id' => $expectedProductId, 'qty' => 1, 'config_options' => $config,
            ]);
            if ((int) ($priceResult['status'] ?? 0) !== 200) {
                throw new \DomainException((string) ($priceResult['msg'] ?? '无法计算商品价格'));
            }
            $priceData = (array) ($priceResult['data'] ?? []);
            $original = Money::normalize($priceData['price'] ?? 0);
            $current = Money::normalize($priceData['price_total'] ?? $original);
            if (!Money::isPositive($original)) {
                throw new \DomainException('免费商品无需使用团购权益');
            }
            if ($mode === 'subsidy' && bccomp($original, Money::normalize($group['subsidy_reference_price']), 2) > 0) {
                throw new \DomainException('0.1元权益只支持白名单商品的基础配置，请取消高配选项');
            }
            $target = $mode === 'subsidy' ? self::SUBSIDY_PRICE
                : Money::mulBps($original, (int) $group['price_rate_bps']);
            // 0.1元团的口径是每人精确首付0.1元；普通团则保留客户已有的更低价。
            $final = $mode === 'subsidy' ? self::SUBSIDY_PRICE : Money::min($current, $target);
            $discount = Money::max(Money::sub($current, $final), '0.00');
            $promo = Db::name('addon_promo_code')->where('id', (int) $member['promo_code_id'])->lock(true)->find();
            if (empty($promo) || (int) $promo['status'] !== 1 || (int) $promo['used'] >= 1) {
                throw new \DomainException('团购权益码不可用');
            }
            Db::name('addon_promo_code')->where('id', (int) $promo['id'])->update([
                'type' => 'fixed_amount', 'value' => $discount, 'update_time' => time(),
            ]);
            Db::name('addon_community_group_buy_member')->where('id', (int) $member['id'])->update([
                'status' => 'prepared', 'final_amount' => $final, 'prepare_time' => time(), 'update_time' => time(),
            ]);
            self::audit('entitlement_prepared', (int) $group['id'], (int) $member['id'], $clientId,
                0, 'client', $clientId, ['mode' => $mode, 'original' => $original,
                    'current' => $current, 'discount' => $discount, 'final' => $final]);
        });
    }

    private static function validateSettings($param)
    {
        $normalIds = self::normalizeIds($param['normal_product_ids'] ?? []);
        $subsidyIds = self::normalizeIds($param['subsidy_product_ids'] ?? []);
        foreach ($normalIds as $id) {
            self::assertProduct($id, '普通拼团白名单商品');
        }
        foreach ($subsidyIds as $id) {
            $product = self::assertProduct($id, '0.1元基础款白名单商品');
            if (!Money::isPositive($product['price'])) {
                throw new \DomainException('0.1元基础款商品 #' . $id . ' 必须设置大于0的基础售价');
            }
        }
        $enabled = !empty($param['subsidy_enabled']) ? 1 : 0;
        $budget = Money::normalize($param['subsidy_budget'] ?? 0);
        $lifetime = max(1, min(100, (int) ($param['subsidy_lifetime_limit'] ?? 1)));
        if ($enabled && empty($subsidyIds)) {
            throw new \DomainException('启用10人0.1元团前，至少选择一个基础款商品');
        }
        if ($enabled && !Money::isPositive($budget)) {
            throw new \DomainException('启用10人0.1元团时，补贴总预算必须大于0');
        }
        return [
            'normal_product_ids' => $normalIds, 'subsidy_product_ids' => $subsidyIds,
            'subsidy_enabled' => $enabled, 'subsidy_budget' => $budget,
            'subsidy_lifetime_limit' => $lifetime,
            'group_expire_minutes' => max(10, min(43200, (int) ($param['group_expire_minutes'] ?? 1440))),
            'claim_expire_minutes' => max(10, min(43200, (int) ($param['claim_expire_minutes'] ?? 1440))),
            'cost_rate_bps' => self::COST_RATE_BPS, 'safety_margin_bps' => self::SAFETY_MARGIN_BPS,
            'rates' => self::DEFAULT_RATES, 'subsidy_price' => self::SUBSIDY_PRICE,
        ];
    }

    private static function readSettings($lock)
    {
        $plugin = self::pluginRow($lock);
        return self::decodeSettings($plugin['config'] ?? '');
    }

    private static function decodeSettings($encoded)
    {
        $defaults = [
            'normal_product_ids' => [], 'subsidy_product_ids' => [], 'subsidy_enabled' => 0,
            'subsidy_budget' => '0.00', 'subsidy_lifetime_limit' => 1,
            'group_expire_minutes' => 1440, 'claim_expire_minutes' => 1440,
            'cost_rate_bps' => self::COST_RATE_BPS, 'safety_margin_bps' => self::SAFETY_MARGIN_BPS,
            'rates' => self::DEFAULT_RATES, 'subsidy_price' => self::SUBSIDY_PRICE,
        ];
        $data = is_array($encoded) ? $encoded : json_decode((string) $encoded, true);
        $data = is_array($data) ? array_merge($defaults, $data) : $defaults;
        $data['normal_product_ids'] = self::normalizeIds($data['normal_product_ids']);
        $data['subsidy_product_ids'] = self::normalizeIds($data['subsidy_product_ids']);
        $data['subsidy_enabled'] = !empty($data['subsidy_enabled']) ? 1 : 0;
        $data['subsidy_budget'] = Money::normalize($data['subsidy_budget']);
        $data['subsidy_lifetime_limit'] = max(1, min(100, (int) $data['subsidy_lifetime_limit']));
        $data['group_expire_minutes'] = max(10, min(43200, (int) $data['group_expire_minutes']));
        $data['claim_expire_minutes'] = max(10, min(43200, (int) $data['claim_expire_minutes']));
        $data['cost_rate_bps'] = self::COST_RATE_BPS;
        $data['safety_margin_bps'] = self::SAFETY_MARGIN_BPS;
        $data['rates'] = self::DEFAULT_RATES;
        $data['subsidy_price'] = self::SUBSIDY_PRICE;
        return $data;
    }

    private static function pluginRow($lock = false)
    {
        $query = Db::name('plugin')->where('module', 'addon')->where('name', 'CommunityGroupBuy');
        if ($lock) {
            $query->lock(true);
        }
        return $query->find();
    }

    private static function eligibleProducts($ids)
    {
        if (empty($ids)) {
            return [];
        }
        $list = Db::name('product')->whereIn('id', $ids)->where('product_id', 0)
            ->where('hidden', 0)->where('pay_type', '<>', 'free')
            ->field('id,name,price,cycle,pay_type,stock_control,qty')->order('id', 'desc')->select()->toArray();
        foreach ($list as &$row) {
            $row = self::decorateProduct($row);
        }
        return $list;
    }

    private static function decorateProduct($row)
    {
        $row['id'] = (int) $row['id'];
        $row['hidden'] = (int) ($row['hidden'] ?? 0);
        $row['price'] = Money::normalize($row['price'] ?? 0);
        return $row;
    }

    private static function decorateGroup($row)
    {
        foreach (['id', 'leader_client_id', 'product_id', 'target_size', 'joined_count', 'price_rate_bps',
                     'cost_rate_bps', 'safety_margin_bps', 'expire_time', 'success_time', 'claim_expire_time'] as $field) {
            $row[$field] = (int) ($row[$field] ?? 0);
        }
        foreach (['subsidy_price', 'subsidy_reference_price', 'subsidy_unit_loss', 'reserved_loss'] as $field) {
            $row[$field] = Money::normalize($row[$field] ?? 0);
        }
        $row['remaining'] = max(0, $row['target_size'] - $row['joined_count']);
        $row['display_discount'] = self::rateLabel($row['price_rate_bps']);
        $row['mode_label'] = self::modeLabel((string) $row['mode'], $row['target_size']);
        $row['display_price'] = (string) $row['mode'] === 'subsidy' ? '¥0.10' : $row['display_discount'];
        return $row;
    }

    /**
     * 前台只暴露加入拼团所需字段；请求幂等键、客户 ID、成本和补贴预留仅供后台及服务端使用。
     */
    private static function clientGroupView($group)
    {
        return [
            'id' => (int) $group['id'],
            'group_no' => (string) $group['group_no'],
            'product_id' => (int) $group['product_id'],
            'product_name' => (string) ($group['product_name'] ?? ''),
            'mode' => (string) $group['mode'],
            'target_size' => (int) $group['target_size'],
            'joined_count' => (int) $group['joined_count'],
            'price_rate_bps' => (int) $group['price_rate_bps'],
            'status' => (string) $group['status'],
            'expire_time' => (int) $group['expire_time'],
            'remaining' => (int) $group['remaining'],
            'display_discount' => (string) $group['display_discount'],
            'mode_label' => (string) $group['mode_label'],
            'display_price' => (string) $group['display_price'],
            'joined_by_me' => !empty($group['joined_by_me']),
        ];
    }

    /**
     * 我的拼团同样采用明确白名单，避免把内部客户、订单、优惠码 ID 和补贴快照带到浏览器。
     */
    private static function clientMembershipView($membership)
    {
        return [
            'group_no' => (string) $membership['group_no'],
            'product_id' => (int) $membership['product_id'],
            'product_name' => (string) ($membership['product_name'] ?? ''),
            'mode' => (string) $membership['mode'],
            'target_size' => (int) $membership['target_size'],
            'joined_count' => (int) $membership['joined_count'],
            'status' => (string) $membership['status'],
            'group_status' => (string) $membership['group_status'],
            'group_expire_time' => (int) $membership['group_expire_time'],
            'claim_expire_time' => (int) $membership['claim_expire_time'],
            'display_discount' => (string) $membership['display_discount'],
            'mode_label' => (string) $membership['mode_label'],
            'code' => (string) $membership['code'],
            'buy_url' => (string) $membership['buy_url'],
        ];
    }

    /**
     * 优先使用后台配置的会员中心域名，避免由未经信任的 Host 头生成传播链接。
     */
    private static function trustedShareOrigin($requestOrigin)
    {
        $configured = '';
        if (function_exists('configuration')) {
            try {
                $configured = trim((string) configuration('clientarea_url'));
            } catch (\Throwable $e) {
                $configured = '';
            }
        }
        $origin = rtrim($configured !== '' ? $configured : trim((string) $requestOrigin), '/');
        if ($origin === '' || filter_var($origin, FILTER_VALIDATE_URL) === false) {
            throw new \RuntimeException('当前站点地址不可用于分享');
        }
        $parts = parse_url($origin);
        if (!is_array($parts) || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            || empty($parts['host']) || !empty($parts['user']) || !empty($parts['pass'])
            || !empty($parts['query']) || !empty($parts['fragment'])
            || (!empty($parts['path']) && (string) $parts['path'] !== '/')) {
            throw new \RuntimeException('当前站点地址不可用于分享');
        }
        $scheme = strtolower((string) $parts['scheme']);
        $host = strtolower((string) $parts['host']);
        if (strpos($host, ':') !== false && $host[0] !== '[') {
            $host = '[' . $host . ']';
        }
        return $scheme . '://' . $host . (isset($parts['port']) ? ':' . (int) $parts['port'] : '');
    }

    private static function validateMode($mode)
    {
        if (!in_array($mode, ['normal', 'subsidy'], true)) {
            throw new \DomainException('请选择普通拼团或10人0.1元拼团');
        }
        return $mode;
    }

    private static function assertEligibleProduct($id, $mode, $settings)
    {
        $mode = self::validateMode($mode);
        if ($mode === 'subsidy' && empty($settings['subsidy_enabled'])) {
            throw new \DomainException('10人0.1元拼团当前未开放');
        }
        $ids = $mode === 'subsidy' ? $settings['subsidy_product_ids'] : $settings['normal_product_ids'];
        if (!in_array((int) $id, $ids, true)) {
            throw new \DomainException($mode === 'subsidy'
                ? '该商品不在10人0.1元基础款白名单中'
                : '该商品不支持普通拼团');
        }
        return self::assertProduct((int) $id, '拼团商品');
    }

    private static function assertProduct($id, $label)
    {
        $product = Db::name('product')->where('id', (int) $id)->where('product_id', 0)->find();
        if (empty($product)) {
            throw new \DomainException($label . '不存在');
        }
        if ((int) $product['hidden'] === 1) {
            throw new \DomainException($label . '当前已隐藏，用户无法在购买页完成下单');
        }
        if ((string) $product['pay_type'] === 'free') {
            throw new \DomainException($label . '不能是免费商品');
        }
        $product['price'] = Money::normalize($product['price'] ?? 0);
        return $product;
    }

    private static function assertNoActiveMembership($clientId, $productId, $mode)
    {
        $active = Db::name('addon_community_group_buy_member')->alias('m')
            ->join('addon_community_group_buy_group g', 'g.id=m.group_id')
            ->where('m.client_id', (int) $clientId)->where('g.mode', (string) $mode)
            ->whereIn('m.status', self::ACTIVE_MEMBER_STATUSES);
        if ((string) $mode === 'normal') {
            $active->where('g.product_id', (int) $productId);
        }
        $active = $active->lock(true)->find();
        if (!empty($active)) {
            throw new \DomainException('你已在该商品的同类拼团中，不能重复参加');
        }
    }

    private static function assertSubsidyLifetime($clientId, $limit)
    {
        $count = (int) Db::name('addon_community_group_buy_member')->alias('m')
            ->join('addon_community_group_buy_group g', 'g.id=m.group_id')
            ->leftJoin('addon_promo_code pc', 'pc.id=m.promo_code_id')
            ->where('m.client_id', (int) $clientId)->where('g.status', 'success')
            ->where(function ($q) {
                $q->where('g.mode', 'subsidy')->whereOr(function ($legacy) {
                    $legacy->where('g.mode', 'legacy')->where('pc.code', 'like', 'GBS-%');
                });
            })->count();
        if ($count >= (int) $limit) {
            throw new \DomainException('你的10人0.1元拼团次数已达上限，可以选择普通拼团');
        }
    }

    private static function lockClient($clientId)
    {
        $client = Db::name('client')->where('id', (int) $clientId)->lock(true)->find();
        if (empty($client)) {
            throw new \DomainException('用户不存在或已失效');
        }
    }

    private static function expireDisallowedGatheringGroups($settings)
    {
        $groups = Db::name('addon_community_group_buy_group')->where('status', 'gathering')->lock(true)->select()->toArray();
        $expired = 0;
        $now = time();
        foreach ($groups as $group) {
            $allowed = (string) $group['mode'] === 'normal'
                ? in_array((int) $group['product_id'], $settings['normal_product_ids'], true)
                : ((string) $group['mode'] === 'subsidy' && !empty($settings['subsidy_enabled'])
                    && in_array((int) $group['product_id'], $settings['subsidy_product_ids'], true));
            if ($allowed) {
                continue;
            }
            Db::name('addon_community_group_buy_group')->where('id', (int) $group['id'])->update([
                'status' => 'expired', 'reserved_loss' => '0.00', 'update_time' => $now,
            ]);
            Db::name('addon_community_group_buy_member')->where('group_id', (int) $group['id'])
                ->where('status', 'joined')->update(['status' => 'expired', 'update_time' => $now]);
            $expired++;
        }
        return $expired;
    }

    private static function releaseMemberReserve($member)
    {
        $amount = Money::normalize($member['reserved_loss'] ?? 0);
        if (!Money::isPositive($amount)) {
            return;
        }
        $group = Db::name('addon_community_group_buy_group')->where('id', (int) $member['group_id'])->lock(true)->find();
        if (empty($group)) {
            return;
        }
        Db::name('addon_community_group_buy_group')->where('id', (int) $group['id'])->update([
            'reserved_loss' => Money::max(Money::sub($group['reserved_loss'], $amount), '0.00'),
            'update_time' => time(),
        ]);
    }

    private static function reservedLoss()
    {
        return Money::normalize(Db::name('addon_community_group_buy_group')->sum('reserved_loss'));
    }

    private static function unitLoss($referencePrice)
    {
        return Money::max(Money::sub(Money::mulBps($referencePrice, self::COST_RATE_BPS), self::SUBSIDY_PRICE), '0.00');
    }

    private static function singleOrderInput($param, $productId)
    {
        if (!empty($param['param']) && is_array($param['param'])) {
            $input = $param['param'];
            return (int) ($input['product_id'] ?? 0) === $productId && (int) ($input['qty'] ?? 1) === 1 ? $input : null;
        }
        if (!empty($param['cart']) && is_array($param['cart']) && count($param['cart']) === 1) {
            $input = reset($param['cart']);
            return (int) ($input['product_id'] ?? 0) === $productId && (int) ($input['qty'] ?? 1) === 1 ? $input : null;
        }
        return null;
    }

    private static function codesFromOrderContext($param)
    {
        $codes = [];
        if (!empty($param['param']['customfield'])) {
            self::collectGroupCodes($param['param']['customfield'], $codes);
        }
        if (!empty($param['cart'])) {
            self::collectGroupCodes($param['cart'], $codes);
        }
        try {
            self::collectGroupCodes(request()->param('customfield', []), $codes);
        } catch (\Throwable $e) {
        }
        return array_values(array_unique($codes));
    }

    private static function collectGroupCodes($value, &$codes)
    {
        if (is_array($value)) {
            foreach ($value as $item) {
                self::collectGroupCodes($item, $codes);
            }
            return;
        }
        if (!is_scalar($value)) {
            return;
        }
        $candidate = strtoupper(trim((string) $value));
        if (preg_match('/^GB[NS]-[A-F0-9]{32}$/', $candidate)) {
            $codes[] = $candidate;
        }
    }

    private static function memberByCode($code, $lock = false)
    {
        $promoId = (int) Db::name('addon_promo_code')->where('code', (string) $code)->value('id');
        if ($promoId <= 0) {
            return null;
        }
        $query = Db::name('addon_community_group_buy_member')->where('promo_code_id', $promoId);
        if ($lock) {
            $query->lock(true);
        }
        return $query->find();
    }

    private static function effectiveMode($group, $code)
    {
        if ((string) $group['mode'] === 'legacy') {
            return strpos(strtoupper((string) $code), 'GBS-') === 0 ? 'subsidy' : 'normal';
        }
        return (string) $group['mode'];
    }

    private static function effectiveProductId($group, $promoId)
    {
        if ((string) $group['mode'] !== 'legacy') {
            return (int) $group['product_id'];
        }
        $productId = (int) Db::name('addon_promo_code_product')
            ->where('addon_promo_code_id', (int) $promoId)->value('product_id');
        return $productId > 0 ? $productId : (int) $group['product_id'];
    }

    private static function clientDiscount($clientId, $productId, $amount)
    {
        $discount = '0.00';
        try {
            $results = hook('client_discount_by_amount', [
                'client_id' => (int) $clientId, 'product_id' => (int) $productId, 'amount' => Money::normalize($amount),
            ]);
            foreach ((array) $results as $result) {
                if ((int) ($result['status'] ?? 0) === 200) {
                    $discount = Money::add($discount, $result['data']['discount'] ?? 0);
                }
            }
        } catch (\Throwable $e) {
        }
        return Money::min($discount, $amount);
    }

    private static function disableMemberCode($member)
    {
        $promoId = (int) ($member['promo_code_id'] ?? 0);
        if ($promoId > 0) {
            Db::name('addon_promo_code')->where('id', $promoId)->update(['status' => 0, 'update_time' => time()]);
        }
    }

    private static function normalizeIds($value)
    {
        if (is_string($value)) {
            $value = preg_split('/[\s,]+/', trim($value));
        }
        $ids = [];
        foreach ((array) $value as $id) {
            $id = (int) $id;
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }
        ksort($ids);
        return array_values($ids);
    }

    private static function requestKey($clientId, $requestId)
    {
        $requestId = trim((string) $requestId);
        if ($requestId === '') {
            return '';
        }
        if (!preg_match('/^[A-Za-z0-9_-]{16,64}$/', $requestId)) {
            throw new \DomainException('请求已过期，请刷新页面后重试');
        }
        return (int) $clientId . ':' . $requestId;
    }

    private static function groupResult($group)
    {
        return ['group_id' => (int) $group['id'], 'status' => (string) $group['status'],
            'joined_count' => (int) $group['joined_count'], 'target_size' => (int) $group['target_size']];
    }

    /**
     * 复用 V10 10.4.6 自带网关中的 LGPL phpqrcode，仅取文本矩阵交给浏览器绘图。
     * 不生成服务器文件，也不新增媒体表或分享表。
     */
    private static function qrMatrix($text)
    {
        if (!class_exists('\\QRcode', false)) {
            $root = defined('WEB_ROOT') ? rtrim((string) WEB_ROOT, '/\\') : rtrim((string) public_path(), '/\\');
            foreach ([
                $root . '/plugins/gateway/wx_pay/phpqrcode/phpqrcode.php',
                $root . '/plugins/gateway/ali_pay_dmf/phpqrcode/phpqrcode.php',
            ] as $library) {
                if (is_file($library)) {
                    require_once $library;
                    break;
                }
            }
        }
        if (!class_exists('\\QRcode', false)) {
            throw new \RuntimeException('V10 官方二维码组件不可用，请联系管理员');
        }
        $oldLevel = error_reporting();
        try {
            error_reporting($oldLevel & ~E_DEPRECATED & ~E_NOTICE & ~E_WARNING);
            $rows = \QRcode::text((string) $text, false, 'M');
        } finally {
            error_reporting($oldLevel);
        }
        if (!is_array($rows) || count($rows) < 21 || count($rows) > 177) {
            throw new \RuntimeException('二维码生成失败，请稍后重试');
        }
        $size = count($rows);
        $matrix = [];
        foreach ($rows as $row) {
            $row = (string) $row;
            if (strlen($row) !== $size || preg_match('/[^01]/', $row)) {
                throw new \RuntimeException('二维码矩阵校验失败，请稍后重试');
            }
            $matrix[] = $row;
        }
        return $matrix;
    }

    private static function newCode($prefix)
    {
        do {
            $code = $prefix . '-' . strtoupper(bin2hex(random_bytes(16)));
        } while (Db::name('addon_promo_code')->where('code', $code)->count() > 0);
        return $code;
    }

    private static function groupNumber()
    {
        return 'GB' . date('YmdHis') . strtoupper(bin2hex(random_bytes(5)));
    }

    private static function rateLabel($basisPoints)
    {
        if ((int) $basisPoints <= 0) {
            return '-';
        }
        $value = bcdiv((string) ((int) $basisPoints), '1000', 1);
        return rtrim(rtrim($value, '0'), '.') . '折';
    }

    private static function modeLabel($mode, $targetSize)
    {
        if ($mode === 'subsidy') {
            return '10人团·0.1元基础款';
        }
        if ($mode === 'legacy') {
            return '历史团购权益';
        }
        return (int) $targetSize . '人团·' . self::rateLabel(self::DEFAULT_RATES[(int) $targetSize] ?? 0);
    }

    private static function maskedClientName($member)
    {
        $value = trim((string) ($member['username'] ?? ''));
        if ($value === '') {
            $value = trim((string) ($member['email'] ?? ''));
        }
        if ($value === '') {
            $value = trim((string) ($member['phone'] ?? ''));
        }
        if ($value === '') {
            return '匿名用户';
        }
        $length = mb_strlen($value);
        return $length <= 2 ? mb_substr($value, 0, 1) . '*'
            : mb_substr($value, 0, 1) . str_repeat('*', min(5, $length - 2)) . mb_substr($value, -1);
    }

    private static function clientId()
    {
        return function_exists('get_client_id') ? (int) get_client_id() : 0;
    }

    private static function adminId()
    {
        return function_exists('get_admin_id') ? (int) get_admin_id() : 0;
    }

    private static function ensureRuntimeHookOrder()
    {
        try {
            $order = Db::name('plugin')->where('module', 'addon')->where('name', 'CommunityGroupBuy')->value('hook_order');
            if ((int) $order !== 1000) {
                Db::name('plugin')->where('module', 'addon')->where('name', 'CommunityGroupBuy')
                    ->update(['hook_order' => 1000, 'update_time' => time()]);
            }
        } catch (\Throwable $e) {
        }
    }

    private static function audit($event, $groupId = 0, $memberId = 0, $clientId = 0, $orderId = 0,
                                  $operatorType = 'system', $operatorId = 0, $extra = [])
    {
        try {
            $payload = [
                'event' => (string) $event, 'group_id' => (int) $groupId, 'member_id' => (int) $memberId,
                'client_id' => (int) $clientId, 'order_id' => (int) $orderId,
                'operator_type' => (string) $operatorType, 'operator_id' => (int) $operatorId, 'extra' => $extra,
            ];
            $relId = (int) ($orderId ?: ($groupId ?: $memberId));
            active_log('CommunityGroupBuy '
                . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'community_group_buy', $relId, (int) $clientId);
        } catch (\Throwable $e) {
            // 官方日志失败不得破坏下单主交易。
        }
    }
}
