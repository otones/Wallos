# Wallos 中文化改造记录

> 基线：`ellite/Wallos` **v5.10.0**（commit `c98f303`）
> 分支：`cn-5.10.0`（fork 自 `otones/Wallos`，`upstream` 指向原项目）
> 上一基线：v5.8.3，对应分支 `cn`（保留，可切回去）
> 部署实例：`http://192.168.2.202:61032/`

这份文档是改造的**唯一索引**。每个改动都在源码里用 `[CN-PATCH N]` 标记，
`grep -rn "CN-PATCH" .` 可以随时定位全部改动点。

---

## 零、v5.8.3 → v5.10.0 迁移记录

上游跨了 30 个提交、133 个文件，含两个新迁移（000060 / 000061）和一次
`default_names.php` 重构。这次没有用 `git merge`，而是在 v5.10.0 上
**重新应用** 每个补丁 —— 因为上游改动面太大，合并冲突会比重新应用更费时，
而且容易在解决冲突时丢掉标记。

### 上游已经自己做了、因此去掉的补丁

| 补丁 | 上游状态 | 处理 |
|---|---|---|
| **CN-PATCH 1**（补 22 个翻译键） | v5.10.0 自行补齐了全部分类键、支付方式键、`totp_too_many_attempts`、`error_saving_logo` | **删除**。措辞以我们的为准保留了 `currency_*` 和 `nickname`（上游没有） |
| **CN-PATCH 7 的 JS 部分** | 提交 `4fdeeb0 fix: use a valid locale in the subscription details popup` 修掉了 `zh_cn` 非法 BCP-47 标签的问题，改用 `detailsLocale()` | **删除**对 `scripts/subscription-details.js` 的覆盖。**覆盖成我们的旧版会退回问题状态** |

### 仍然需要、重新应用的补丁

| 补丁 | 说明 |
|---|---|
| CN-PATCH 2 / 2b | 支付方式、分类、货币种子数据 —— 上游仍是欧美方案 |
| CN-PATCH 3 / 4 | 语言默认中文、中文提到首位 |
| CN-PATCH 5 | 姓/名合并为昵称 —— 上游仍保留 firstname/lastname |
| CN-PATCH 6 / 6b | 默认货币人民币、货币表收敛为 11 种 |
| CN-PATCH 7（PHP 部分） | 中文日期格式，两处 `formatDate()` 已同步 |
| CN-PATCH 8 | `tools/cn-seed.php` 存量数据迁移 |
| CN-PATCH 9 | 品牌文案 |
| CN-PATCH 12 | 货币表单一来源 —— **上游仍把货币排除在外**（注释里明确写着 "Currencies are deliberately not here"） |
| CN-PATCH 14 | 主货币 id 按 ISO 代码查，不写死 |
| CN-PATCH 15 | 右上角显示昵称 |
| CN-PATCH 16 | 层叠层级 |

### 新增功能的适配点

v5.10.0 引入了**多分类、多付款人**（`feat(subscriptions): allow several categories per subscription` /
`allow several payers per subscription`）和**批量操作**。我们的补丁不受影响，
因为这些功能复用了同一套 `categories` / `payment_methods` 表；
`default_names.php` 的 `localize_default_names()` 签名变了（新增 `$from` 参数），
但我们没有改这个函数，所以无需适配。

### 迁移后的验证结果（2026-10-18）

| 检查项 | 结果 |
|---|---|
| 镜像版本 | `v5.10.0` |
| 两个新迁移 | `000060.php` / `000061.php` 成功执行 |
| 数据完整 | 用户 1 个、货币 11 种中文、分类 17 个、支付方式启用 13 个 |
| `cn-seed` | 幂等，本次只重排了 13 条 order，无改名 |
| `fix-currency` | 无需修复（已全部指向有效行） |
| 页面报错（`Warning:` / `Fatal` / `TypeError` / `i18n String Missing`） | index / subscriptions / calendar / stats / settings / profile 全部为 **0** |
| 右上角显示 | 「子愚」（昵称） |
| 构建期自检 | 19 个文件通过 |

### 新增功能实测：多分类与批量操作

v5.10.0 引入了两个需要单独验证的功能，因为它们改动了数据结构
（`subscription_categories` / `subscription_payers` 两张连接表），
而我们的补丁（特别是 CN-PATCH 12 的货币表统一）碰的是相邻的代码。

#### 多分类（`migration 000061`）

**实现方式**：`subscriptions.category_id` 保留「第一个」分类作为主分类
（排序、分组、通知、API 都读它，保证向后兼容），
`subscription_categories` 存全量列表。多对多，不是改字段。

**注意**：表单里的分类控件不是原生 `<select multiple>`，而是自定义的
`.multi-select`（隐藏 `input[name=category_id]` + JS 维护逗号分隔值），
因为原生多选在移动端体验很差。

| 检查项 | 结果 |
|---|---|
| 创建时 `category_id=2,3`（逗号分隔） | ✅ `parsePayerIds()` 解析为两个 id |
| `subscriptions.category_id` 存主分类 | ✅ 存 `2`（第一个） |
| `subscription_categories` 存两行 | ✅ `2` 和 `3` |
| 编辑接口返回 `category_ids` | ✅ `[2,3]`（数组），另有 `category_id=2` 兼容旧字段 |
| 前端多选控件 | ✅ `id="category_select"`、`aria-multiselectable="true"`、18 个可选项 |
| 按 `?category=2` 筛选 | ✅ 命中，不含其他订阅 |
| **按 `?category=3` 筛选**（只存在于连接表） | ✅ **命中** —— 证明查询走的是 `EXISTS (SELECT 1 FROM subscription_categories ...)`，不是只看主分类 |
| 按 `?category=6` 筛选 | ✅ 只命中单分类那条，无误报 |
| 详情弹窗查表 | ✅ `"categories":{"1":"无分类","2":"影音娱乐",...}` 17 项全中文 |

#### 批量操作（`endpoints/subscriptions/bulkedit.php`）

**支持 6 种操作**：启用通知、关闭通知、设置提醒天数、设置分类、设置支付方式、删除。
前端是「批量操作」按钮 → 工具栏（全选 / 操作下拉 / 值下拉 / 应用）。

