<?php

/**
 * [CN-PATCH 8] 存量数据中文化。
 *
 * 上游的默认分类 / 支付方式 / 货币是英文，已经建好的账号在数据库里存的就是
 * 英文文本（categories.name = 'Entertainment' 等）。光改代码里的种子数据
 * 只影响「将来新建的账号」，已有账号得单独迁移一次。
 *
 * 为什么不写成 migrations/000060.php：
 *   上游的迁移是顺序编号的，我们占用一个号之后，一旦上游发布同号迁移，
 *   两边就会撞车（且 migrations 表已经记录了我们的记录，上游那份会被跳过，
 *   结果是数据库悄悄缺一次结构变更）。独立脚本不参与那个序列，安全。
 *
 * 幂等性：只更新「名字仍然精确等于上游英文默认值」的行。
 * 用户自己改过名字的分类/支付方式不会被碰。
 * 重复执行不会产生任何变化。
 *
 * 调用方式：由 startup.sh 在 migrate.php 之后调用一次；
 * 也可以手动 `php tools/cn-seed.php`。
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$root = dirname(__DIR__);
$databaseFile = getenv('SUBDESK_DB') ?: $root . '/db/wallos.db';

if (!file_exists($databaseFile)) {
    fwrite(STDERR, "[cn-seed] 数据库不存在，跳过：$databaseFile\n");
    exit(0);
}

$db = new SQLite3($databaseFile);
$db->busyTimeout(5000);
$db->enableExceptions(true);

/** 上游英文默认名 => 中文名。键必须与上游 createdatabase.php 的种子完全一致。 */
$categoryMap = [
    'No category' => '无分类',
    'Entertainment' => '影音娱乐',
    'Music' => '音乐',
    'Utilities' => '生活缴费',
    'Food & Beverages' => '餐饮',
    'Health & Wellbeing' => '健康',
    'Productivity' => '效率工具',
    'Banking' => '银行金融',
    'Transport' => '交通出行',
    'Education' => '教育学习',
    'Insurance' => '保险',
    'Gaming' => '游戏',
    'News & Magazines' => '资讯阅读',
    'Software' => '软件订阅',
    'Technology' => '数码科技',
    'Cloud Services' => '云服务与存储',
    'Charity & Donations' => '公益捐赠',
];

/**
 * 支付方式：上游 31 项里，国内会用的改名保留，其余停用（enabled = 0）。
 * 停用而不是删除，是因为 subscriptions.payment_method_id 引用着这些 id，
 * 删掉会留下悬空外键，历史订阅的支付方式显示为空。
 */
$paymentMap = [
    'AliPay' => '支付宝',
    'WeChat' => '微信支付',
    'Union Pay' => '银联云闪付',
    'Credit Card' => '信用卡',
    'Bank Transfer' => '银行转账',
    'Direct Debit' => '自动扣款',
    'Money' => '现金 / 其他',
];

/** 国内基本用不到的支付方式，保留但隐藏。 */
$paymentDisable = [
    'Klarna', 'SEPA', 'Skrill', 'Sofort', 'Stripe', 'Affirm', 'Elo',
    'Facebook Pay', 'GiroPay', 'iDeal', 'Interac', 'Paysafe', 'Poli',
    'Qiwi', 'ShopPay', 'Venmo', 'VeriFone', 'WebMoney', 'Amazon Pay',
    'Samsung Pay', 'Crypto',
];

/**
 * 国内常用支付方式的展示顺序。
 *
 * 上游的 order 是按欧美习惯排的（PayPal 第 1、支付宝第 17、微信第 24），
 * 光改名不重排的话，订阅表单的下拉框仍然是 PayPal 在最上面。
 * 这里对启用中的渠道重新编号，停用的保持在后面（反正不显示）。
 */
$paymentOrder = [
    '支付宝', '微信支付', '银联云闪付', '信用卡', '银行转账',
    '花呗', '京东白条', '虚拟信用卡', '自动扣款', '现金 / 其他',
    'PayPal', 'Apple Pay', 'Google Pay',
];

/** 货币英文名 => 中文名。 */
$currencyMap = [
    'Chinese Yuan' => '人民币',
    'US Dollar' => '美元',
    'Hong Kong Dollar' => '港币',
    'Japanese Yen' => '日元',
    'Euro' => '欧元',
    'British Pound Sterling' => '英镑',
    'New Taiwan Dollar' => '新台币',
    'Singapore Dollar' => '新加坡元',
    'South Korean Won' => '韩元',
    'Australian Dollar' => '澳元',
    'Canadian Dollar' => '加元',
];

