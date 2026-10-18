<?php
require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint_admin.php';
require_once '../../includes/default_names.php';

// [CN-PATCH 12] 货币表统一由 includes/default_names.php 的 DEFAULT_CURRENCIES
// 提供。这里原本是第四份 34 项硬编码副本，与注册页那份没有任何同步机制，
// 导致管理员通过后台加用户时写入 34 种英文货币，而注册页写入 11 种中文。
// 实际使用 default_currencies($language)，见下方 INSERT 处。
$currencies = DEFAULT_CURRENCIES;

function validate($value)
{
    $value = trim($value);
    $value = stripslashes($value);
    $value = htmlspecialchars($value);
    $value = htmlentities($value);
    return $value;
}

$postData = file_get_contents("php://input");
$data = json_decode($postData, true);

$loggedInUserId = $userId;

$email = validate($data['email']);
$username = validate($data['username']);
$password = $data['password'];

if (empty($username) || empty($password) || empty($email)) {
    die(json_encode([
        "success" => false,
        "message" => translate('error', $i18n)
    ]));
}

$stmt = $db->prepare('SELECT COUNT(*) FROM user WHERE username = :username OR email = :email');
$stmt->bindValue(':username', $username, SQLITE3_INTEGER);
$stmt->bindValue(':email', $email, SQLITE3_TEXT);
$result = $stmt->execute();
$row = $result->fetchArray();
// Error if user exist
if ($row[0] > 0) {
    die(json_encode([
        "success" => false,
        "message" => translate('error', $i18n)
    ]));
}

// Get main currency and language from admin user
$stmt = $db->prepare('SELECT main_currency, language FROM user WHERE id = :id');
$stmt->bindValue(':id', $loggedInUserId, SQLITE3_TEXT);
$result = $stmt->execute();
$row = $result->fetchArray();
$currency = $row['main_currency'] ?? 1;
$language = $row['language'] ?? 'en';
$avatar = "images/avatars/0.svg";

// Get code for main currency
$stmt = $db->prepare('SELECT code FROM currencies WHERE id = :id');
$stmt->bindValue(':id', $currency, SQLITE3_TEXT);
$row = $stmt->execute();
$main_currency = $row->fetchArray()['code'];

$query = "INSERT INTO user (username, email, password, main_currency, avatar, language, budget, api_key) VALUES (:username, :email, :password, :main_currency, :avatar, :language, :budget, :api_key)";
$stmt = $db->prepare($query);
$hashedPassword = password_hash($password, PASSWORD_DEFAULT);
$stmt->bindValue(':username', $username, SQLITE3_TEXT);
$stmt->bindValue(':email', $email, SQLITE3_TEXT);
$stmt->bindValue(':password', $hashedPassword, SQLITE3_TEXT);
// [CN-PATCH 14] 原本硬编码 1。1 是 Euro 在上游种子里的 id，本实例的
// currencies.id 由 AUTOINCREMENT 决定，不保证 1 是哪个货币 ——
// 新库里 1 恰好是人民币，纯属巧合，换一种插入顺序就会变成别的。
// 留一个不指向任何行的占位值，真实 id 由下面的「Update user main currency」
// 按 ISO 代码查出来后回填。
$stmt->bindValue(':main_currency', 0, SQLITE3_INTEGER);
$stmt->bindValue(':avatar', $avatar, SQLITE3_TEXT);
$stmt->bindValue(':language', $language, SQLITE3_TEXT);
$stmt->bindValue(':budget', 0, SQLITE3_INTEGER);
$stmt->bindValue(':api_key', bin2hex(random_bytes(32)), SQLITE3_TEXT);
$result = $stmt->execute();

