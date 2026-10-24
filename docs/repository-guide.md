# V10 商品拼团插件 · 使用说明

面向 ZJMF-CBAP V10 的独立 Addon。管理员维护商品白名单，用户在会员中心自行选择商品、开团并邀请其他用户参与。

[产品首页](../README.md) · [部署说明](../DEPLOYMENT.md) · [配置说明](CONFIGURATION.md) · [版本下载](https://github.com/006IDC/v10-group-buy-public/releases) · [更新记录](../CHANGELOG.md)

## 使用前确认

- 当前公开发行：`1.0.0`；稳定身份为 `CommunityGroupBuy / community_group_buy`。
- 已验证基线为 V10 10.4.6；设计范围为 10.4.6 至 10.x，其他小版本须先在隔离环境回归。
- PHP 7.3+，需 BCMath、Mbstring；必须启用官方“优惠码插件（基础版）”`PromoCode`。本地构建 ZIP 另需 Zip 扩展。
- 0.1 元模式默认关闭，是预算控制的获客补贴，不是保本方案。续费仍由 V10 按当时正常价格处理。

## 安装与首次配置

1. 下载 `community_group_buy_1.0.0_v10_import.zip`，在 V10 Addon 管理中上传、安装并启用；不要上传自动 Source code ZIP。
2. 配置 V10 会员中心可信 HTTPS 地址，选择普通拼团商品白名单，验证 3 人、5 人、10 人团入口。
3. 如需补贴模式，使用独立的基础商品白名单，先设置总预算、每用户终身次数、成团时限和成团后购买时间。
4. 核对整团预留亏损后，再单独开启 0.1 元模式。

详细目录、后台导入、手工放置与安装检查见[部署说明](../DEPLOYMENT.md)。只复制文件仍需后台安装与启用，不能手工向核心表插入插件记录；配置统一写入官方 `plugin.config`，不需手工执行 SQL。

## 用户参团流程

1. 用户选择对应白名单中未隐藏的付费商品并发起团，管理员不代替用户创建活动。
2. 普通团为 3 人 9 折、5 人 8 折、10 人 7 折；0.1 元模式使用独立开关与白名单。
3. 开团后可复制上车链接、保存二维码和 750×1000 宣传图。链接只定位拼团，不绕过登录或参团校验。
4. 成团后为每名成员生成绑定本人、指定商品、仅限新购的一次性官方优惠权益，不可用于续费。
5. 0.1 元团满 10 人后，每名成员首次开通价为 0.1 元；需遵守预算、终身次数及有效期限制。

## 价格与补贴口径

插件按商品售价的 65% 估算成本，普通团另留 5 个百分点安全垫，最低实付为 70%。这是插件的估算口径，不代表实际经营成本或收益承诺。

| 团型 | 用户实付 | 按售价计毛利 | 按收入计毛利率 |
| --- | ---: | ---: | ---: |
| 3 人团 | 90% | 25% | 约 27.78% |
| 5 人团 | 80% | 15% | 约 18.75% |
| 10 人团 | 70% | 5% | 约 7.14% |

0.1 元模式单独计算预算：

```text
每人预计亏损 = max(基础售价 × 65% - 0.10, 0)
整团预留亏损 = 每人预计亏损 × 10
```

基础售价 156.00 元时，每人预计亏损 101.30 元，10 人团预留预算 1013.00 元。第二个月起仍按正常续费价处理。

## 身份与数据边界

- 模块 `addon`；插件名/入口类 `CommunityGroupBuy`；目录 `community_group_buy`；入口 `CommunityGroupBuy.php`。
- 用户接口 `/console/v1/community_group_buy`，管理接口 `/{后台目录}/v1/community_group_buy`。发布后不改这些身份，1.0.0 开源包不覆盖其他名称的同类插件。
- 只新增 `addon_community_group_buy_group`（团状态、人数、时效与风控快照）和 `addon_community_group_buy_member`（成员、权益与官方优惠码引用）两张业务表，其余复用宿主。
- 正常升级保留完整插件目录并执行幂等 `upgrade()`，不卸载重装。卸载默认保留经营及订单归因数据；恢复文件不等于撤回 MySQL DDL，详见[升级与回退](../DEPLOYMENT.md)。

数据与实现边界见[数据库说明](DATABASE.md)、[架构说明](ARCHITECTURE.md)及[V10 合规说明](V10_COMPLIANCE.md)。

## 开发与验证

源码目录必须命名为 `community_group_buy`；从源码开发时执行：

```bash
git clone https://github.com/006IDC/v10-group-buy-public.git community_group_buy
cd community_group_buy
php tests/money_test.php
php tests/contract_test.php
php tools/repository_audit.php
find . -type f -name '*.php' -not -path './dist/*' -print0 | xargs -0 -n1 php -l
node --check public/group-buy-admin.js
node --check public/group-buy-client.js
php tools/build_release.php
php tools/build_source_archive.php
```

构建工具分别生成含 20 个运行文件的 V10 导入包，以及含许可、文档、测试、CI 与构建工具的源码归档。仅在源码/隔离环境进行这些检查；不把源码包当后台安装包。

## 安全与许可

本仓库不含 V10 本体、官方商业插件、客户数据、服务器资料、凭据或签名私钥。安全问题见[安全策略](../SECURITY.md)，贡献方式见[贡献指南](../CONTRIBUTING.md)。

原创代码采用 [MIT License](../LICENSE)。V10、官方优惠码插件与宿主二维码组件不在本仓授权范围，见[第三方声明](../THIRD_PARTY_NOTICES.md)。由[零零陆云计算（006IDC）](https://github.com/006IDC)独立维护。
