# 商品拼团 1.0.0

适用于 ZJMF-CBAP V10 的开源 Addon。管理员维护可参团商品白名单，用户在自己的会员中心选择商品，再选择 3 人、5 人、10 人普通团，或参加可单独开关的“10 人团 0.1 元基础款”。

本仓库只包含插件原创源码，不包含 V10 本体、官方商业插件、客户数据、服务器资料、访问凭据或签名私钥。

- 作者：`006IDC`
- 许可证：MIT
- 插件身份：`CommunityGroupBuy / community_group_buy`

## 核心能力

- 用户自助选择商品并发起拼团，管理员不代替用户创建活动。
- 普通团固定为 3 人 9 折、5 人 8 折、10 人 7 折。
- 10 人 0.1 元模式默认关闭；开启后，满团 10 人每人的首次开通价均为 0.1 元。
- 普通团和 0.1 元模式分别使用商品白名单，不支持活动的商品不会出现在用户端。
- 开团后生成上车链接、二维码和 750×1000 宣传图；链接只负责定位拼团，不绕过登录和参团校验。
- 成团后为每名成员生成绑定本人、指定商品、仅限新购且不可续费的一次性官方优惠权益。
- 白名单和风控参数复用 V10 官方 `plugin.config`，运行数据只新增两张必要业务表。

## 价格与成本

插件按商品售价的 65% 估算成本。理论保本比例为 65%；普通团额外保留 5 个百分点的售价安全垫，所以最低实付比例为 70%。

| 团型 | 用户实付 | 按售价计毛利 | 按收入计毛利率 |
| --- | ---: | ---: | ---: |
| 3 人团 | 90% | 25% | 约 27.78% |
| 5 人团 | 80% | 15% | 约 18.75% |
| 10 人团 | 70% | 5% | 约 7.14% |

0.1 元模式是明确的获客补贴，不是保本方案：

```text
每人预计亏损 = max(基础售价 × 65% - 0.10, 0)
整团预留亏损 = 每人预计亏损 × 10
```

例如基础售价为 156.00 元时，每人预计亏损 101.30 元，发起一个 10 人团会预留 1013.00 元预算。第二个月起仍由 V10 按当时的正常续费价处理。

## 兼容性与依赖

- 已验证基线：ZJMF-CBAP V10 10.4.6。
- 设计兼容范围：V10 10.4.6 至 10.x；其他小版本上线前应在隔离环境回归。
- PHP 7.3 或更高版本。
- PHP 扩展：BCMath、Mbstring；本地构建 ZIP 还需要 Zip。
- 必需依赖：V10 官方“优惠码插件（基础版）”，插件标识 `PromoCode`。

## 安装与部署

### 环境要求

- ZJMF-CBAP V10 10.4.6 或更高的 10.x 版本。
- PHP 7.3+，启用 BCMath、Mbstring。
- V10 官方“优惠码插件（基础版）”已经安装并启用。
- 建议在 V10 后台把会员中心地址配置为 `https://example.com`，邀请链接优先使用该可信地址。

### 后台导入安装

1. 从 GitHub Release 下载 `community_group_buy_1.0.0_v10_import.zip`。
2. 登录 V10 后台，确认官方优惠码插件已经启用。
3. 进入 Addon 插件管理，上传 ZIP 并执行安装。
4. 启用“商品拼团”。
5. 进入插件管理页，先选择“基础拼团商品白名单”。
6. 需要 0.1 元模式时，先设置总预算、终身次数和有效期，再单独开启开关。

不要上传 GitHub 自动生成的 Source code 压缩包。V10 安装包必须只有一个 `community_group_buy/` 根目录，入口文件为 `CommunityGroupBuy.php`。

### 手工放入 plugins 目录

仅在后台不能上传时使用。以下 `/var/www/example` 是示例 V10 应用根目录：

```bash
unzip community_group_buy_1.0.0_v10_import.zip -d /tmp/community-group-buy-install
cp -a /tmp/community-group-buy-install/community_group_buy /var/www/example/public/plugins/addon/
```