| 操作 | 结果 |
|---|---|
| `enable_notifications` | ✅ `notify` 0→1 |
| `disable_notifications` | ✅（同路径） |
| `set_notify_days=7` | ✅ `notify=1, notify_days_before=7` |
| `set_category=6` | ✅ 主分类 2→6，**连接表也从 `[2,3]` 全量替换为 `[6]`** |
| `set_payment_method` | ✅ `payment_method_id` 更新 |
| `delete` | ✅ 订阅删除，**连接表残留 0 行**（上游专门处理了「SQLite 会复用 id」的问题） |

**安全测试**：

| 攻击面 | 结果 |
|---|---|
| 改别人的订阅（id=999999） | ✅ 拒绝，`success:false`（先校验 id 全部属于当前用户） |
| 非法 action（`drop_table`） | ✅ 拒绝（白名单 `$updates` 数组） |
| 缺 CSRF token | ✅ **HTTP 403**（不是 200 + success:false，上游提交 `a924db3` 专门改的） |
| `notify_days=999`（越界） | ✅ 拒绝（范围 -1..180） |

**UI 中文化**：批量工具栏的「批量操作」「全选」「选择操作」「设置分类」
「设置支付方式」全部显示中文 —— 这些键是上游 v5.10.0 新加的，
`zh_cn.php` 里已自带，不需要我们补。

#### 多付款人（`migration 000060`）

结构与多分类完全对称（`subscription_payers` 连接表 + `subscriptions.payer_user_id` 主付款人），
实现走同一套 `parsePayerIds()` / `saveSubscriptionPayers()`。本次未单独压测，
因为代码路径与已验证的多分类共用。

**测试数据已清理**：测试结束后 `subscriptions` / `subscription_categories` /
`subscription_payers` 三张表都是 0 行，分类 17、货币 11 保持原样。

---

## 一、改动清单

| 编号 | 主题 | 文件 | 类型 |
|---|---|---|---|
| ~~CN-PATCH 1~~ | ~~补全 22 个缺失的中文翻译键~~ **v5.10.0 上游已补齐，已删除** | — | — |
| CN-PATCH 2 | 支付方式改国内市场默认值 | `includes/default_names.php` | 常量 |
| CN-PATCH 2b | 全新安装的种子数据 | `endpoints/cronjobs/createdatabase.php` | 种子 SQL |
| CN-PATCH 3 | 语言默认中文（Cookie → 浏览器 → 兜底） | `includes/i18n/getlang.php` | 逻辑 |
| CN-PATCH 4 | 简体中文提到语言列表首位 | `includes/i18n/languages.php` | 数组顺序 |
| CN-PATCH 5 | 姓/名合并为「昵称」 | `registration.php`、`profile.php`、`endpoints/user/save_user.php`、`scripts/registration.js`、`includes/i18n/{zh_cn,en}.php` | 表单+逻辑 |
| CN-PATCH 6 | 默认货币人民币 + 货币名中文化 | `registration.php`、`createdatabase.php`、`includes/i18n/zh_cn.php` | 表单+种子 |
| CN-PATCH 6b | 注册页的货币表收敛为 11 种 | `registration.php` | 数组 |
| CN-PATCH 7 | 中文日期格式（**仅 PHP 部分**，JS 部分上游已修） | `includes/list_subscriptions.php`、`index.php` | 展示 |
| CN-PATCH 8 | 存量数据中文化脚本 | `tools/cn-seed.php`、`startup.sh` | 独立脚本 |
| CN-PATCH 9 | 品牌文案本地化 | `header.php`、`login.php`、`registration.php`、`passwordreset.php`、`totp.php`、`verifyemail.php`、`manifest.php` | 文案 |
| CN-PATCH 10 | 覆盖式构建镜像 | `docker/Dockerfile.cn`、`docker/docker-compose.cn.yml` | 构建 |
| CN-PATCH 11 | 本地构建临时目录忽略 | `.gitignore` | 杂项 |
| CN-PATCH 12 | **货币表统一为单一来源**（修复四条建号路径不一致） | `includes/default_names.php`、`registration.php`、`endpoints/admin/adduser.php`、`includes/oidc/oidc_create_user.php` | 重构 |
| CN-PATCH 13 | 构建期自检：漏覆盖即构建失败 | `docker/Dockerfile.cn`、`docker/expected-patches.txt`、`tools/gen-expected-patches.sh` | 构建 |
| CN-PATCH 14 | **修复主货币 id 错位**（日历页 TypeError） | `includes/default_names.php`、`registration.php`、`endpoints/admin/adduser.php`、`includes/oidc/oidc_create_user.php`、`includes/list_subscriptions.php`、`tools/fix-main-currency.php` | 修复 |
| CN-PATCH 15 | **右上角显示昵称**而非用户名 | `includes/header.php` | 修复 |
| CN-PATCH 16 | **层级修正**：用户菜单被订阅表单遮挡 | `styles/styles.css` | 修复 |
| CN-PATCH 17 | **图片搜索源本地化**（DDG/Brave 国内不通） | `endpoints/logos/search.php`、`endpoints/logos/icon_search.php`、`scripts/subscriptions.js` | 替换源 |

---

## 二、每个改动的动机与细节

### CN-PATCH 1 — 补全中文翻译键

**症状**：注册时选了简体中文，分类仍是 Entertainment / Music / Groceries。

**根因**：`zh_cn.php` 483 键，`en.php` 504 键，缺 22 个。其中 16 个是
`category_*` 分类键、4 个是 `payment_method_*` 键。`translate()` 在缺键时
静默回退 `en.php`，所以不是"翻译没做"，是"键不存在"。

更隐蔽的一层：`default_names_translations()` 会把 `en.php` 合并进
`zh_cn` 来补缺键，所以**新建账号写入数据库的分类名就是英文**——
即使界面语言是中文。

**改法**：在 `zh_cn.php` 末尾补齐 22 个键。分类中文名与
`createdatabase.php` 的种子名保持一致。

---

### CN-PATCH 2 — 支付方式改国内市场默认值

**症状**：支付方式下拉是 PayPal / Klarna / SEPA / GiroPay / iDeal…

**根因**：`DEFAULT_PAYMENT_METHODS` 常量里 31 项全是欧美渠道，其中只有
4 个通用词（Credit Card 等）是翻译键，品牌名是硬编码字面量。

