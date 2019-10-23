# Blogger / Bangumi 小组论坛

一个不依赖任何 PHP 框架与 Composer 的**Bangumi 小组讨论论坛的简单实现**。

功能包括：用户创建小组、在组内发表主题与回复，站点管理员审核小组公开申请、管理用户与内容。

界面沿用 Bangumi 番组计划的视觉风格（`assets/bangumi.min.css`）。

- **使用的框架**：原生 PHP（`declare(strict_types=1)`）+ MySQL 5.7+ / InnoDB + JavaScript
- **数据访问**：PDO 预处理语句（`ATTR_EMULATE_PREPARES = false`）
- **Markdown**：渲染器（`app/markdown.php`），先转义再生成标记，支持标题/强调/删除线/代码块/行内代码/链接/图片/列表/引用/表格/分隔线
- **语言**：全部界面与提示文案为简体中文。
- **时区**：固定为 `Asia/Shanghai`

---

## 目录

- [功能一览](#功能一览)
- [环境要求](#环境要求)
- [快速开始](#快速开始)
- [配置项](#配置项)
- [目录结构](#目录结构)
- [页面与操作入口](#页面与操作入口)
- [权限模型](#权限模型)
- [核心机制](#核心机制)
- [数据库结构](#数据库结构)
- [检查脚本](#检查脚本)
- [部署与安全](#部署与安全)

---

## 功能一览

**小组**

- 创建小组（名称、简介、图标）
- 小组的可见性：`public` 公开 · `pending` 公开审核中 · `private` 私有（默认） · `rejected` 公开未通过
- 公开小组可直接加入、私有小组需提交加入申请，由组长/组内管理员审批
- 成员状态：`pending` 待审核、`approved` 已加入、`rejected` 已拒绝、`blocked` 已移出（被移出后无法再次申请，可解除限制）
- 组内角色：组长（owner）、组内管理员（moderator）、成员（member）
- 组长可编辑资料、提交/撤回公开申请、任命组内管理员、转交组长权限、删除小组
- 删除为软删除，站点管理员可在回收站恢复或彻底删除

**讨论**

- 主题支持置顶、锁定（锁定后仅管理人员可编辑），回复支持引用（`parent_id`）
- 主题/回复支持编辑与软删除、列表按最后活动时间排序，支持分页（每页 20 条）
- 主题页支持顺序/倒序浏览与「只看楼主」
- 编辑器提供 Markdown 工具栏、`Ctrl+Enter` 快捷提交与**服务端渲染的实时预览**（`preview.php`，与会话已登录 + CSRF 校验）

**收件箱（站内通知）**

- 每条事件为每个接收者生成一行记录，收到回复、被引用、主题被编辑/删除、加入申请结果、角色变更、账号状态变更、密码被重置等都会通知
- 分类：回复、引用、主题、小组、成员、审核、账号、系统
- 支持全部/未读/已读筛选、单条已读/删除、全部已读、批量删除已读
- 表不存在时自动按需建表（`inbox_ensure_schema()`），已上线的旧站点无需手动迁移
- 徽标计数失败不会影响页面渲染

**站点管理（管理中心）**

- 公开审核：通过（同时放行该组所有待审核加入申请）或拒绝，可附审核说明
- 小组管理、用户管理（停用/启用、设为管理员/降为普通用户、重置临时密码）
- 内容管理、回收站（恢复/彻底删除小组、主题、回复）、操作记录（审计日志）与清理过期登录记录

**账号**

- 注册、登录（用户名或邮箱）、登出
- 昵称/签名/头像/邮箱/密码修改
- 登录失败限流：同一身份 15 分钟内 8 次、同一 IP 15 分钟内 30 次
- 改密码或管理员操作会递增 `session_version`，使其他会话立即失效

---

## 环境要求

| 项目 | 要求 |
| --- | --- |
| PHP | 7.4 及以上 |
| 扩展 | `pdo_mysql`（必需）、`fileinfo`（推荐，用于二次校验上传图片）、`mbstring`（可选，缺失时 `utf8_lower()` 自动回退到 `strtolower()`）、`session` |
| MySQL | 5.7+ / 8.0，InnoDB，`utf8mb4` |
| Web 服务器 | Apache（含 `mod_rewrite`）或 Nginx + PHP-FPM，项目根目录作为站点根 |
| Node.js | 仅 `scripts/static_audit.js` 需要，非运行依赖 |

本项目已在 Windows + PHPStudy 环境（Apache / Nginx + MySQL 5.7.26）下开发。

---

## 快速开始

1. **创建数据库**：

   ```sql
   CREATE DATABASE blogger DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   ```

2. **创建配置**：复制 `config/local.example.php` 为 `config/local.php`，填入数据库连接与安装密钥：

   ```php
   return [
       'DB_HOST' => '127.0.0.1',
       'DB_PORT' => '3306',
       'DB_NAME' => 'blogger',
       'DB_USER' => 'blogger',
       'DB_PASS' => '你的数据库密码',
       'APP_ENV'  => 'production',
       'APP_DEBUG' => '0',
       'APP_URL' => '',        // 子目录部署时填写，如 /blogger；留空自动识别
       'INSTALL_TOKEN' => '至少24位的随机字符串',
   ];
   ```

3. **确认目录可写**：Web 服务器用户需要能写入 `assets/uploads/group/` 与 `assets/uploads/avatar/`（上传图标与头像）。

4. **执行安装**：浏览器访问 `install.php`，填写安装密钥 + 管理员账号信息，系统会导入 `database/schema.sql` 并创建 **UID 1 的站长账号**（`role = admin`）。安装使用 MySQL 命名锁（`GET_LOCK`）防止并发重复初始化。

5. **锁定安装入口**：`users` 表非空后 `install.php` 自动返回 403；随后请清空或轮换 `config/local.php` 中的 `INSTALL_TOKEN`。

6. 访问 `index.php` 开始使用。如需脚本化部署，也可直接导入 `database/schema.sql` 后手动插入管理员账号（`password_hash` 使用 `PASSWORD_BCRYPT`）。

> 注意：`APP_DEBUG=1` 仅在 `APP_ENV` 为 `local`/`development`/`dev` 时才会输出详细错误信息。

---

## 配置项

配置按 **环境变量优先，其次 `config/local.php`，最后默认值** 的顺序解析（`config/config.php` 中的 `config_value()`）。环境变量统一带 `BLOGGER_` 前缀，例如 `BLOGGER_DB_PASS`。

| 键 | 默认值 | 说明 |
| --- | --- | --- |
| `DB_HOST` | `127.0.0.1` | MySQL 主机 |
| `DB_PORT` | `3306` | MySQL 端口 |
| `DB_NAME` | `blogger` | 数据库名 |
| `DB_USER` | `root` | 数据库用户 |
| `DB_PASS` | 空 | 数据库密码 |
| `APP_NAME` | `Bangumi` | 站点名称，用于标题与页脚 |
| `APP_URL` | 空 | 站点基址；留空时由 `SCRIPT_NAME` 自动推断（适配子目录部署） |
| `APP_ENV` | `production` | 运行环境；`local`/`development`/`dev` 才允许调试输出 |
| `APP_DEBUG` | `0` | 与 `APP_ENV` 同时满足时，异常页显示原始错误信息 |
| `INSTALL_TOKEN` | 空 | 安装密钥，**至少 24 位**，否则 `install.php` 直接 403 |
| `SESSION_NAME` | `blogger_session` | 会话 Cookie 名 |

固定常量（见 `app/helpers.php`）：`SITE_OWNER_ID = 1`、上传图片类型 `jpg/png/gif/webp`、单张上限 2 MB、`GROUP_IMAGE_DIRECTORY` / `USER_AVATAR_DIRECTORY` 及目录下默认图。

---

## 目录结构

```
blogger/
├── index.php              小组首页（随便看看 / 所有小组 / 参加的小组 + 最新话题）
├── group.php              小组页（讨论列表 / 成员列表 / 加入与退出）
├── topic.php              主题页（正文、回复、引用、排序、主题管理面板）
├── new_topic.php          发表新主题
├── edit.php               编辑主题或回复
├── create_group.php       创建小组
├── group_manage.php       小组管理（资料、公开申请、成员审批、转交、删除）
├── user.php               个人主页（公开可见的话题列表）
├── settings.php           账号设置（昵称、头像、签名、邮箱、密码）
├── inbox.php              收件箱（列表 + 已读/删除等 POST 操作）
├── preview.php            Markdown 实时预览（仅 POST，登录 + CSRF）
├── login.php / register.php
├── install.php            首次安装（导入表结构 + 创建站长）
├── auth.php               登录、注册、登出处理
├── actions.php            小组/主题/回复的全部写操作
├── admin.php              管理中心（公开审核/小组/用户/内容/回收站/操作记录）
├── admin_actions.php      管理中心的写操作
├── app/
│   ├── bootstrap.php      入口引导：配置、异常处理、安全响应头、会话启动
│   ├── db.php             PDO 单例与 db_transaction() 事务封装
│   ├── helpers.php        转义、UTF-8、URL/跳转、Flash、CSRF、登录态、权限、上传、分页、审计
│   ├── layout.php         页头/页脚与图片上传字段渲染
│   ├── markdown.php       零依赖 Markdown 子集渲染器
│   └── inbox.php          通知存储、分发（audit_notify）与读写查询
├── config/
│   ├── config.php         常量定义与环境变量/本地配置读取
│   └── local.example.php  本地配置模板（复制为 local.php）
├── database/schema.sql    全部表结构
├── assets/
│   ├── bangumi.min.css    站点基础样式（Bangumi 风格，含明暗主题变量）
│   ├── app.css            本项目组件样式（面板、通知、Markdown、工具栏、响应式）
│   ├── app.js             data-confirm 确认、图片预览与前端校验、Ctrl+Enter 提交
│   ├── markdown.js        Markdown 工具栏 + 实时预览（渐进增强）
│   ├── images/            站点自带图片（默认小组图标、默认头像、Logo、背景等）
│   ├── picker/            预留图片选择器目录
│   └── uploads/           运行时上传（group/ 小组图标、avatar/ 头像），含禁用脚本执行的 .htaccess
├── deploy/nginx.conf.example  Nginx 部署片段
├── scripts/
│   ├── markdown_check.php Markdown 渲染器冒烟测试（纯源码，不连数据库）
│   └── static_audit.js    静态审计：CSRF 表单、表结构、路由与动作齐全性
└── .htaccess / .gitignore
```

---

## 页面与操作入口

所有 PHP 页面都通过 `require_once __DIR__ . '/app/bootstrap.php'` 启动，共享配置、异常处理、会话与安全响应头。

| 页面 | 方法 | 关键参数 | 权限 |
| --- | --- | --- | --- |
| `index.php` | GET | `view=discover\|all\|mine`、`q`、`page`、`topic_page` | `mine` 需登录 |
| `group.php` | GET | `id`、`tab=members`、`page` | 私有小组需成员/管理者 |
| `topic.php` | GET | `id`、`sort=asc\|desc`、`author=1`、`page`、`reply_to` | 需 `group_access()` |
| `user.php` | GET | `id`（缺省为本人）、`page` | 公开 |
| `settings.php` | GET/POST | 昵称、头像、签名、邮箱、当前密码、新密码 | 登录 |
| `inbox.php` | GET/POST | `tab=all\|unread\|read`、`page`；POST `action` | 登录 |
| `preview.php` | POST | `content`、`_csrf` | 登录 + CSRF |
| `install.php` | GET/POST | `install_token` + 管理员信息 | 仅未初始化且令牌 ≥ 24 位 |
| `admin.php` | GET | `tab=requests\|groups\|users\|content\|trash\|logs`、`page`、`content_page` | `require_admin()` |
| `group_manage.php` | GET | `id`、`page` | 组长/组内管理员/站点管理员 |

**`auth.php` 的 `action`**：`login`、`register`、`logout`（POST + CSRF）

**`actions.php` 的 `action`**（POST + CSRF + 登录）：
`create_group`、`join_group`、`leave_group`、`request_public`、`update_group`、`make_private`、`delete_group`、`manage_membership`、`remove_member`、`unblock_member`、`member_role`、`transfer_group`、`create_topic`、`edit_topic`、`delete_topic`、`moderate_topic`、`reply_topic`、`edit_reply`、`delete_reply`
（列表外的动作一律返回 400，避免动作字符串被当作路径处理。）

**`admin_actions.php` 的 `action`**：`review_public`、`user_status`、`user_role`、`reset_password`、`restore_group`、`restore_topic`、`restore_reply`、`purge_group`、`purge_topic`、`purge_reply`、`clean_auth_attempts`、`reset_logs`

> 写操作全部走 POST：页面渲染只负责读，所有状态变更集中在这四个入口文件，便于统一鉴权与审计。

---

## 权限模型

判定逻辑集中在 `app/helpers.php`：

| 函数 | 含义 |
| --- | --- |
| `current_user()` / `require_login()` / `require_admin()` | 当前登录用户（校验 `status='active'` 与 `session_version`）；强制登录；强制站点管理员 |
| `is_site_owner($user)` | 是否为 **UID 1 的站长**；其他管理员不能将其降级、停用或重置密码（仅可「恢复」为管理员/启用） |
| `group_is_owner($group, $user)` | 组长或站点管理员 |
| `group_is_manager($group, $user)` | 组长、站点管理员或组内管理员（`role='moderator'` 且已批准） |
| `group_can_write($group, $user)` | 管理者或已批准成员 —— 决定能否发帖/回复 |
| `group_access($group, $user)` | 公开小组对所有人开放；否则仅管理者与已批准成员可读 |

其他内置保护：不能在当前登录的管理中心操作自己；系统始终保留至少一个有效管理员（并发降级通过先锁定全部管理员行来避免）；不能通过成员管理修改现任组长；被移出（`blocked`）的用户无法重新申请。

---

## 核心机制

### 请求生命周期

`app/bootstrap.php` 依次加载配置 → 数据库 → 助手函数 → Markdown → 收件箱，然后：

1. 设置时区、关闭 `display_errors`，注册全局异常处理器；
2. 输出安全响应头：`X-Content-Type-Options: nosniff`、`X-Frame-Options: SAMEORIGIN`、`Referrer-Policy: same-origin`、`Cache-Control: no-store`；
3. 以 `session.use_strict_mode=1`、`HttpOnly` + `SameSite=Lax`（HTTPS 下自动 `secure`）启动会话。

异常处理区分两类：`DomainException` 作为**业务校验失败**返回 422 并展示中文原因；其他异常返回 503 并分配请求编号，仅当 `APP_DEBUG` 且 `APP_ENV` 为本地时显示原始信息，避免泄露凭据与路径。对 `PDOException` 还会把错误码翻译成可执行的排查建议（1045/1049/2002/42S02 等）。

### 图片上传

统一走 `image_upload()`：校验上传错误码、`is_uploaded_file()`、文件大小（≤ 2 MB），用 `getimagesize()` 判定真实 MIME，并在 `finfo` 可用时二次确认。**扩展名取自检测出的类型，绝不使用上传文件名**；文件名形如 `group-<24位随机十六进制>.jpg`。读取路径时 `image_path()` 用白名单正则再次校验，越界或消失的文件回退到默认图标。替换/失败时会删除残留文件（事务提交后再删除被替换的旧图）。

### Markdown 渲染

`markdown_to_html()` 先整体 HTML 转义，再生成标记，因此用户输入不可能注入原始 HTML。链接与图片 URL 会先解码实体再校验协议，仅允许 `http(s)`（图片）/ `http(s)`、`mailto`（链接），`javascript:`、`data:`、`vbscript:`、`file:` 等一律拒绝。内联与块的递归深度均有限制，长标记串、大量括号等病态输入不会触发放大或回溯爆炸（详见 `scripts/markdown_check.php` 中的极端用例）。

### 通知与审计

- `audit($action, $user, $type, $id)` 写入 `audit_logs`；
- `audit_notify(...)` 在写审计的同时按事件类型分发站内通知（`app/inbox.php`），例如新回复通知主题作者、引用的回复额外通知被引用者、管理员编辑/删除内容通知原作者；
- 通知内容经 Markdown 转为纯文本并按单行截断（`inbox_body_line()`），避免 `##`、`>` 等在通知里影响阅读；行为者本人不会收到自己触发的通知；
- 通知不做物理删除：`read_at` 标记已读，`deleted_at` 对本人隐藏。

### 事务与并发

`db_transaction()` 包裹写操作并在异常时回滚。`actions.php` 对小组与主题行使用 `FOR UPDATE`，把权限判断与状态变更放在同一把锁内，避免「审批/移出/转交」等状态转换被并发请求穿透；`admin_actions.php` 审核公开申请时会同时锁定小组与申请行。

---

## 数据库结构

全部表定义见 `database/schema.sql`（`utf8mb4` / `utf8mb4_unicode_ci` / InnoDB，`CREATE TABLE IF NOT EXISTS` 可重复执行）。

| 表 | 说明 | 关键约束 |
| --- | --- | --- |
| `users` | 账号、昵称、签名、头像、角色、状态、`session_version` | `username`、`email` 唯一 |
| `discussion_groups` | 小组资料、`visibility_status`、软删除 `deleted_at` | `slug` 唯一；`owner_id` → `users` |
| `group_members` | 成员关系与 `role`/`status` | 主键 `(group_id, user_id)`；级联删除 |
| `group_public_requests` | 公开申请与审核结果（`status`、`review_note`、`reviewed_by`） | 关联小组与申请人 |
| `topics` | 主题标题、正文、`is_pinned`、`is_locked`、`deleted_at` | 关联小组、作者 |
| `replies` | 回复正文、`parent_id`（引用）、`deleted_at` | 关联主题、作者 |
| `audit_logs` | 操作记录：`actor_id`、`action`、`target_type`、`target_id` | 按时间索引 |
| `notifications` | 站内通知：`kind`、`title`、`body`、`target_*`、`read_at`、`deleted_at` | 绑定接收者与触发者 |
| `auth_attempts` | 登录/注册尝试的 `identity_hash`、`ip_hash` | 按身份与 IP 索引，仅存哈希 |

内容删除均为软删除（`deleted_at`）；`purge_*` 才执行物理删除，届时依赖外键级联清理从属数据，并解除被删回复的 `parent_id` 引用。

---

## 检查脚本

两个脚本都是**纯源码检查**，不会启动 PHP 服务、不连接数据库、不发 HTTP 请求：

```bash
# Markdown 渲染器测试
php scripts/markdown_check.php

# 静态审计测试
node scripts/static_audit.js
```

`static_audit.js` 会校验：
- 所有 POST 表单都含 `csrf_field()`；9 张表都在 schema 中；
- 小组默认为私有；
- `notifications` 含 `read_at`/`deleted_at` 与外键；
- `inbox.php` 保留四个动作并要求登录；
- `actions.php` / `admin_actions.php` 的关键动作与站长保护逻辑仍存在。两者输出 `OK` 即通过，失败以非 0 退出码结束，适合接入 CI。

---

## 部署与安全

**Apache**：仓库自带根 `.htaccess`（`mod_rewrite` + `AllowOverride` 需开启）
- 关闭目录索引
- 拒绝访问 `app|config|database|deploy|scripts|tests|.git|.agents|.codex|.aws` 目录
- 拒绝 `AGENTS.md`/`README.md`、拒绝隐藏文件
- **禁止执行 `assets/uploads/` 下任何脚本**（`.htaccess`）

**Nginx**：参考 `deploy/nginx.conf.example` 把对应 `location` 片段插入站点 `server` 块。

子目录（如 `/blogger`）部署时需为所有 location 与重写目标加上前缀。PHP-FPM 的 `location ~ \.php$` 需存在于父级 `server` 块中。

**上线前检查清单**

- `config/local.php` 的权限，安装完成后清空 `INSTALL_TOKEN`
- `APP_ENV=production`、`APP_DEBUG=0`（生产环境请勿开启调试）
- 生产环境启用 HTTPS，会话 Cookie 会自动带上 `secure`
- 确保上传目录不可执行脚本，且仅 Web 用户可写
- 定期清理 `auth_attempts`（管理中心「操作记录」页提供一键清理）

**已内置的安全措施**：
- 全部 SQL 使用预处理语句且禁用模拟预处理
- 所有 POST 经 CSRF 令牌校验（`hash_equals` 恒时比较）
- 登录/注册成功与改密后重新生成会话 ID
- 密码使用 `bcrypt` 且限制 8–72 字节（保留首尾空格，不做 trim）
- 输出统一经 `e()` 转义；`session_version` 让停用/改密/改角色的旧会话立即失效
- 删除等危险操作需要 `data-confirm` 二次确认，删除小组还需输入名称
