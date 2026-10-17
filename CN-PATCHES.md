# Wallos 中文化改造记录

> 基线：`ellite/Wallos` **v5.8.3**（commit `cc9677a`）
> 分支：`cn`（fork 自 `otones/Wallos`，`upstream` 指向原项目）
> 部署实例：`http://192.168.2.202:61032/`

这份文档是改造的**唯一索引**。每个改动都在源码里用 `[CN-PATCH N]` 标记，
`grep -rn "CN-PATCH" .` 可以随时定位全部改动点。

---

## 一、改动清单

| 编号 | 主题 | 文件 | 类型 |
|---|---|---|---|
| CN-PATCH 1 | 补全 22 个缺失的中文翻译键 | `includes/i18n/zh_cn.php` | 新增键 |
| CN-PATCH 2 | 支付方式改国内市场默认值 | `includes/default_names.php` | 常量 |
| CN-PATCH 2b | 全新安装的种子数据 | `endpoints/cronjobs/createdatabase.php` | 种子 SQL |
| CN-PATCH 3 | 语言默认中文（Cookie → 浏览器 → 兜底） | `includes/i18n/getlang.php` | 逻辑 |
| CN-PATCH 4 | 简体中文提到语言列表首位 | `includes/i18n/languages.php` | 数组顺序 |
| CN-PATCH 5 | 姓/名合并为「昵称」 | `registration.php`、`profile.php`、`endpoints/user/save_user.php`、`scripts/registration.js`、`includes/i18n/{zh_cn,en}.php` | 表单+逻辑 |
| CN-PATCH 6 | 默认货币人民币 + 货币名中文化 | `registration.php`、`createdatabase.php`、`includes/i18n/zh_cn.php` | 表单+种子 |
| CN-PATCH 6b | 注册页的货币表收敛为 11 种 | `registration.php` | 数组 |
| CN-PATCH 7 | 中文日期格式 | `includes/list_subscriptions.php`、`index.php`、`scripts/subscription-details.js` | 展示 |
| CN-PATCH 8 | 存量数据中文化脚本 | `tools/cn-seed.php`、`startup.sh` | 独立脚本 |
| CN-PATCH 9 | 品牌文案本地化 | `header.php`、`login.php`、`registration.php`、`passwordreset.php`、`totp.php`、`verifyemail.php`、`manifest.php` | 文案 |
| CN-PATCH 10 | 覆盖式构建镜像 | `docker/Dockerfile.cn`、`docker/docker-compose.cn.yml` | 构建 |
| CN-PATCH 11 | 本地构建临时目录忽略 | `.gitignore` | 杂项 |
| CN-PATCH 12 | **货币表统一为单一来源**（修复四条建号路径不一致） | `includes/default_names.php`、`registration.php`、`endpoints/admin/adduser.php`、`includes/oidc/oidc_create_user.php` | 重构 |
| CN-PATCH 13 | 构建期自检：漏覆盖即构建失败 | `docker/Dockerfile.cn`、`docker/expected-patches.txt`、`tools/gen-expected-patches.sh` | 构建 |

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

- [ ] 首页问候语显示昵称（或回退用户名）
- [ ] 订阅列表日期显示为「10月17日」而非「Oct 17」
- [ ] 个人资料页只有昵称一个字段，修改能保存
- [ ] 订阅表单的支付方式下拉顺序符合预期
- [ ] 设置页货币列表只剩 11 项

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