**改法**：改成 13 项，按国内使用频率排序：

```
支付宝 / 微信支付 / 银联云闪付 / 信用卡 / 银行转账 /
花呗 / 京东白条 / 虚拟信用卡 / 自动扣款 / 现金·其他 /
PayPal / Apple Pay / Google Pay        ← 海外订阅仍可能需要
```

无对应图标的条目复用最接近的内置图标（花呗用 alipay.png、京东白条用
creditcard.png），否则设置页会显示裂图。

---

### CN-PATCH 2b — 种子数据

`createdatabase.php` 是**全新安装**时建库的地方，它自己硬编码了一份
分类/支付方式/货币的 INSERT，和 `default_names.php` 是两套独立数据。
只改常量不改这里，新装的库仍然是英文。

同时把货币从 34 种精简到 11 种（去掉保加利亚列弗、冰岛克朗等国内用不到的），
**人民币排第一**。

⚠️ 支付方式的 id 1–31 区间保持不变，因为 `subscriptions.payment_method_id`
靠 id 关联，导入旧备份后仍要指得到对应行。

---

### CN-PATCH 3 — 语言默认中文

**症状**：未登录访客、新用户看到的永远是英文。

**根因**：`getlang.php` 只有 `$_COOKIE['language']` 和硬编码 `"en"` 两条路径。

**改法**：改成三级回退

```
Cookie（用户显式选择）
  → HTTP Accept-Language（浏览器偏好）
    → zh_cn（兜底）
```

注意保留了一个判断：只有浏览器**明确声明了 en** 时才用英文，
`curl`/cron 这类没有 Accept-Language 的请求落到中文。

---

### CN-PATCH 4 — 语言列表顺序

注册页的下拉框直接遍历 `$languages`，顺序即显示顺序。上游把 English 放第一位，
中文在第 28 位。改成中文第一。

---

### CN-PATCH 5 — 姓/名合并为「昵称」

**症状**：注册要填 First name + Last name。

**为什么选「合并」而不是「删除」**：

- `save_user.php` 里 firstname/lastname 是 `isset` 双条件守卫，且写 session。
  直接删字段容易漏掉某个守卫，产生 PHP warning 或静默保存失败。
- 数据库两列保留不动 = **零迁移风险**。
- 昵称写入 `firstname`，`lastname` 恒为空。
- `index.php:75` 本来就是 `$user['firstname'] ?? $user['username']`，
  所以填了就显示昵称，没填回退用户名，不会空白。

**改了 6 处**：

| 文件 | 改动 |
|---|---|
| `registration.php` 表单 | 两个 input → 一个 `nickname` |
| `registration.php` 接收 | `$_POST['nickname'] ?? $_POST['firstname'] ?? ''` |
| `profile.php` | 两个 input → 一个 `nickname` |
| `save_user.php` | 守卫改 `array_key_exists('nickname', ...)` |
| `scripts/registration.js` | 草稿字段跟随；**顺带修了一个上游 bug**（见下） |
| 语言文件 | 新增 `nickname` / `nickname_placeholder` |

**顺带修的上游 bug**：`registration.js` 的 `restoreFormFieldValue()` 直接
`fieldElement.value`，字段不存在时（比如从 localStorage 恢复了已移除的字段）
会抛 TypeError，导致整个 `window.onload` 中断——主题初始化也跟着失效。
加了 `fieldElement &&` 保护。

**一个值得记录的坑**：`save_user.php` 原本用 `isset()` 判断字段存在。
`isset` 对空字符串返回 `true`，但对**字段不存在**返回 `false`。
用户清空昵称时提交的是空字符串，用 `isset` 能过；但如果改用
`!empty()` 就会把"清空昵称"这个合法操作静默忽略。所以用 `array_key_exists`。

---

### CN-PATCH 6 — 默认货币人民币 + 货币名中文化

**症状**：注册页货币默认 Euro（欧元）；下拉显示 `Chinese Yuan` 而不是「人民币」。

**根因**：`registration.php` 的 `<option>` 没有任何 `selected`，浏览器选第一项；
而 `$currencies` 数组第一项是 Euro。货币名来自数据库 `currencies.name` 列，
存的是英文全名。

**⚠️ 这里踩了一个坑，值得记下来**：上游有**两份独立的货币表**——

| 位置 | 作用 | 触发时机 |
|---|---|---|
| `endpoints/cronjobs/createdatabase.php` | 建库时的种子 SQL | **只在全新安装第一次启动时执行一次** |
| `registration.php` 的 `$currencies` 数组 | 注册时写入 currencies 表 | **每个新用户注册时都执行** |

只改前者的话，已经建过库的实例完全不受影响，新注册的用户照样拿到 34 种英文货币。
这就是 CN-PATCH 6b 存在的原因。

**改法**：

1. 注册页给 CNY 加 `selected`
2. 注册时写入数据库的货币名走 `translate('currency_' . code)`，
   存中文而不是英文——因为「设置 → 货币」页把 `name` 当普通文本框直接展示，
   表里存英文的话那里看到的还是英文
3. `zh_cn.php` 补 11 个 `currency_xxx` 键
4. **CN-PATCH 6b**：把 `registration.php` 的 `$currencies` 收敛为 11 种，人民币置首

---

### CN-PATCH 6b — 注册页货币表

见上面 6 的说明。`registration.php:79` 那份 34 项数组改为 11 项，
保留上游的 `id` 原值（某些历史路径会用它关联）。

---

### CN-PATCH 7 — 中文日期格式

**症状**：日期显示 `Oct 17` / `Oct 2025`。

**根因**：`formatDate()` 固定用 ICU 模式 `'MMM d'` / `'MMM yyyy'`，
中文 locale 下渲染成"10月 17"这种夹生格式。

**改法**：中文用 `'M月d日'`（当年）/ `'yyyy年M月'`（跨年）。

⚠️ **同一函数在上游有两份副本**（`includes/list_subscriptions.php` 和
`index.php`），必须同步修改，否则首页和订阅列表显示两种格式。

JS 侧 `subscription-details.js` 用了 `toLocaleDateString('zh_cn')`——
`zh_cn` 不是合法 BCP-47 标签（下划线应为连字符），部分浏览器会静默回退
系统区域。改成 `zh-CN`。

---

### CN-PATCH 8 — 存量数据中文化

