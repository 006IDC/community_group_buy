# V10 商品拼团插件（开源版）

## 产品介绍

V10 开源商品拼团 Addon，支持 3、5、10 人团及独立可控的 0.1 元获客补贴模式。

## 版本概览

| 项目 | 公开说明 |
| --- | --- |
| 公开发行版本 | [v1.0.0](https://github.com/006IDC/v10-group-buy-public/releases/tag/v1.0.0) |
| 适用环境 | 公开说明已验证基线 V10 10.4.6；PHP 7.3+ / BCMath / Mbstring |
| 使用边界 | 其他 V10 10.x 小版本上线前须隔离回归；依赖官方 PromoCode。0.1 元模式是预算补贴，默认关闭，不是保本承诺。 |

版本以本仓公开 Release 为准；完整技术说明见[运行与维护说明](docs/repository-guide.md)。

## 主要功能

- 商品白名单与用户自助开团：3 人 9 折、5 人 8 折、10 人 7 折。
- 邀请链接、二维码及宣传图，不绕过登录和参团校验。
- 本人、指定商品、仅新购的一次性官方优惠权益。
- 0.1 元模式独立白名单、预算与终身次数限制。

## 使用与升级

从 [Releases](https://github.com/006IDC/v10-group-buy-public/releases) 下载 `community_group_buy_1.0.0_v10_import.zip`，通过 V10 Addon 管理导入、安装并启用。保持 `CommunityGroupBuy / community_group_buy` 身份；升级前备份数据库及插件目录，不用卸载重装代替常规升级。详见[部署说明](DEPLOYMENT.md)和[技术说明](docs/repository-guide.md)。

## 版本演进

版本变化见[变更记录](CHANGELOG.md)。适用环境和部署检查以对应版本文档为准。

## 许可证与维护

由[零零陆云计算（006IDC）](https://github.com/006IDC)独立维护。原创代码采用 [MIT License](LICENSE)。V10、官方优惠码插件及宿主二维码组件不在本仓授权范围，见[第三方声明](THIRD_PARTY_NOTICES.md)。
