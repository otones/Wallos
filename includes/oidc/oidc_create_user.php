<?php

// Try to extract first and last name from "name"
$fullName = $userInfo['name'] ?? '';
$parts = explode(' ', trim($fullName), 2);
$firstname = $parts[0] ?? '';
$lastname = $parts[1] ?? '';

// Defaults
//
// The language comes from the provider when it named one. "locale" is a
// standard OIDC claim and Authentik, Authelia and Keycloak all send it, so
// hardcoding English here meant every account an identity provider created was
// English whatever the provider said - and the person never saw the
// registration form that would have asked them, because they never went
// through it. resolve_language() answers 'en' when there is no claim or when
// the claim names no translation, which is what this line did before.
require_once __DIR__ . '/../i18n/languages.php';
$language = resolve_language($userInfo['locale'] ?? null);
$avatar = "images/avatars/0.svg";
$budget = 0;
// [CN-PATCH 12] 主货币不再硬编码为 1（Euro）。
//
// 这里的值只是 INSERT user 时的占位：currencies 表要到下面第 80 行才插入，
// 插入完再由 "UPDATE user SET main_currency" 回填真实的行 id。
// 取 DEFAULT_CURRENCIES 的第一项（人民币）的 id 作为占位，
// 保证即便回填失败，用户也不会落到欧元上。
require_once __DIR__ . '/../default_names.php';
$main_currency_id = DEFAULT_CURRENCIES[0]['id'];
$password = bin2hex(random_bytes(16)); // 32-character random password$hashedPassword = password_hash($password, PASSWORD_DEFAULT);

// Insert user
$query = "INSERT INTO user (username, email, oidc_sub, main_currency, avatar, language, budget, firstname, lastname, password, api_key)
          VALUES (:username, :email, :oidc_sub, :main_currency, :avatar, :language, :budget, :firstname, :lastname, :password, :api_key)";
$stmt = $db->prepare($query);
$stmt->bindValue(':username', $username, SQLITE3_TEXT);
$stmt->bindValue(':email', $email, SQLITE3_TEXT);
$stmt->bindValue(':oidc_sub', $oidcSub, SQLITE3_TEXT);
$stmt->bindValue(':main_currency', $main_currency_id, SQLITE3_INTEGER);
$stmt->bindValue(':avatar', $avatar, SQLITE3_TEXT);
$stmt->bindValue(':language', $language, SQLITE3_TEXT);
$stmt->bindValue(':budget', $budget, SQLITE3_INTEGER);
$stmt->bindValue(':firstname', $firstname, SQLITE3_TEXT);
$stmt->bindValue(':lastname', $lastname, SQLITE3_TEXT);
$stmt->bindValue(':password', $hashedPassword, SQLITE3_TEXT);
$stmt->bindValue(':api_key', bin2hex(random_bytes(32)), SQLITE3_TEXT);

if (!$stmt->execute()) {
    die("Failed to create user");
}

// Get the user data into $userData
$stmt = $db->prepare("SELECT * FROM user WHERE username = :username");
$stmt->bindValue(':username', $username, SQLITE3_TEXT);
$result = $stmt->execute();
$userData = $result->fetchArray(SQLITE3_ASSOC);
$newUserId = $userData['id'];

// Household
$stmt = $db->prepare("INSERT INTO household (name, user_id) VALUES (:name, :user_id)");
$stmt->bindValue(':name', $username, SQLITE3_TEXT);
$stmt->bindValue(':user_id', $newUserId, SQLITE3_INTEGER);
$stmt->execute();

require_once __DIR__ . '/../default_names.php';

// Categories
$stmt = $db->prepare("INSERT INTO categories (name, \"order\", user_id) VALUES (:name, :order, :user_id)");
foreach (default_categories($language) as $index => $name) {
    $stmt->bindValue(':name', $name, SQLITE3_TEXT);
    $stmt->bindValue(':order', $index + 1, SQLITE3_INTEGER);
    $stmt->bindValue(':user_id', $newUserId, SQLITE3_INTEGER);
    $stmt->execute();
}

// Payment Methods
$stmt = $db->prepare("INSERT INTO payment_methods (name, icon, \"order\", user_id) VALUES (:name, :icon, :order, :user_id)");
foreach (default_payment_methods($language) as $index => $method) {
    $stmt->bindValue(':name', $method['name'], SQLITE3_TEXT);
    $stmt->bindValue(':icon', $method['icon'], SQLITE3_TEXT);
    $stmt->bindValue(':order', $index + 1, SQLITE3_INTEGER);
    $stmt->bindValue(':user_id', $newUserId, SQLITE3_INTEGER);
    $stmt->execute();
}

// Currencies
//
// [CN-PATCH 12] 第五份货币副本的下场：这里原本是独立的 34 项硬编码数组，
// 且 main_currency_id 上面硬编码为 1（Euro）—— 通过 OIDC 创建的用户
// 拿到的是欧元账户 + 34 种英文货币。现在改为读 DEFAULT_CURRENCIES，
// 主货币也跟随该表的第一个（人民币）。
$currencies = default_currencies($language);
$main_currency_id = $currencies[0]['id'];

$stmt = $db->prepare("INSERT INTO currencies (name, symbol, code, rate, user_id) 
                      VALUES (:name, :symbol, :code, :rate, :user_id)");
foreach ($currencies as $currency) {
    $stmt->bindValue(':name', $currency['name'], SQLITE3_TEXT);
    $stmt->bindValue(':symbol', $currency['symbol'], SQLITE3_TEXT);
    $stmt->bindValue(':code', $currency['code'], SQLITE3_TEXT);
    $stmt->bindValue(':rate', 1.0, SQLITE3_FLOAT);
    $stmt->bindValue(':user_id', $newUserId, SQLITE3_INTEGER);
    $stmt->execute();
}

// 回填主货币的真实行 id
//
// [CN-PATCH 12] 这里原本硬编码查 'EUR'，与上面「主货币硬编码为 1（Euro）」
// 是同一个假设的两半。改成查 DEFAULT_CURRENCIES 的第一项，
// 两处就不会再各说各话。
$mainCurrencyCode = DEFAULT_CURRENCIES[0]['code'];
$stmt = $db->prepare("SELECT id FROM currencies WHERE code = :code AND user_id = :user_id");
$stmt->bindValue(':code', $mainCurrencyCode, SQLITE3_TEXT);
$stmt->bindValue(':user_id', $newUserId, SQLITE3_INTEGER);
$result = $stmt->execute();
$currency = $result->fetchArray(SQLITE3_ASSOC);
if ($currency) {
    $stmt = $db->prepare("UPDATE user SET main_currency = :main_currency WHERE id = :user_id");
    $stmt->bindValue(':main_currency', $currency['id'], SQLITE3_INTEGER);
    $stmt->bindValue(':user_id', $newUserId, SQLITE3_INTEGER);
    $stmt->execute();
}

// 第 118 行原本直接取 $currency['id']，$currency 为 false 时会取到 null。
if ($currency) {
    $userData['main_currency'] = $currency['id'];
}

// Insert settings
$stmt = $db->prepare("INSERT INTO settings (dark_theme, monthly_price, convert_currency, remove_background, color_theme, hide_disabled, user_id, disabled_to_bottom, show_original_price, mobile_nav, week_starts_sunday) 
                      VALUES (2, 0, 0, 0, 'blue', 0, :user_id, 0, 0, 0, 0)");
$stmt->bindValue(':user_id', $newUserId, SQLITE3_INTEGER);
$stmt->execute();

// Log the user in
require_once('oidc_login.php');