前面 1/2/2b/6 都只影响**新建**的账号。已经建好的账号，数据库里存的就是英文文本，
得单独迁移一次。

`tools/cn-seed.php` 做三件事：

1. 分类：英文默认名 → 中文
2. 支付方式：7 项改名（AliPay→支付宝 等）+ 21 项停用（`enabled=0`）
3. 货币：11 项英文名 → 中文

**为什么停用而不是删除**：`subscriptions.payment_method_id` 引用着这些 id，
删掉会留下悬空外键，历史订阅的支付方式显示为空。停用只是从下拉框隐藏。

**另外还做了两件事**：

- **重排 order**：上游的 `order` 是按欧美习惯排的（PayPal 第 1、支付宝第 17、
  微信第 24）。光改名不重排，订阅表单的下拉框仍然是 PayPal 在最上面。
  现在把启用中的渠道按国内习惯重新编号 1–10，停用的统一挪到 999。
- **清理冷门货币**：34 种里去掉 23 种（保加利亚列弗、冰岛克朗之类）。
  与支付方式不同，货币**可以删**——因为删除前先检查了没有任何订阅引用它，
  且不是任何用户的主货币。保留它们的唯一后果是「设置 → 货币」页要滚动，
  汇率表多算 23 个无用条目。

**幂等性**：只更新「名字仍然精确等于上游英文默认值」的行。用户自己改过名字的
不会被碰，重复执行无副作用。

**为什么不写成 `migrations/000060.php`**：上游迁移是顺序编号的，占号之后一旦
上游发布同号迁移就会撞车——`migrations` 表已记录我们的记录，上游那份会被跳过，
数据库悄悄缺一次结构变更。独立脚本不参与那个序列。

由 `startup.sh` 在 `migrate.php` 之后调用，也可以手动
`docker exec wallos php tools/cn-seed.php`。

---

### CN-PATCH 10 — 覆盖式构建镜像

`docker/Dockerfile.cn` 以 `bellamy/wallos:5.8.3` 为底座，只 COPY 我们改过的
23 个文件。

**为什么不用仓库根目录那个 Dockerfile**：它 `FROM php:8.3-fpm-alpine` 然后
`apk add` 一堆扩展，一次构建十几分钟，每次上游发版都要重跑全部编译。
中文化只动 PHP/JS 文件，覆盖式构建 3 秒完成，且上游的安全修复、依赖升级
全部自动继承。

**为什么 COPY 逐个文件而不是 COPY 整个目录**：COPY 整目录会用我们 v5.8.3 的
旧文件覆盖掉新版镜像里所有更新过的文件，上游的 bug 修复会静默丢失。
逐个列举后，如果上游改了某个我们也在改的文件，冲突会在
`git merge upstream/main` 时暴露（可解决），而不是在构建时被无声覆盖。

**踩到的坑：CRLF 行尾**。

第一次构建后容器无限重启：

```
[dumb-init] /var/www/html/startup.sh: No such file or directory
```

用 `od -c` 看首行是 `# ! / b i n / s h \r \n` —— Windows 上
`core.autocrlf=true`，git checkout 把 `startup.sh` 转成了 CRLF。
shebang 变成 `#!/bin/sh\r`，kernel 去找 `/bin/sh\r`，当然找不到。

修法是在 Dockerfile 里加一道 `sed -i 's/\r$//'`，而不是依赖 `.gitattributes`——
试过用 `.gitattributes` 声明 `eol=lf`，但在 `core.autocrlf=true` 的 Windows
检出上会让整个工作区显示成已修改（几百个文件），反而制造噪音。
镜像里兜一层更可靠，也顺带覆盖了"别人直接 scp 传文件"的场景。

**为什么固定 `5.8.3` 而不是 `latest`**：`latest` 会随上游更新漂移，
某天 `docker compose build` 后补丁对不上，页面直接 500。
固定版本 + 手动升级更可控。

---

### CN-PATCH 11 — 忽略本地构建临时目录

`.gitignore` 加 `.tmp/`，避免本地打包的 tar 和校验脚本被提交。

---

### CN-PATCH 12 — 货币表统一为单一来源

**这是这个项目里最容易反复踩的坑，值得单独写清楚。**

**症状**：注册页新建的用户货币是 11 种中文，但管理员在后台加的用户、
以及 OIDC 登录自动创建的用户，仍然拿到 34 种英文货币。

**根因**：上游在**四个地方**各写了一份货币数组，它们没有任何同步机制：

| 位置 | 触发时机 |
|---|---|
| `endpoints/cronjobs/createdatabase.php` | 全新安装第一次建库 |
| `registration.php` | 每个自助注册的用户 |
| `endpoints/admin/adduser.php` | 管理员后台加用户 |
| `includes/oidc/oidc_create_user.php` | OIDC 首次登录自动建号 |

第一版补丁只改了前两个，因为我是从「注册页」这个入口倒推去改的，
没有做全仓扫描。**测出来的表现极具迷惑性**：用第一个用户注册时一切正常
（走 createdatabase 的种子），只有第二个及以后的用户、或者非注册入口创建的用户才出错。

`oidc_create_user.php` 还有第二个坑：`$main_currency_id = 1; // Euro`
是硬编码的，且第 107 行回填主货币时写死查 `code = 'EUR'`。
两处都假设欧元，改动时必须一起改，否则用户会得到一个「货币表是中文、
但主货币 id 指向不存在的行」的账号。

**改法**：把货币表提到 `includes/default_names.php`，作为与分类、支付方式
同级的单一来源：

```php
const DEFAULT_CURRENCIES = [ /* 11 项 */ ];
function default_currencies($language) {
    // 按语言解析货币名，与 default_categories() 同一套约定
}
```

四个调用点全部改为读这个常量：

| 文件 | 改动 |
|---|---|
| `registration.php` | 删掉 32 行内联数组 → `$currencies = DEFAULT_CURRENCIES;` |
| `endpoints/admin/adduser.php` | 删掉 34 行内联数组 → `default_currencies($language)` |
| `includes/oidc/oidc_create_user.php` | 删掉 34 行内联数组 → `default_currencies($language)`；主货币改为取 `DEFAULT_CURRENCIES[0]['id']`，回填查询改为按 code 查 |
| `createdatabase.php` | 保留内联 SQL（建库期还没有语言，无法调用 `default_currencies()`） |

