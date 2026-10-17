<?php

$databaseFile = __DIR__ . '/../../db/wallos.db';

if (!file_exists($databaseFile)) {
    echo "Database does not exist. Creating it...\n";
    $db = new SQLite3($databaseFile, SQLITE3_OPEN_CREATE | SQLITE3_OPEN_READWRITE);
    $db->busyTimeout(5000);

    $db->exec('CREATE TABLE user (
        id INTEGER PRIMARY KEY,
        username TEXT NOT NULL,
        email TEXT NOT NULL,
        password TEXT NOT NULL,
        main_currency INTEGER NOT NULL,
        avatar TEXT,
        FOREIGN KEY(main_currency) REFERENCES currencies(id)
    )');

    $db->exec('CREATE TABLE payment_methods (
        id INTEGER PRIMARY KEY,
        name TEXT NOT NULL,
        icon TEXT
    )');

    $db->exec('CREATE TABLE subscriptions (
        id INTEGER PRIMARY KEY,
        name TEXT NOT NULL,
        logo TEXT,
        price REAL NOT NULL,
        currency_id INTEGER,
        next_payment DATE,
        cycle INTEGER,
        frequency INTEGER,
        notes TEXT,
        payment_method_id INTEGER,
        payer_user_id INTEGER,
        category_id INTEGER,
        notify BOOLEAN DEFAULT false,
        FOREIGN KEY(currency_id) REFERENCES currencies(id),
        FOREIGN KEY(cycle) REFERENCES cycles(id),
        FOREIGN KEY(frequency) REFERENCES frequencies(id),
        FOREIGN KEY(payment_method_id) REFERENCES payment_methods(id),
        FOREIGN KEY(payer_user_id) REFERENCES household(id)
        FOREIGN KEY(category_id) REFERENCES categories(id)
    )');

    $db->exec('CREATE TABLE categories (
        id INTEGER PRIMARY KEY,
        name TEXT NOT NULL
    )');

    $db->exec('CREATE TABLE currencies (
        id INTEGER PRIMARY KEY,
        name TEXT NOT NULL,
        symbol TEXT NOT NULL,
        code TEXT NOT NULL,
        rate TEXT NOT NULL
    )');

    $db->exec('CREATE TABLE household (
        id INTEGER PRIMARY KEY,
        name TEXT NOT NULL
    )');

    $db->exec('CREATE TABLE login_tokens (
        user_id INTEGER NOT NULL,
        token TEXT NOT NULL,
        timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES user(id) ON DELETE CASCADE ON UPDATE CASCADE
    )');

    $db->exec('CREATE TABLE cycles (
        id INTEGER PRIMARY KEY,
        days INTEGER NOT NULL,
        name TEXT NOT NULL
    )');

    $db->exec('CREATE TABLE frequencies (
        id INTEGER PRIMARY KEY,
        name INTEGER NOT NULL
    )');

    $db->exec('CREATE TABLE fixer (
        api_key TEXT NOT NULL
    )');

    $db->exec('CREATE TABLE last_exchange_update (
        date DATE NOT NULL
    )');

    $db->exec('CREATE TABLE last_update_next_payment_date (
        date DATE NOT NULL
    )');

    $db->exec('CREATE TABLE notifications (
        id INTEGER PRIMARY KEY,
        enabled BOOLEAN DEFAULT false,
        days INTEGER,
        smtp_address VARCHAR(255),
        smtp_port INTEGER,
        smtp_username VARCHAR(255),
        smtp_password VARCHAR(255)
    )');

    // [CN-PATCH 2b] 与 includes/default_names.php 的 DEFAULT_CATEGORY_KEYS 对应。
    // 这里是「全新安装」的种子数据：数据库第一次创建时就写这批名字，
    // 之后由 localize_default_names() 按注册时选择的语言再做一次本地化。
    // 保持中文是因为国内部署下新装实例的默认语言就是中文（见 getlang.php）。
    $db->exec("INSERT INTO categories (id, name) VALUES
    (1, '无分类'),
    (2, '影音娱乐'),
    (3, '音乐'),
    (4, '生活缴费'),
    (5, '餐饮'),
    (6, '健康'),
    (7, '效率工具'),
    (8, '银行金融'),
    (9, '交通出行'),
    (10, '教育学习'),
    (11, '保险'),
    (12, '游戏'),
    (13, '资讯阅读'),
    (14, '软件订阅'),
    (15, '数码科技'),
    (16, '云服务与存储'),
    (17, '公益捐赠')");

    $db->exec("INSERT INTO cycles (id, days, name) VALUES
    (1, 1, 'Daily'),
    (2, 7, 'Weekly'),
    (3, 30, 'Monthly'),
    (4, 365, 'Yearly'),
    (5, 0, 'One-time')");

    $db->exec("INSERT INTO frequencies (id, name) VALUES
    (1, 1),
    (2, 2),
    (3, 3),
    (4, 4),
    (5, 5),
    (6, 6),
    (7, 7),
    (8, 8),
    (9, 9),
    (10, 10),
    (11, 11),
    (12, 12),
    (13, 13),
    (14, 14),
    (15, 15),
    (16, 16),
    (17, 17),
    (18, 18),
    (19, 19),
    (20, 20),
    (21, 21),
    (22, 22),
    (23, 23),
    (24, 24),
    (25, 25),
    (26, 26),
    (27, 27),
    (28, 28),
    (29, 29),
    (30, 30),
    (31, 31)");

    // [CN-PATCH 6] 全新安装的种子货币表。
    // 上游把 34 种货币全写进去，第一项是 Euro —— 注册页的下拉框直接遍历这张表，
    // 没有任何 option 带 selected 时浏览器选第一项，所以新用户默认拿到欧元。
    // 这里重排为「人民币第一」，并把常用的几个改成中文名。
    // 冷门货币（保加利亚列弗、冰岛克朗之类）直接去掉：国内订阅场景用不到，
    // 留着只会让货币下拉框长到需要滚动。
    $db->exec("INSERT INTO currencies (name, symbol, code, rate) VALUES
    ('人民币', '¥', 'CNY', 1),
    ('美元', '$', 'USD', 1),
    ('港币', 'HK$', 'HKD', 1),
    ('日元', '¥', 'JPY', 1),
    ('欧元', '€', 'EUR', 1),
    ('英镑', '£', 'GBP', 1),
    ('新台币', 'NT$', 'TWD', 1),
    ('新加坡元', 'S$', 'SGD', 1),
    ('韩元', '₩', 'KRW', 1),
    ('澳元', 'A\$', 'AUD', 1),
    ('加元', 'C\$', 'CAD', 1)");

    // [CN-PATCH 2b] 支付方式种子数据，与 DEFAULT_PAYMENT_METHODS 保持一致。
    // 上游的 31 项欧美方案改为国内常用的 13 项；海外三项放最后保留。
    // id 保持不变（1-31 的区间不动），因为 subscriptions.payment_method_id 是靠 id 关联的，
    // 已有备份数据库导入后仍然指得到对应的行。
    $db->exec("INSERT INTO payment_methods (id, name, icon) VALUES
    (1, '支付宝', 'alipay.png'),
    (2, '微信支付', 'wechat.png'),
    (3, '银联云闪付', 'unionpay.png'),
    (4, '信用卡', 'creditcard.png'),
    (5, '银行转账', 'banktransfer.png'),
    (6, '花呗', 'alipay.png'),
    (7, '京东白条', 'creditcard.png'),
    (8, '虚拟信用卡', 'creditcard.png'),
    (9, '自动扣款', 'directdebit.png'),
    (10, '现金 / 其他', 'money.png'),
    (11, 'PayPal', 'paypal.png'),
    (12, 'Apple Pay', 'applepay.png'),
    (13, 'Google Pay', 'googlepay.png')");

    $setupToken = bin2hex(random_bytes(32));
    file_put_contents(__DIR__ . '/../../db/setup_token.db', $setupToken);
    echo "Database created.\n";
    echo "Setup token for database restore: " . $setupToken . "\n";
} else {
    echo "Database already exist. Checking for upgrades...\n";

    $db = new SQLite3($databaseFile);
    $db->busyTimeout(5000);

    if (!$db) {
        die('Connection to the database failed.');
    }

    # v0.9 to v1.0
    # Added new notifications table
    # Added notify column to subscriptions table

    $result = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='notifications'");
    if (!$result->fetchArray(SQLITE3_ASSOC)) {
        $db->exec('CREATE TABLE notifications (
            id INTEGER PRIMARY KEY,
            enabled BOOLEAN DEFAULT false,
            days INTEGER,
            smtp_address VARCHAR(255),
            smtp_port INTEGER,
            smtp_username VARCHAR(255),
            smtp_password VARCHAR(255)
        )');
        echo "Table 'notifications' created.\n";
    } else {
        echo "Table 'notifications' already exists.\n";
    }

    $result = $db->query("PRAGMA table_info(subscriptions)");
    $notifyColumnExists = false;
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        if ($row['name'] === 'notify') {
            $notifyColumnExists = true;
            break;
        }
    }
    if (!$notifyColumnExists) {
        $db->exec('ALTER TABLE subscriptions ADD COLUMN notify BOOLEAN DEFAULT false');
        echo "Column 'notify' added to table 'subscriptions'.\n";
    } else {
        echo "Column 'notify' already exists in table 'subscriptions'.\n";
    }

}

?>