if ($result) {

    // Get id of the newly created user
    $newUserId = $db->lastInsertRowID();

    // Add username as household member for that user
    $query = "INSERT INTO household (name, user_id) VALUES (:name, :user_id)";
    $stmt = $db->prepare($query);
    $stmt->bindValue(':name', $username, SQLITE3_TEXT);
    $stmt->bindValue(':user_id', $newUserId, SQLITE3_INTEGER);
    $stmt->execute();

    if ($newUserId > 1) {

        // Add categories for that user
        $query = 'INSERT INTO categories (name, "order", user_id) VALUES (:name, :order, :user_id)';
        $stmt = $db->prepare($query);
        foreach (default_categories($language) as $index => $name) {
            $stmt->bindValue(':name', $name, SQLITE3_TEXT);
            $stmt->bindValue(':order', $index + 1, SQLITE3_INTEGER);
            $stmt->bindValue(':user_id', $newUserId, SQLITE3_INTEGER);
            $stmt->execute();
        }

        // Add payment methods for that user
        $query = 'INSERT INTO payment_methods (name, icon, "order", user_id) VALUES (:name, :icon, :order, :user_id)';
        $stmt = $db->prepare($query);
        foreach (default_payment_methods($language) as $index => $payment_method) {
            $stmt->bindValue(':name', $payment_method['name'], SQLITE3_TEXT);
            $stmt->bindValue(':icon', $payment_method['icon'], SQLITE3_TEXT);
            $stmt->bindValue(':order', $index + 1, SQLITE3_INTEGER);
            $stmt->bindValue(':user_id', $newUserId, SQLITE3_INTEGER);
            $stmt->execute();
        }

        // Add currencies for that user
        //
        // [CN-PATCH 12] 用 default_currencies() 而不是直接遍历 $currencies：
        // 前者会把货币名按新账号的语言翻译成中文，后者只给英文原名。
        $query = "INSERT INTO currencies (name, symbol, code, rate, user_id) VALUES (:name, :symbol, :code, :rate, :user_id)";
        $stmt = $db->prepare($query);
        foreach (default_currencies($language) as $currency) {
            $stmt->bindValue(':name', $currency['name'], SQLITE3_TEXT);
            $stmt->bindValue(':symbol', $currency['symbol'], SQLITE3_TEXT);
            $stmt->bindValue(':code', $currency['code'], SQLITE3_TEXT);
            $stmt->bindValue(':rate', 1, SQLITE3_FLOAT);
            $stmt->bindValue(':user_id', $newUserId, SQLITE3_INTEGER);
            $stmt->execute();
        }

        // Retrieve main currency id
        // [CN-PATCH 14] 按 ISO 代码查真实行 id（currencies.id 是 AUTOINCREMENT）
        $query = "SELECT id FROM currencies WHERE code = :code AND user_id = :user_id";
        $stmt = $db->prepare($query);
        $stmt->bindValue(':code', $main_currency, SQLITE3_TEXT);
        $stmt->bindValue(':user_id', $newUserId, SQLITE3_INTEGER);
        $result = $stmt->execute();
        $currency = $result->fetchArray(SQLITE3_ASSOC);

        // Update user main currency
        $query = "UPDATE user SET main_currency = :main_currency WHERE id = :user_id";
        $stmt = $db->prepare($query);
        $stmt->bindValue(':main_currency', $currency['id'], SQLITE3_INTEGER);
        $stmt->bindValue(':user_id', $newUserId, SQLITE3_INTEGER);
        $stmt->execute();

        // Add settings for that user
        $query = "INSERT INTO settings (dark_theme, monthly_price, convert_currency, remove_background, color_theme, hide_disabled, user_id, disabled_to_bottom, show_original_price, mobile_nav, week_starts_sunday) 
                VALUES (2, 0, 0, 0, 'blue', 0, :user_id, 0, 0, 0, 0)";
        $stmt = $db->prepare($query);
        $stmt->bindValue(':user_id', $newUserId, SQLITE3_INTEGER);
        $stmt->execute();

        // If email verification is required add the user to the email_verification table
        $query = "SELECT * FROM admin";
        $stmt = $db->prepare($query);
        $result = $stmt->execute();
        $settings = $result->fetchArray(SQLITE3_ASSOC);
    }

    $db->close();

    die(json_encode([
        "success" => true,
        "message" => translate('success', $i18n)
    ]));
}