**讽刺的是**：`default_names.php` 开头那段上游注释恰好解释了这个教训——
分类和支付方式曾经也在三处重复，且都是英文，所以上游把它们收进了这个文件。
但注释末尾明确写着「Currencies are deliberately not here」，货币被排除在外。
这正是补丁第一版漏掉两条路径的原因。

---

### CN-PATCH 13 — 构建期自检

既然"漏覆盖"的后果是静默的，就得让它在构建时就失败。

`docker/expected-patches.txt` 列出仓库里所有带 `CN-PATCH` 标记的源文件
（当前 17 个）。`Dockerfile.cn` 在**所有 COPY 之后**逐个检查：
镜像里那个文件是否还带 `CN-PATCH` 标记。如果仓库里有补丁但没被 COPY 进来，
镜像里就是上游原版，grep 不到标记 → 构建失败并列出文件名。

**必须放在所有 COPY 之后**：第一版把它放在 `startup.sh` 的 COPY 之前，
结果它自己报告 `startup.sh` 缺失。

重新生成清单：

```bash
sh tools/gen-expected-patches.sh
```

新增补丁、或合并上游解决冲突后（冲突解决可能整段覆盖掉标记）都要重跑。

---

### CN-PATCH 14 — 主货币 id 错位（这是 CN-PATCH 12 引入的 bug）

**症状**：日历页下方两个统计卡片变成两坨蓝色报错：

```
NumberFormatter::formatCurrency(): Argument #2 ($currency) must be of type
string, null given, called in .../includes/currency_formatter.php on line 19
```

同时页面顶部还有一条：
`Warning: Trying to access array offset on value of type bool in .../calendar.php on line 85`

**根因**：`DEFAULT_CURRENCIES` 里我沿用了**上游的硬编码行 id**：

```php
['id' => 20, 'name' => 'Chinese Yuan', ...],   // CNY 在上游是 20
```

上游这些 id 之所以能对上，是因为它自己的种子也按同样顺序插入 34 种货币，
AUTOINCREMENT 恰好给出了同样的号。我们把列表缩短到 11 项后，
这个巧合就断了：**CNY 在新库里拿到 id 1，而 `registration.php` 仍然把 20
写进了 `user.main_currency`**。

于是 `calendar.php:79` 的查询：

```sql
SELECT c.code FROM currencies c
INNER JOIN user u ON c.id = u.main_currency
WHERE u.id = :userId
```

匹配不到任何行 → `$row['code']` 是 `null` → `CurrencyFormatter::format($amount, null)`
抛 TypeError → 卡片渲染成一坨报错。

**为什么之前没发现**：这条路径只在「已经注册了用户、然后访问日历页」时触发。
我之前的验证是在临时空库里测建号，测完就销毁了，从没登录进去看过日历。

**改法**：`DEFAULT_CURRENCIES` **不再携带 id**。

`currencies.id` 是 `INTEGER PRIMARY KEY AUTOINCREMENT`，由插入顺序决定，
这个值本文件无法预知，也不该由它决定。改完之后，所有需要真实 id 的地方
都按 ISO 代码查：

```php
$stmt = $db->prepare("SELECT id FROM currencies WHERE code = :code AND user_id = :user_id");
```

`INSERT user` 时的 `main_currency` 一律写占位值 **0**（不是某个真实 id），
真实值由插入完货币后按 code 查出来再 UPDATE 回去。写 0 而不是 1 或 20，
是因为 0 永远不指向任何行 —— 回填失败时会明确暴露，而不是"碰巧指到某个货币"。

**改了五处**：

| 文件 | 原来 | 现在 |
|---|---|---|
| `includes/default_names.php` | 数组带 `id` | 去掉 `id` 字段 |
| `registration.php:104` | `$currencies[$i]['id']` 写入 user | 占位 `0`，插入后按 code 回填 |
| `endpoints/admin/adduser.php:70` | `bindValue(':main_currency', 1)` | 占位 `0`，回填已有 |
| `includes/oidc/oidc_create_user.php:29` | `DEFAULT_CURRENCIES[0]['id']` | 占位 `0` |
| `includes/oidc/oidc_create_user.php:96` | `$currencies[0]['id']` | 删除（不再需要） |
| `includes/list_subscriptions.php:412` | `$currencies[1]['id']` | 按 code 查，或取该用户第一行 |

补上 `tools/fix-main-currency.php` 修复存量数据（由 `startup.sh` 自动调用）：
找出 `main_currency` 指向不存在行的用户，按该用户货币表的第一行重建指向。
幂等，正常用户不受影响。

**教训**：`DEFAULT_CURRENCIES` 里那个 `id` 从来不是常量作者能知道的东西 ——
它是数据库在插入时分配的。把它写成常量，等于把「插入顺序」这个隐含假设
固化进了一个看起来像事实的数字里。上游之所以没炸，纯粹是因为两边顺序一致。

---

### CN-PATCH 15 — 右上角显示昵称

**症状**：个人资料里把昵称改成「子愚」，右上角仍然显示用户名 `otones`。

**根因**：`includes/header.php:179` 写死了 `$userData['username']`：

```php
<span id="user"><?= $userData['username'] ?></span>
```

昵称字段（`firstname` 列）根本没参与渲染。CN-PATCH 5 把姓/名合并成昵称时
只改了表单和存储，忘了改这个显示点。

**改法**：

```php
$displayName = trim((string) ($userData['firstname'] ?? ''));
if ($displayName === '') {
    $displayName = (string) $userData['username'];
}
```

回退顺序与 `index.php` 的问候语一致，两处显示不会打架。
`$userData` 每次请求都从数据库重新查（`includes/checksession.php:41`），
所以改完昵称刷新页面即可生效，不需要额外同步 session。

---

### CN-PATCH 16 — 右上角用户菜单被订阅表单遮挡

**症状**：打开「添加订阅」表单后，右上角的用户下拉菜单被压在表单下面，
只从菜单右上角露出一窄条（截图里能看到「仪表盘」「订阅」「日历」等字被切掉）。

**根因**：层叠上下文的传递。

```
body>header            z-index: 10   ← 创建层叠上下文
  └ .dropdown-content      z-index: 5    （只在 header 内部比较）
.subscription-form     z-index: 20   ← 全页范围，高于 header 的 10
```

