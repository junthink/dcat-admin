# CLS 业务日志

可选的腾讯云 CLS 日志浏览功能，默认关闭。启用后在后台前缀下提供只读页面 `/cls-logs`，支持时间范围、服务、日志级别、关键词和分页，沿用所在 Dcat 应用的登录、权限与中间件。不修改日志采集链路，不需要安装腾讯云 SDK 或重新构建前端。

在宿主项目的 `config/admin.php` 增加：

```php
'cls' => [
    'enabled' => env('CLS_ENABLED', false),
    'region' => env('CLS_REGION', 'ap-guangzhou'),
    'topic_id' => env('CLS_TOPIC_ID'),
    'secret_id' => env('CLS_SECRET_ID'),
    'secret_key' => env('CLS_SECRET_KEY'),
    'token' => env('CLS_TOKEN'), // 临时凭证可选
    'services' => ['api' => 'API 服务', 'worker' => '队列服务'],
    'service_field' => env('CLS_SERVICE_FIELD', '__TAG__.container_name'),
    'level_field' => env('CLS_LEVEL_FIELD', 'level_name'),
    'message_field' => env('CLS_MESSAGE_FIELD', 'message'),
    'page_size' => 100,
    'max_range_hours' => 168,
],
```

将 `CLS_ENABLED=true`、实际地域、TopicId 和专用查询凭证写入运行环境。凭证只需对指定日志主题拥有 `cls:SearchLog` 权限。页面查询的主题由服务端配置决定。更改配置后重新生成配置缓存；若使用路由缓存，也需重新生成。多应用分别在对应配置文件启用，默认不会向其他应用添加路由。

执行一次 `php artisan admin:cls-install` 创建菜单和 `cls-logs` 权限，再将该权限分配给需要的角色。命令可重复运行，不会自动给普通角色授权，也不会修改已有角色。已有菜单管理方式也可手动配置：URI 为 `cls-logs`，权限方法为 `GET`，路径为 `/cls-logs`。

默认服务字段是 TKE 的 `__TAG__.container_name`，级别字段是 Monolog JSON 的 `level_name`，消息字段是 `message`。必须与实际采集结构和 CLS 字段索引一致；`services` 的键是日志中的真实服务值，值是下拉框的显示名称。支持平铺的点号字段和嵌套 JSON 展示。如果级别值使用小写或数字，可设置 `level_values`，例如 `['INFO' => 'info', 'ERROR' => 'error']`。关键词作为短语检索，依赖 CLS 全文索引，不作为任意 CQL 或 SQL 执行。

默认最近一小时、每页 100 条、单次最多查询七天。使用应用时区显示和解释时间，结束时间不包含在范围内；翻页保留固定时间和筛选条件。`timezone` 可覆盖应用时区。CLS 的错误详情仅记录错误码和 RequestId，不向页面暴露云凭证或底层异常。

使用官方 [SearchLog API](https://cloud.tencent.com/document/api/614/56447)，采用 `QueryString`、CQL 语法和 Offset 分页，通过 TC3-HMAC-SHA256 签名。自动化测试使用模拟响应验证查询和权限；真实日志检索仍需在宿主项目配置实际凭证、主题和索引后验收。