复制后仍需在 V10 后台执行安装和启用，让系统创建插件记录、权限、导航、Hook 和两张业务表。不要手工向 V10 核心表插入插件记录。

### 首次配置

1. 选择允许参加普通团的商品。
2. 在用户端验证 3 人、5 人和 10 人团入口。
3. 如需使用 0.1 元模式，只把独立、低配置的基础商品加入对应白名单。
4. 设置补贴总预算、每用户终身次数、拼团有效时间和成团后购买时间。
5. 核对管理页显示的整团预留亏损后再启用 0.1 元模式。

所有设置通过一个后台保存接口原子写入 V10 官方 `plugin.config`，不需要手工执行 SQL。

### 安装后验证

- 后台导航显示“商品拼团”，用户端导航显示“拼团优惠”。
- 用户端只展示对应白名单中未隐藏的付费商品。
- 发团按钮具有处理中、成功和失败反馈，并阻止重复提交。
- 开团成功后可以复制上车链接并保存二维码宣传图。
- 0.1 元模式关闭后，接口也不能绕过开关发起补贴团。
- 新安装只创建 `addon_community_group_buy_group` 和 `addon_community_group_buy_member` 两张插件业务表。

### 升级与回滚

升级前备份数据库和 `public/plugins/addon/community_group_buy`，通过目标 V10 支持的插件升级流程替换文件并执行幂等 `upgrade()`。不要通过卸载再安装完成常规升级。

文件升级失败时恢复完整插件目录；数据库迁移失败时保留现场并核对迁移日志。MySQL DDL 可能隐式提交，恢复文件不等于恢复表结构。若只需停止业务，请禁用插件或关闭 0.1 元模式；卸载默认保留经营与订单归因数据。

更详细的故障排查见 [DEPLOYMENT.md](DEPLOYMENT.md)。

## 开源身份

| 项目 | 固定值 |
| --- | --- |
| 模块 | `addon` |
| 插件名 / 入口类 | `CommunityGroupBuy` |
| 插件目录 | `community_group_buy` |
| 入口文件 | `CommunityGroupBuy.php` |
| 用户接口前缀 | `/console/v1/community_group_buy` |
| 管理接口前缀 | `/{后台目录}/v1/community_group_buy` |

发布后不要修改这些身份字段，否则 V10 会把它识别为另一个插件。该开源身份从 1.0.0 开始，不用于覆盖其他名称的同类插件。

## 数据库边界

新安装只创建：

- `addon_community_group_buy_group`：团状态、人数、时效与发团时风控快照。
- `addon_community_group_buy_member`：团与用户的唯一成员关系、权益状态及官方优惠码引用。

其余数据复用 V10 官方表。详情见 [docs/DATABASE.md](docs/DATABASE.md) 和 [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md)。

## 开发与验证

```bash
php tests/money_test.php
php tests/contract_test.php
php tools/repository_audit.php
find . -type f -name '*.php' -not -path './dist/*' -print0 | xargs -0 -n1 php -l
node --check public/group-buy-admin.js
node --check public/group-buy-client.js
php tools/build_release.php
php tools/build_source_archive.php
```

`tools/build_release.php` 生成仅含 20 个运行文件的 V10 导入包；`tools/build_source_archive.php` 生成包含许可证、文档、测试、CI 和构建工具的源码归档。

## 文档

- [部署说明](DEPLOYMENT.md)
- [配置说明](docs/CONFIGURATION.md)
- [架构说明](docs/ARCHITECTURE.md)
- [数据库审计](docs/DATABASE.md)
- [V10 合规说明](docs/V10_COMPLIANCE.md)
- [安全策略](SECURITY.md)
- [贡献指南](CONTRIBUTING.md)

## 许可证

原创代码采用 [MIT License](LICENSE)。V10、官方优惠码插件和宿主系统提供的二维码组件不属于本仓库授权范围，见 [THIRD_PARTY_NOTICES.md](THIRD_PARTY_NOTICES.md)。