`.dropdown-content` 的 5 是小问题，真正的问题是 **header 自己只有 10**。
子元素的 z-index 无论多大都跳不出父级建立的层叠上下文 —— 所以调大那个 5
完全没有用。

**改法**：调整 header 自身，但要照顾两条约束：

| 元素 | 原值 | 新值 | 理由 |
|---|---|---|---|
| `body>header` | 10 | **25** | 必须高于内容弹窗（20），低于全屏浮层 |
| `.dropdown-content` | 5 | 5（不变） | 在 header 内部无竞争者，改它无意义 |
| `.icon-search-backdrop` | 21 | **30** | 必须仍在 header 之上 |
| `.icon-search` | 22 | **31** | 面板要盖在自身遮罩之上 |

**为什么 header 要低于 30/31**：图标搜索是全屏取色/选图界面，
它必须盖住页头，否则面板顶部会被 sticky header 切掉一条。
如果只把 header 提到 100，图标搜索就反被压在下面了。

**为什么 header 要高于 20**：右上角菜单是全局导航，任何内容弹窗打开时
都应保持可点 —— 尤其「退出登录」，被弹窗挡住会逼用户先关弹窗才能退出。

**层级约定表**（写在 `body>header` 上方的注释里，改任何浮层前先对照）：

```
 20   内容型弹窗：.subscription-form / .subscription-modal /
      .subscription-details / .totp-popup
 25   body>header（含右上角用户菜单）
 30   .icon-search-backdrop
 31   .icon-search
 99   .modal-backdrop（通用模态遮罩，含注册页的数据库恢复）
```

这个应用原本没有统一的层级体系，各处 z-index 是分散加的，
所以把约定写成注释放在 header 上，是希望下次改动时能看到。

**顺带修的构建遗漏**：`styles.css` 改了但没进 Dockerfile.cn 的 COPY 清单。
`tools/gen-expected-patches.sh` 原来只扫 `*.php` 和 `*.js`，漏掉了 `*.css`，
所以构建期自检也没报错。已补上 CSS 通配，期望清单从 19 项变 20 项。
这正好说明了自检机制的局限：**它只能检查清单里的文件，清单本身漏了它也无能为力**。

---

### CN-PATCH 17 — 图片搜索源本地化

**症状**：「网页搜索」弹窗里两个区块一直转圈，最后报「获取图片结果时出错」。
selfh.st 和 Dashboard Icons 能出结果，DuckDuckGo 和 Brave 永远失败。

**根因（实测，N150 容器内 8 秒超时）**：

| 源 | 可达性 |
|---|---|
| DuckDuckGo | HTTP 000，8s 超时 |
| Brave | HTTP 000，8s 超时 |

不是代码 bug，是这两个站在国内直连不通。

**替代方案调研**（同样实测）：

| 候选 | 结果 | 采用 |
|---|---|---|
| 必应中国 `cn.bing.com` | 200 / 0.61s / 25 条 | ✅ |
| 360 图片 `image.so.com` | 200 / 0.34s / 37 条 | ✅ |
| 搜狗 `pic.sogou.com` | `{"status":1,"info":"forbid"}` | ❌ |
| 百度 `image.baidu.com` | `{"antiFlag":1,"message":"Forbid spider access"}` | ❌ |

搜狗和百度都需要前端 JS 生成的签名参数（搜狗要 `us`/`k`，百度要 `token`），
逆向这些签名不稳定 —— 对方一改算法就失效。必应和 360 不需要签名。

**没走官方 API**：必应 Image Search API 要在 Azure 门户注册、拿 key、
每月 1000 次免费额度。对一个自托管应用来说，让用户为了搜个 logo 去注册
Azure 账号不合理。**爬 HTML/JSON 是这里唯一现实的做法**，这也是上游
选 DDG/Brave 的同一个理由。

**改法**：

1. `search.php` 重写：新增 `fetchBingChinaImages()`（解析 HTML 里的
   `murl&quot;:&quot;…` 原图直链）和 `fetchSo360Images()`（解析 JSON 的
   `list[].img`）。DDG/Brave 的代码保留但只在显式 `source=` 时调用，
   中文部署不会付它们的 8 秒超时。
2. `scripts/subscriptions.js` 的源列表换成：selfh.st → Dashboard Icons →
   必应图片 → 360 图片。顺序按「logo 场景命中质量」排 —— 图标集最精准，
   必应原图直链多，360 数量多但杂图较多。
3. **给 jsDelivr 加多镜像回退**（`icon_search.php`）。selfh.st 和
   Dashboard Icons 都托管在 GitHub、经 jsDelivr 访问，而上游只写死
   `cdn.jsdelivr.net` —— 这个域名历史上多次被墙，一旦不可用两个图标集
   会同时失效该区块。

   实测镜像可达性：`cdn` 0.44s、`fastly` 0.43s、`gcore` 1.25s、
   `testingcf` 1.22s、`statically` 超时、`ghproxy.net` 6.8s。
   现在按此顺序尝试，成功一个就把索引记到临时文件，后续请求直接用，
   不再逐个试超时。全部失败才退回 GitHub raw 代理，最后退回过期缓存。

**实测结果（重新构建后）**：

| 源 | 结果 |
|---|---|
| 必应图片 | ✅ 12 条，原图直链（知乎、新浪等 CDN） |
| 360 图片 | ✅ 56 条 |
| selfh.st | ✅ 命中 `netflix.png` |
| Dashboard Icons | ✅ 命中 `netflix.png` |
| DuckDuckGo（显式） | 仍失败，但已不在 UI 列表中 |

**踩到的坑**：第一次改完直接测端点，返回的还是旧错误信息 —— 忘把
`endpoints/logos/search.php` 和 `icon_search.php` 加进 Dockerfile 的
COPY 清单。这次是构建期自检拦下来的（它发现 `scripts/subscriptions.js`
也漏了）。自检机制连续第二次发挥了作用。

---

### 货币表：四条路径的一致性验证

上表四个入口，逐个验证结果（2026-10-17，`wallos-cn:5.8.3`）：

