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
// [CN-PATCH 12 / 14] 主货币不再硬编码为 1（Euro）。
//
// 这里的值只是 INSERT user 时的占位：currencies 表要到下面才插入，
// 插入完再由「回填主货币」那一步按 ISO 代码查出真实的行 id。
//
// 用 0 而不是某个具体 id：currencies.id 是 AUTOINCREMENT，由插入顺序决定，
// 本文件无法预知。占位值写成 0，回填失败时会明确表现为「指向不存在的行」，
// 而不是"碰巧指到某个货币"这种更难察觉的错误。
require_once __DIR__ . '/../default_names.php';
$main_currency_id = 0;
$password = bin2hex(random_bytes(16)); // 32-character random password
$hashedPassword = password_hash($password, PASSWORD_DEFAULT);

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
// [CN-PATCH 12 / 14] 这里原本是独立的 34 项硬编码数组，且 main_currency_id
// 上面硬编码为 1（Euro）—— 通过 OIDC 创建的用户拿到的是欧元账户
// + 34 种英文货币。现在改为读 DEFAULT_CURRENCIES，货币名按语言解析。
//
// 注意这里不再从数组里取 id：数组本身不带 id（见 default_names.php 的说明），
// 真实行 id 由下面「回填主货币」按 ISO 代码查出来。
$currencies = default_currencies($language);

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
// [CN-PATCH 12 / 14] 这里原本硬编码查 'EUR'，与上面「主货币硬编码为 1（Euro）」
// 是同一个假设的两半。现在按 DEFAULT_CURRENCIES 第一项的 ISO 代码查真实 id ——
// 这是唯一可靠的来源，因为 currencies.id 是 AUTOINCREMENT，本文件无法预知。
$mainCurrencyCode = DEFAULT_CURRENCIES[0]['code'];
$stmt = $db->prepare("SELECT id FROM currencies WHERE code = :code AND user_id = :user_id");
$stmt->bindValue(':code', $mainCurrencyCode, SQLITE3_TEXT);
$stmt->bindValue(':user_id', $newUserId, SQLITE3_INTEGER);
$result = $stmt->execute();
$currency = $result->fetchArray(SQLITE3_ASSOC);

if ($currency === false) {
    // 上面刚插入了全部 DEFAULT_CURRENCIES，查不到说明插入失败。
    // 静默留下 main_currency = 0 会让整个实例的货币显示失效且难查，
    // 这里明确失败。
    die("OIDC 建号后无法确定主货币 '" . $mainCurrencyCode . "' 的 id");
}

$stmt = $db->prepare("UPDATE user SET main_currency = :main_currency WHERE id = :user_id");
$stmt->bindValue(':main_currency', $currency['id'], SQLITE3_INTEGER);
$stmt->bindValue(':user_id', $newUserId, SQLITE3_INTEGER);
$stmt->execute();

$userData['main_currency'] = $currency['id'];

// Insert settings
$stmt = $db->prepare("INSERT INTO settings (dark_theme, monthly_price, convert_currency, remove_background, color_theme, hide_disabled, user_id, disabled_to_bottom, show_original_price, mobile_nav, week_starts_sunday) 
                      VALUES (2, 0, 0, 0, 'blue', 0, :user_id, 0, 0, 0, 0)");
$stmt->bindValue(':user_id', $newUserId, SQLITE3_INTEGER);
$stmt->execute();

// Log the user in
require_once('oidc_login.php');