# 参与贡献

感谢参与商品拼团插件的改进。

## 基本规则

1. 不修改 ZJMF-CBAP 核心文件，不提交 V10 本体、官方插件或商业资源。
2. 不提交服务器地址、真实域名、账号口令、私钥、`.env`、客户数据、订单快照或构建产物。
3. 文档示例域名统一使用 `example.com`，示例路径和账号使用 `example`。
4. 保持插件身份不变：`CommunityGroupBuy / community_group_buy`。
5. 数据迁移必须可重复执行；已有安装应通过 `upgrade()` 原位升级。
6. 新增数据表前先证明 V10 官方表不能表达该业务事实，并同步更新数据库文档。
7. 用户可见写操作必须有提交中、成功、失败和“结果未知”反馈。
8. 新增公开接口字段时按白名单输出，不得复用后台 DTO。

## 本地检查

```bash
find . -type f -name '*.php' -not -path './dist/*' -print0 | xargs -0 -n1 php -l
php tests/money_test.php
php tests/contract_test.php
php tools/repository_audit.php
node --check public/group-buy-admin.js
node --check public/group-buy-client.js
php tools/build_release.php
```

功能变更还应在隔离的 V10 测试站覆盖：首次安装、启用/禁用、升级、权限、前后台接口、发团/参团、支付/取消/退款、过期释放、PC/Mobile 与缓存刷新。

## 提交说明

- 一个提交只处理一类问题，并写清业务语义与验证结果。
- 数据库、API 或配置变更必须同步 README、CHANGELOG 和相关文档。
- 安全问题遵循 [SECURITY.md](SECURITY.md)，不要在公开 Issue 中披露可利用细节。