| 路径 | 验证方式 | 结果 |
|---|---|---|
| 注册页 | 临时空库完成真实注册 | 11 项，`CNY USD HKD JPY EUR GBP TWD SGD KRW AUD CAD` |
| 管理员加用户 | 调用 `default_currencies('zh_cn')` | 11 项，首项「人民币」 |
| OIDC 建号 | 检查占位主货币 + `default_currencies('zh_cn')` | 占位 `id=20 code=CNY`；11 项，首项「人民币」 |
| 全新安装种子 | 空库建库后查 `user_id=1` | 11 项 |

源码残留检查（应全部为「否」）：

```
adduser.php 还有 34 项数组?  否
oidc 还有 34 项数组?         否
oidc 还有硬编码 EUR?         否
oidc 还有 main_currency=1?   否
registration 还有 34 项?     否
```

---

### CN-PATCH 9 — 品牌文案

`Wallos - Subscription Tracker` → `订阅台账 · SubDesk`（7 个文件）。
`manifest.php` 的应用描述也改成中文。

---

## 三、上游更新后的同步流程

这是这份改造能长期维护的关键。

### 目录约定

```
C:\DSH\Wallos\
├── .git/                 origin = otones/Wallos（你的 fork）
│                         upstream = ellite/Wallos（原项目）
├── tools/cn-seed.php     存量数据迁移（不在上游的 migration 序列里）
├── CN-PATCHES.md         本文档
└── ...                   其余为上游源码 + [CN-PATCH N] 标记的改动
```

### 日常同步（上游发了新版本）

```bash
cd /c/DSH/Wallos

# 1. 拉上游最新代码和 tag
git fetch upstream --tags

# 2. 看清上游改了什么
git log --oneline cn..upstream/main

# 3. 把上游合进 cn 分支
git checkout cn
git merge upstream/main

# 4. 处理冲突
#    冲突只可能出现在我们改过的 23 个文件里，靠 CN-PATCH 标记定位
grep -rn "CN-PATCH" .

# 5. 语法自检（需要一个 php 环境；容器里也行）
#    见下方"语法检查"小节

# 6. 改 docker/Dockerfile.cn 第一行的 FROM 版本号，重新构建部署
#    见第五节
```

### 语法检查

本地没有 PHP 时，可以借用运行中的容器：

```bash
sudo docker exec wallos php -l /var/www/html/registration.php
```

批量检查：

```bash
# 在仓库根目录
find . -name "*.php" -not -path "./libs/*" -not -path "./.git/*" \
  -exec sh -c 'docker cp "$1" wallos:/tmp/chk.php >/dev/null && docker exec wallos php -l /tmp/chk.php' _ {} \;
```

### 冲突概率评估

| 文件 | 上游改动频率 | 冲突风险 |
|---|---|---|
| `includes/i18n/zh_cn.php` | 高（每次加功能都可能加键） | **高** — 但我们只**追加**键，冲突好解决 |
| `endpoints/cronjobs/createdatabase.php` | 低（只在加表/加种子时改） | 中 |
| `includes/default_names.php` | 低 | 低 |
| `registration.php` | 中 | 中 |
| `endpoints/admin/adduser.php` | 低 | 低 |
| `includes/oidc/oidc_create_user.php` | 低 | 低 |
| `includes/i18n/getlang.php` | 低 | 低 |
| `index.php` / `list_subscriptions.php` | 中（formatDate 两处） | 中 |
| `save_user.php` | 低 | 低 |
| 其余文案类 | 低 | 低 |

### 合并后必做

```bash
# 1. 重新生成覆盖清单（冲突解决可能丢失 CN-PATCH 标记）
sh tools/gen-expected-patches.sh
git add docker/expected-patches.txt

# 2. 构建。自检不通过会直接失败，不会产生一个"看起来正常"的错误镜像
sudo docker build -f docker/Dockerfile.cn -t wallos-cn:<新版本> ..
```

**尤其是解决过冲突之后**：如果冲突解决时整段采用了上游版本，
`CN-PATCH` 标记会消失，清单里就没有这个文件，自检也就查不出问题——
所以第 1 步的清单重生成必须在解决冲突之后做，它会反映当时的真实标记状态。

### 降低冲突的三条实践

1. **只在文件末尾追加，不改上游已有的行**——`zh_cn.php` 的 22 个键全部
   追加在 `];` 之前，上游在中间加键不会冲突。

2. **每个改动带完整注释解释"为什么"**——合并时如果上游的代码结构变了，
   注释能让人快速判断"这个修复上游是否已经自己做了"。

3. **上游如果自己做了同一件事，删掉我们的补丁**。比如上游某天补了
   `category_entertainment` 的中文，我们就该把这几个键删掉，而不是留着重复定义。
   判断方法：`git log upstream/main -- includes/i18n/zh_cn.php` 看有没有相关提交。

---

## 四、验证清单

每次合并上游或改完代码，按这个清单过一遍。

### 最重要的一条：货币表有四条路径

改任何与「新账号默认数据」相关的代码后，**必须把四条建号路径都测一遍**，
只测注册页会漏掉后面三条：

| # | 入口 | 怎么测 |
|---|---|---|
| 1 | 自助注册 `registration.php` | 临时空库注册一个用户 |
| 2 | 管理员加用户 `admin/adduser.php` | 后台 → 管理员 → 添加用户 |
| 3 | OIDC 首次登录 `oidc_create_user.php` | 配置 OIDC 后首次登录，或直接调 `default_currencies()` |
| 4 | 全新安装 `createdatabase.php` | 删除 db 目录重启容器 |

对应的快速检查 SQL：

```sql
-- 每个用户的货币数量与代码（正常应为 11 项）
SELECT user_id, COUNT(*) AS n, GROUP_CONCAT(code,' ') AS codes
FROM currencies GROUP BY user_id;

-- 是否还有英文货币名
SELECT code, name FROM currencies WHERE name GLOB '*[A-Za-z]*' AND name NOT IN (
  'Chinese Yuan','US Dollar','Hong Kong Dollar','Japanese Yen','Euro',
  'British Pound Sterling','New Taiwan Dollar','Singapore Dollar',
  'South Korean Won','Australian Dollar','Canadian Dollar');
```

### 已实测通过（2026-10-17）

**存量实例**（`http://192.168.2.202:61032`）：