/**
 * 国内用不到的货币，且没有任何订阅引用时直接删除。
 *
 * 为什么可以删而支付方式只能停用：subscriptions 用 currency_id 引用货币，
 * 所以删除前必须先确认没有订阅在用（下面 SQL 会检查）。
 * 保留 34 种货币的唯一后果是「设置 → 货币」页长得要滚动，
 * 以及汇率表多算 23 个无用条目。
 */
$currencyPrune = [
    'BGN', 'CZK', 'DKK', 'HUF', 'PLN', 'RON', 'SEK', 'CHF', 'ISK',
    'NOK', 'RUB', 'TRY', 'BRL', 'IDR', 'ILS', 'INR', 'MXN', 'MYR',
    'NZD', 'PHP', 'THB', 'ZAR', 'UAH',
];

$stats = [
    'categories' => 0, 'payments_renamed' => 0, 'payments_disabled' => 0,
    'payments_reordered' => 0, 'currencies' => 0, 'currencies_pruned' => 0,
];

$db->exec('BEGIN IMMEDIATE');
try {
    $stmt = $db->prepare('UPDATE categories SET name = :cn WHERE name = :en');
    foreach ($categoryMap as $en => $cn) {
        $stmt->bindValue(':cn', $cn, SQLITE3_TEXT);
        $stmt->bindValue(':en', $en, SQLITE3_TEXT);
        $stmt->execute();
        $stats['categories'] += $db->changes();
        $stmt->reset();
    }

    $stmt = $db->prepare('UPDATE payment_methods SET name = :cn WHERE name = :en');
    foreach ($paymentMap as $en => $cn) {
        $stmt->bindValue(':cn', $cn, SQLITE3_TEXT);
        $stmt->bindValue(':en', $en, SQLITE3_TEXT);
        $stmt->execute();
        $stats['payments_renamed'] += $db->changes();
        $stmt->reset();
    }

    $stmt = $db->prepare('UPDATE payment_methods SET enabled = 0 WHERE name = :en AND enabled = 1');
    foreach ($paymentDisable as $en) {
        $stmt->bindValue(':en', $en, SQLITE3_TEXT);
        $stmt->execute();
        $stats['payments_disabled'] += $db->changes();
        $stmt->reset();
    }

    // 重排启用中的支付方式：先按 $paymentOrder 编号 1..N，
    // 停用的接在后面，避免 order 撞号导致排序不稳定。
    $stmt = $db->prepare('UPDATE payment_methods SET "order" = :ord WHERE name = :name');
    $order = 1;
    foreach ($paymentOrder as $name) {
        $stmt->bindValue(':ord', $order, SQLITE3_INTEGER);
        $stmt->bindValue(':name', $name, SQLITE3_TEXT);
        $stmt->execute();
        $stats['payments_reordered'] += $db->changes();
        $stmt->reset();
        $order++;
    }
    $stmt = $db->prepare('UPDATE payment_methods SET "order" = :ord WHERE enabled = 0');
    $stmt->bindValue(':ord', 999, SQLITE3_INTEGER);
    $stmt->execute();
    $stmt->reset();

    $stmt = $db->prepare('UPDATE currencies SET name = :cn WHERE name = :en');
    foreach ($currencyMap as $en => $cn) {
        $stmt->bindValue(':cn', $cn, SQLITE3_TEXT);
        $stmt->bindValue(':en', $en, SQLITE3_TEXT);
        $stmt->execute();
        $stats['currencies'] += $db->changes();
        $stmt->reset();
    }

    // 删除没有任何订阅引用的冷门货币。
    // 先查引用再删，避免留下悬空的 currency_id。
    $stmt = $db->prepare(
        'DELETE FROM currencies
          WHERE code = :code
            AND id NOT IN (SELECT DISTINCT currency_id FROM subscriptions WHERE currency_id IS NOT NULL)
            AND id <> (SELECT main_currency FROM user WHERE id = currencies.user_id)'
    );
    foreach ($currencyPrune as $code) {
        $stmt->bindValue(':code', $code, SQLITE3_TEXT);
        $stmt->execute();
        $stats['currencies_pruned'] += $db->changes();
        $stmt->reset();
    }

    $db->exec('COMMIT');
} catch (Throwable $e) {
    $db->exec('ROLLBACK');
    fwrite(STDERR, '[cn-seed] 失败：' . $e->getMessage() . "\n");
    exit(1);
}

$total = array_sum($stats);
if ($total === 0) {
    echo "[cn-seed] 数据已是中文，无改动。\n";
} else {
    echo sprintf(
        "[cn-seed] 完成：分类 %d 项、支付方式改名 %d / 停用 %d / 重排 %d、货币改名 %d / 清理 %d。\n",
        $stats['categories'],
        $stats['payments_renamed'],
        $stats['payments_disabled'],
        $stats['payments_reordered'],
        $stats['currencies'],
        $stats['currencies_pruned']
    );
}

$db->close();
