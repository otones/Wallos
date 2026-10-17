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
| CN-PATCH 7 | 中文日期格式 | `includes/list_subscriptions.php`、`index.php`、`scripts/subscription-details.js` | 展示 |
| CN-PATCH 8 | 存量数据中文化脚本 | `tools/cn-seed.php`、`startup.sh` | 独立脚本 |
| CN-PATCH 9 | 品牌文案本地化 | `header.php`、`login.php`、`registration.php`、`passwordreset.php`、`totp.php`、`verifyemail.php`、`manifest.php` | 文案 |

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

**改法**：

1. 注册页给 CNY 加 `selected`（`createdatabase.php` 已把人民币排第一，双保险）
2. 注册时写入数据库的货币名走 `translate('currency_' . code)`，
   存中文而不是英文——因为「设置 → 货币」页把 `name` 当普通文本框直接展示，
   表里存英文的话那里看到的还是英文
3. `zh_cn.php` 补 11 个 `currency_xxx` 键

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

**幂等性**：只更新「名字仍然精确等于上游英文默认值」的行。用户自己改过名字的
不会被碰，重复执行无副作用。

**为什么不写成 `migrations/000060.php`**：上游迁移是顺序编号的，占号之后一旦
上游发布同号迁移就会撞车——`migrations` 表已记录我们的记录，上游那份会被跳过，
数据库悄悄缺一次结构变更。独立脚本不参与那个序列。

由 `startup.sh` 在 `migrate.php` 之后调用，也可以手动
`docker exec wallos php tools/cn-seed.php`。

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
#    冲突只可能出现在我们改过的 19 个文件里，靠 CN-PATCH 标记定位
grep -rn "CN-PATCH" .

# 5. 语法自检
docker run --rm -v "$PWD":/app -w /app php:8.3-cli \
  sh -c 'find . -name "*.php" -newer .git/HEAD -exec php -l {} \;'

# 6. 构建并替换
docker compose build && docker compose up -d
```

### 冲突概率评估

| 文件 | 上游改动频率 | 冲突风险 |
|---|---|---|
| `includes/i18n/zh_cn.php` | 高（每次加功能都可能加键） | **高** — 但我们只**追加**键，冲突好解决 |
| `endpoints/cronjobs/createdatabase.php` | 低（只在加表/加种子时改） | 中 |
| `includes/default_names.php` | 低 | 低 |
| `registration.php` | 中 | 中 |
| `includes/i18n/getlang.php` | 低 | 低 |
| `index.php` / `list_subscriptions.php` | 中（formatDate 两处） | 中 |
| `save_user.php` | 低 | 低 |
| 其余文案类 | 低 | 低 |

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

每次合并上游或改完代码，按这个清单过一遍：

- [ ] `php -l` 全部通过
- [ ] 注册页：语言默认简体中文、货币默认人民币、只有「昵称」没有姓/名
- [ ] 注册后：分类和支付方式是中文
- [ ] 设置页：支付方式列表是国内渠道，海外项已停用
- [ ] 订阅列表：日期是「10月17日」格式
- [ ] 首页：问候语显示昵称（或回退用户名）
- [ ] 个人资料：只有昵称一个字段，改了能保存
- [ ] `docker logs wallos` 里 `cn-seed` 没有报错

---

## 五、回滚

```bash
cd /vol1/1000/docker/wallos
sudo docker compose down
cp db/wallos.db.bak-20261017-1146 db/wallos.db   # 恢复数据库
# 把 docker-compose.yml 的 image 改回 bellamy/wallos:latest
sudo docker compose up -d
```

或者切回 main 分支重新构建：

```bash
cd /c/DSH/Wallos && git checkout main && docker compose build && docker compose up -d
```
