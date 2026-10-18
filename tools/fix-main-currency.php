<?php
/**
 * [CN-PATCH 14] 修复 user.main_currency 指向不存在的货币行。
 *
 * 病因：上一版 DEFAULT_CURRENCIES 沿用了上游的硬编码行 id（CNY => 20），
 * 但 currencies.id 是 AUTOINCREMENT。上游的种子恰好按 34 项的顺序插入，
 * 所以 id 能对上；我们把列表缩短到 11 项后，CNY 拿到 id 1，
 * 而 registration.php 仍然把 20 写进了 user.main_currency。
 *
 * 后果：calendar.php 的 `INNER JOIN currencies ON c.id = u.main_currency`
 * 匹配不到行，$row['code'] 为 null，CurrencyFormatter::format() 收到 null
 * 抛 TypeError —— 就是日历页那两块红色报错。
 *
 * 修法：对每个 main_currency 指向不存在的行的用户，按该用户自己的
 * 货币列表重建指向 —— 优先保留原意（如果它原本想指某个 code，无从得知），
 * 所以退而取该用户的第一个货币（这是我们默认列表的首项，也是人民币）。
 *
 * 幂等：只处理「指向不存在行」的用户，正常用户不受影响。
 * CLI only。
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$root = dirname(__DIR__);
$databaseFile = getenv('SUBDESK_DB') ?: $root . '/db/wallos.db';

if (!file_exists($databaseFile)) {
    fwrite(STDERR, "[fix-currency] 数据库不存在：$databaseFile\n");
    exit(0);
}

$db = new SQLite3($databaseFile);
$db->busyTimeout(5000);
$db->enableExceptions(true);

// 找出所有 main_currency 指向不存在货币行的用户
$rows = [];
$res = $db->query(<<<SQL
    SELECT u.id AS user_id, u.username, u.main_currency
      FROM user u
      LEFT JOIN currencies c ON c.id = u.main_currency
     WHERE c.id IS NULL
SQL);
while ($r = $res->fetchArray(SQLITE3_ASSOC)) {
    $rows[] = $r;
}

if (count($rows) === 0) {
    echo "[fix-currency] 所有用户的主货币都指向有效行，无需修复。\n";
    $db->close();
    exit(0);
}

$db->exec('BEGIN IMMEDIATE');
try {
    $fixed = 0;
    foreach ($rows as $row) {
        $uid = (int) $row['user_id'];

        // 取该用户货币表的第一行（默认列表首项 = 人民币）
        $pick = $db->prepare('SELECT id, code FROM currencies WHERE user_id = :uid ORDER BY id ASC LIMIT 1');
        $pick->bindValue(':uid', $uid, SQLITE3_INTEGER);
        $c = $pick->execute()->fetchArray(SQLITE3_ASSOC);

        if ($c === false) {
            echo "  跳过用户 #{$uid}（{$row['username']}）：该用户没有任何货币记录\n";
            continue;
        }

        $upd = $db->prepare('UPDATE user SET main_currency = :cid WHERE id = :uid');
        $upd->bindValue(':cid', (int) $c['id'], SQLITE3_INTEGER);
        $upd->bindValue(':uid', $uid, SQLITE3_INTEGER);
        $upd->execute();

        printf(
            "  修复用户 #%d（%s）：main_currency %d → %d（%s）\n",
            $uid,
            $row['username'],
            (int) $row['main_currency'],
            (int) $c['id'],
            $c['code']
        );
        $fixed++;
    }
    $db->exec('COMMIT');
    echo "[fix-currency] 完成，修复 $fixed 个用户。\n";
} catch (Throwable $e) {
    $db->exec('ROLLBACK');
    fwrite(STDERR, '[fix-currency] 失败：' . $e->getMessage() . "\n");
    exit(1);
}

$db->close();
