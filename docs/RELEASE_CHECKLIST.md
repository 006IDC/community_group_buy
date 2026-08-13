# 发布清单

## 版本与源码

- [ ] `CommunityGroupBuy.php`、静态资源版本、README 和 CHANGELOG 使用同一语义版本。
- [ ] 身份仍为 `addon / CommunityGroupBuy / community_group_buy`。
- [ ] 仓库没有真实域名、IP、服务器资料、凭据、密钥、客户数据、V10 本体或构建产物。
- [ ] 文档中的示例域名只使用 `example.com`。
- [ ] GitHub Actions 固定到经过核对的完整提交 SHA。
- [ ] PHP/JS 语法、金额测试、合同测试和仓库审计全部通过。

## V10 验证

- [ ] 在隔离的目标版本验证普通包首次安装、启用、禁用与再次启用。
- [ ] 从上一正式版本原位升级，确认 `upgrade()` 可重复执行且不要求卸载。
- [ ] 管理员只能配置白名单和补贴风控，不能代替用户开团。
- [ ] 用户可选商品和 3/5/10 人团；0.1 元模式固定 10 人且每人首付均为 0.1 元。
- [ ] 开团后邀请链接、二维码、宣传图、深链接定位、登录与主动参团正常。
- [ ] 权益只用于本人、指定商品、数量 1、首次开通，不影响续费价。
- [ ] 支付、取消、删除、退款和权益过期闭环正常。
- [ ] 新安装只创建团表和成员表，没有多余插件表。
- [ ] PC、Mobile、受限账户、错误反馈、重复点击和缓存刷新通过。

## 发布包

- [ ] 运行 `php tools/build_release.php` 生成 V10 导入包。
- [ ] 导入包只有一个 `community_group_buy/` 根目录和清单中的 20 个运行文件。
- [ ] 包内没有 `.DS_Store`、`__MACOSX`、文档、测试、CI、密钥或服务器资料。
- [ ] 在空目录解压并逐文件核对 SHA-256。
- [ ] 运行 `php tools/build_source_archive.php` 生成 GitHub 源码归档。
- [ ] 记录源码包和 V10 导入包的 SHA-256。

## GitHub

- [ ] 使用 `community_group_buy` 目录作为仓库根目录，不上传整个本地工作区。
- [ ] 启用 Private vulnerability reporting 和分支保护，要求 CI 通过后合并。
- [ ] 创建 `v1.0.0` 标签与 Release。
- [ ] Release 附件上传 `_v10_import.zip` 和 SHA-256。
- [ ] Release 说明明确：GitHub 自动生成的 Source code 压缩包不能直接导入 V10。