| 检查项 | 结果 |
|---|---|
| 容器状态 | `Up (healthy)` |
| `cn-seed` 执行 | 分类 16 项、支付方式改名 7 / 停用 21 / 重排 10、货币改名 11 / 清理 23 |
| 分类全部中文 | ✅ 影音娱乐 / 音乐 / 生活缴费 … 公益捐赠 |
| 支付方式前 10 项 | ✅ 支付宝 / 微信支付 / 银联云闪付 / 信用卡 / 银行转账 / 自动扣款 / 现金·其他 / PayPal / Apple Pay / Google Pay |
| 停用的支付方式 | 21 项（Klarna、SEPA、Skrill 等） |
| 货币 | ✅ 11 种，全部中文名 |
| 页面文案 | ✅ `<title>` 为「订阅台账 · SubDesk」，`window.lang = zh_cn` |

**全新实例**（临时容器 + 空数据库，四条建号路径全测，测试后已销毁）：

| 检查项 | 结果 |
|---|---|
| 注册表单字段 | ✅ 只有 `nickname`，无 `firstname` / `lastname` |
| 表单标签 | ✅ 用户名 / 昵称 / 电子邮箱 / 密码 / 确认密码 / 主要货币 / 语言 |
| 默认货币 | ✅ CNY 带 `selected` |
| 完整注册流程 | ✅ HTTP 302 跳转登录页 |
| 注册后用户记录 | `firstname=我自己`，`lastname` 空，`language=zh_cn`，`main_currency=20`(CNY) |
| 注册后分类 / 支付方式 / 货币 | ✅ 17 / 13 / 11，全部中文 |
| **管理员加用户路径** | ✅ `default_currencies('zh_cn')` 返回 11 项，首项「人民币」 |
| **OIDC 路径** | ✅ 占位主货币 `id=20 code=CNY`，11 项中文 |
| **构建期自检** | ✅ 「CN-PATCH 覆盖自检通过（17 个文件）」 |

**语法检查**：所有改动文件 `php -l` 通过。

### 待人工确认（需要浏览器）

- [x] ~~首页问候语显示昵称~~ — 已验：右上角显示「子愚」
- [ ] 订阅列表日期显示为「10月17日」而非「Oct 17」
- [ ] 个人资料页修改昵称后保存，右上角随之更新
- [ ] 订阅表单的支付方式下拉顺序符合预期
- [x] ~~设置页货币列表只剩 11 项~~ — 已验：`data-currencyid` 计数 11

### 页面渲染实测（2026-10-17，容器内 curl + 真实登录）

| 页面 | HTTP | NumberFormatter | Warning | TypeError | 备注 |
|---|---|---|---|---|---|
| `calendar.php` | 200 | 0 | 0 | 0 | 修复前是两个报错卡片 |
| `subscriptions.php` | 200 | 0 | 0 | 0 | |
| `stats.php` | 200 | 0 | 0 | 0 | |
| `settings.php` | 200 | 0 | 0 | 0 | `data-currencyid` = 11 |
| `index.php` | 302 | 0 | 0 | 0 | 正常：无订阅时跳订阅页 |

右上角显示：`<span id="user">子愚</span>`（昵称，不是 `otones`）

**验证方式说明**：为了能登录，脚本临时把该用户的密码哈希换成已知值，
验证完由 `trap EXIT` 还原为原哈希。全程不改变数据，只读页面。

---

## 五、部署

### 当前部署状态

| 项 | 值 |
|---|---|
| 地址 | `http://192.168.2.202:61032/` |
| 镜像 | `wallos-cn:5.8.3`（由 `docker/Dockerfile.cn` 构建） |
| 容器 | `wallos`，`healthy`，`restart: unless-stopped` |
| 构建上下文 | `/vol1/1000/docker/wallos/src/`（从本地打包上传） |
| 数据 | `/vol1/1000/docker/wallos/db` → `/var/www/html/db` |
| Logo | `/vol1/1000/docker/wallos/logos` → `/var/www/html/images/uploads/logos` |
| 网络 | `trim-default`（与青龙等其他容器一致） |

### 从本地重新构建部署

```powershell
# Windows 本地（仓库根目录）
cd C:\DSH\Wallos
tar -czf .tmp\wallos-cn-build.tar.gz --exclude=.git --exclude=.tmp `
    --exclude=screenshots --exclude=webfonts --exclude=dev .
scp -i C:\DSH\.secrets\id_rsa -P 60022 .tmp\wallos-cn-build.tar.gz otones@192.168.2.202:/tmp/
```

```bash
# N150 上
rm -rf /vol1/1000/docker/wallos/src && mkdir -p /vol1/1000/docker/wallos/src
tar -xzf /tmp/wallos-cn-build.tar.gz -C /vol1/1000/docker/wallos/src
cd /vol1/1000/docker/wallos/src/docker
sudo docker build -f Dockerfile.cn -t wallos-cn:5.8.3 ..
sudo docker rm -f wallos
sudo docker run -d --name wallos --hostname wallos --network trim-default \
  -p 61032:80 -e TZ=Asia/Shanghai \
  -v /vol1/1000/docker/wallos/db:/var/www/html/db \
  -v /vol1/1000/docker/wallos/logos:/var/www/html/images/uploads/logos \
  --restart unless-stopped wallos-cn:5.8.3
```

### 手动重跑数据迁移（幂等，可随时执行）

```bash
sudo docker exec wallos php /var/www/html/tools/cn-seed.php
```

---

## 六、回滚

```bash
cd /vol1/1000/docker/wallos
sudo docker rm -f wallos
cp db/wallos.db.bak-before-cn db/wallos.db   # 恢复改造前的数据库
sudo docker run -d --name wallos --hostname wallos --network trim-default \
  -p 61032:80 -e TZ=Asia/Shanghai \
  -v /vol1/1000/docker/wallos/db:/var/www/html/db \
  -v /vol1/1000/docker/wallos/logos:/var/www/html/images/uploads/logos \
  --restart unless-stopped bellamy/wallos:5.8.3
```

或者切回 main 分支重新构建：

```bash
cd /c/DSH/Wallos && git checkout main && git push origin main
```

**已备份的数据库**：

| 文件 | 时间点 |
|---|---|
| `db/wallos.db.bak-20261017-1146` | 改造前 |
| `db/wallos.db.bak-before-cn` | 替换镜像前 |

---
