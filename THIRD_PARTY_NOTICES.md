# 第三方组件说明

本仓库没有复制或打包 ZJMF-CBAP V10、官方 PromoCode 插件或二维码库源码。

运行时集成：

- **ZJMF-CBAP V10**：插件通过 V10 提供的插件基类、Hook、数据库模型和中间件接口运行。V10 本体不属于本仓库的 MIT 授权范围。
- **PromoCode（优惠码插件基础版）**：必需的 V10 官方运行时依赖，本仓库不分发该插件。
- **PHP QR Code / phpqrcode**：邀请卡功能会在宿主系统已安装的官方支付网关目录中查找并调用该 LGPL 组件，只取得二维码文本矩阵；本仓库不复制或再分发该组件。请以宿主系统随附的许可证和源码说明为准。

CI 使用 GitHub Actions 的 `actions/checkout` 与 `shivammathur/setup-php`，并固定到明确提交；它们只参与仓库验证，不进入插件发布包。
