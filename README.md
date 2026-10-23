# V10 商品拼团插件（开源版）

适用于 ZJMF-CBAP V10 的商品拼团 Addon，支持 3、5、10 人团及独立可控的 0.1 元获客补贴模式。

[使用文档](docs/repository-guide.md) · [部署说明](DEPLOYMENT.md) · [版本下载](https://github.com/006IDC/v10-group-buy-public/releases) · [更新记录](CHANGELOG.md)

**当前发行：** [v1.0.0](https://github.com/006IDC/v10-group-buy-public/releases/tag/v1.0.0)

## 主要功能

- 商品白名单与用户自助开团：3 人 9 折、5 人 8 折、10 人 7 折。
- 邀请链接、二维码及宣传图，不绕过登录和参团校验。
- 本人、指定商品、仅新购的一次性官方优惠权益。
- 0.1 元模式的独立白名单、预算与终身次数限制。

## 安装与升级

已验证基线：V10 10.4.6；PHP 7.3+，需 BCMath 与 Mbstring 扩展，依赖官方 PromoCode。

1. 从 [Releases](https://github.com/006IDC/v10-group-buy-public/releases) 下载 `community_group_buy_1.0.0_v10_import.zip`，通过 V10 Addon 管理导入、安装并启用。
2. 保持 `CommunityGroupBuy / community_group_buy` 身份；升级前备份数据库与插件目录，不用卸载重装代替常规升级。详细步骤见[部署说明](DEPLOYMENT.md)和[使用文档](docs/repository-guide.md)。

> 其他 V10 10.x 小版本上线前须隔离回归。0.1 元模式是预算补贴，默认关闭，不是保本承诺。

<details>
<summary>从源码开发构建</summary>

源码目录仍须命名为 `community_group_buy`；仓库技术名与插件安装身份分别维护：

```bash
git clone https://github.com/006IDC/v10-group-buy-public.git community_group_buy
cd community_group_buy
```

</details>

## 版本与兼容性

2026-10-22 维护了 GitHub Actions 依赖，仅更新工作流与说明，保留当前公开发行版本。完整变化见[更新记录](CHANGELOG.md)，部署与兼容性以对应版本文档为准。

## 许可证与维护

由[零零陆云计算（006IDC）](https://github.com/006IDC)独立维护。原创代码采用 [MIT License](LICENSE)。V10、官方优惠码插件与宿主二维码组件不在本仓授权范围，见[第三方声明](THIRD_PARTY_NOTICES.md)。
