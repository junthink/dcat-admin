# Dcat Admin

Dcat Admin 是基于 Laravel 的后台系统构建工具，提供用户与角色权限管理、菜单管理、数据表格、表单、详情页和扩展机制，方便快速开发管理后台。

本项目基于 `jqhph/dcat-admin` 维护，适配 Laravel 13。Composer 包名为 `junthink/dcat-admin`，保留 `Dcat\Admin` 命名空间。

## 环境要求

- PHP 8.3 或更高版本，启用 Fileinfo、Zip 扩展。
- Laravel 13。
- Composer。

## 安装

创建 Laravel 项目；已有 Laravel 13 应用可跳过此步：

```bash
composer create-project laravel/laravel my-admin "^13.0"
cd my-admin
```

在 `.env` 中配置已创建的数据库：

```dotenv
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=my_admin
DB_USERNAME=your_username
DB_PASSWORD=your_password
```

通过 Composer 安装：

```bash
composer require junthink/dcat-admin:^3.0 --with-all-dependencies
php artisan admin:publish
php artisan admin:install
php artisan serve
```

打开 `http://127.0.0.1:8000/admin`，使用安装命令输出的账号和随机初始密码登录。也可在安装前通过 `.env` 设置 `ADMIN_INITIAL_USERNAME` 和 `ADMIN_INITIAL_PASSWORD`。

部署时，将 Web 服务的根目录指向 Laravel 项目的 `public` 目录。

## 使用

可选的 [CLS 业务日志](docs/cls-logs.md)使用原生 Grid、筛选、行展开与简易分页，支持按日志集自动发现应用主题、单个或全部应用查询、时间、日志级别和关键词筛选，默认关闭，沿用 Dcat 登录和权限。

安装后，主要开发入口如下：

- `config/admin.php`：后台地址、认证、数据库和主题配置。
- `app/Admin/routes.php`：后台路由。
- `app/Admin/Controllers/`：后台控制器，通过 `Dcat\Admin\Grid`、`Dcat\Admin\Form`、`Dcat\Admin\Show` 构建列表、表单和详情页。
- `app/Admin/bootstrap.php`：后台组件和行为的初始化配置。

在 `app/Admin/routes.php` 已有的路由组中注册资源控制器，例如：

```php
$router->resource('posts', 'PostController');
```

后台控制器可参考 `app/Admin/Controllers/HomeController.php`。查看可用管理命令：

```bash
php artisan admin
```

更新包后，重新发布前端资源：

```bash
php artisan admin:publish --assets --force
```

修改本仓库的前端源码时，需要 Node.js 22 或更高版本，在仓库根目录执行：

```bash
npm ci
npm run production
```

## 固定顺序与可搜索的 Tab

`Tab` 按添加顺序显示标签，选中项不会自动移到首位。链接式详情页可用 `addLinks()` 一次传入完整顺序，并通过回调只渲染当前内容：

```php
use Dcat\Admin\Widgets\Tab;

$links = [
    'overview' => [
        'title' => '概览',
        'href' => request()->fullUrlWithQuery(['_t' => 'overview', 'page' => 1]),
    ],
    'logs' => [
        'title' => '日志',
        'href' => request()->fullUrlWithQuery(['_t' => 'logs', 'page' => 1]),
    ],
];
$current = request('_t', 'overview');
abort_unless(is_string($current) && isset($links[$current]), 404);

$tab = Tab::make()->withCard()->searchable()->addLinks(
    $links,
    $current,
    fn () => $current === 'overview' ? '概览内容' : '日志内容'
);
```

搜索默认关闭，用 `searchable()` 开启，`searchable(false)` 关闭。开启后仅在标签栏内显示小搜索按钮，点击展开输入框和匹配结果，不增加工具栏行，也不改变标签排列。支持标题与 `addLinks()` 的键名匹配、方向键选择、Enter 跳转、Esc 关闭。

调用方应先按权限过滤链接清单，并校验请求中的当前项；组件不会添加清单之外的搜索入口。原有 `add()`、`addLink()` 用法保持可用，直接按固定顺序调用即可。
