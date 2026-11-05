# CLS 业务日志

可选的腾讯云 CLS 日志浏览功能，默认关闭。启用后在后台前缀下提供只读页面 `/cls-logs`，支持时间范围、应用、日志级别、关键词、分页及原始日志详情，沿用所在 Dcat 应用的登录、权限与中间件。无需云 SDK 或前端构建，不修改日志采集链路。页面使用 Dcat 原生 Grid、筛选面板、可搜索 Select、时间区间组件、级别 Label、行展开和 Alert；翻页沿用原生简易分页，通过 CLS 适配器分别携带上一页和下一页游标，不显示虚构的总页数。桌面端时间区间、应用、日志级别、关键词和查询按钮排列在同一行，窄屏自动换行。

在宿主项目的 `config/admin.php` 增加：

```php
'cls' => [
    'enabled' => env('CLS_ENABLED', false),
    'region' => env('CLS_REGION', 'me-saudi-arabia'),
    'logset_id' => env('CLS_LOGSET_ID'),
    'logset_name' => env('CLS_LOGSET_NAME', 'imee-prod'),
    'secret_id' => env('CLS_SECRET_ID'),
    'secret_key' => env('CLS_SECRET_KEY'),
    'token' => env('CLS_TOKEN'), // 临时凭证可选
    'level_field' => env('CLS_LEVEL_FIELD', 'level_name'),
    'message_field' => env('CLS_MESSAGE_FIELD', 'message'),
    'page_size' => 100,
    'max_range_hours' => 168,
    'pagination_ttl' => 600,
],
```

运行环境设置 `CLS_ENABLED=true`、实际地域和专用查询凭证，配置日志集 ID 或名称。ID 优先；仅配置名称时，通过 `DescribeLogsets` 查找唯一精确匹配项，名称不匹配或有歧义时明确报错。随后完整翻页调用 `DescribeTopics`，限定该日志集；每个日志主题自动成为一个应用，主题名称为显示名称，主题 ID 为选择值。新增主题会在下一次查询时出现，无需维护服务列表或重发代码。支持选择单个应用，或选择“全部应用”按日志时间倒序合并。

凭证需要目标地域的 `cls:DescribeTopics`、目标日志集内所有日志主题的 `cls:SearchLog`；使用日志集名称时还需要 `cls:DescribeLogsets`。日志集和主题范围均由服务端配置控制，客户端不能提交任意主题 ID。任一主题查询失败会显示错误，不会把不完整结果显示为成功。更改配置后重新生成配置缓存；使用路由缓存时也需重新生成。其他 Dcat 应用默认不会增加此路由。

执行一次 `php artisan admin:cls-install` 创建菜单和 `cls-logs` 权限，再将该权限分配给需要的角色。命令可重复运行，不会自动给普通角色授权，也不会修改已有角色。也可手动配置：菜单 URI 为 `cls-logs`，权限方法为 `GET`，路径为 `/cls-logs`。同一页面权限可查询所配置日志集内全部应用，不提供逐主题角色权限。

级别字段默认使用 Monolog JSON 的 `level_name`，消息字段使用 `message`。应用名称直接来自主题，不依赖日志内的 `service`、容器名或 `channel`。支持平铺点号字段和嵌套 JSON 展示。字段应已建立 CLS 索引；若级别使用小写或数字，可配置 `level_values`，例如 `['INFO' => 'info', 'ERROR' => 'error']`。关键词作为一个短语检索，依赖全文索引，不作为任意 CQL 或 SQL 执行。

默认最近一小时、每页 100 条、单次最多七天。时间按应用时区解释和显示，结束时间不包含在范围内；`timezone` 可覆盖应用时区。翻页保留固定时间和筛选条件。日志集模式逐主题使用 `SearchLog` 的单主题 Offset，合并有序数据流并保存尚未消费的数据，因此不受一次多主题请求 50 个主题和 Context 共 10,000 条的限制。主题越多，需要的查询请求越多，云端查询费用和响应时间也会随之增加。

翻页快照在 Laravel 默认缓存中加密暂存 10 分钟（`pagination_ttl`，60–3600 秒），链接中仅含随机令牌，绑定当前登录用户、主题范围与查询条件。多 Pod 部署应使用共享 Redis 等缓存，并使用相同 APP_KEY；缓存不可用时明确报错，过期或条件变化后需重新查询。快照仅用于翻页，真实日志仍保留在 CLS。查询期间若主题列表变化，也需重新查询。

兼容 v3.1 单主题模式：当 `logset_id`、`logset_name` 均为空时，仍读取 `topic_id` / `CLS_TOPIC_ID`，使用 `services`、`service_field` 对同一主题内的服务做筛选，保持 Offset 翻页。日志集配置优先于这些旧配置。

采用 TC3-HMAC-SHA256 签名及 `QueryString` / CQL。CLS 失败统一记录 `CLS log query failed` 警告，包含 `diagnostic_id`、`reason`、地域、日志集及适用的接口名、主题 ID、HTTP 状态、云错误码 `code`、脱敏后的云错误说明 `cloud_message` 和 `request_id`。网络失败记录异常类型、cURL 错误号及安全分类（如超时、DNS、TLS），缓存失败记录阶段、缓存类型和异常分类；不记录原始异常、签名请求头、完整响应或查询正文。SecretId、SecretKey、Token 和签名会脱敏。页面只显示通用错误及诊断编号，按编号或 `CLS log query failed` 检索后台日志即可定位原因。API 参考：[DescribeLogsets](https://cloud.tencent.com/document/api/614/58624)、[DescribeTopics](https://cloud.tencent.com/document/api/614/56454)、[SearchLog](https://cloud.tencent.com/document/api/614/56447)。自动化测试使用模拟响应；真实检索仍需宿主项目实际凭证和索引。
