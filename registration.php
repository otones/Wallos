<?php
require_once 'includes/connect.php';
require_once 'includes/checkuser.php';
ob_start();
require_once 'includes/run_migrations.php';
ob_end_clean();

require_once 'includes/i18n/languages.php';
require_once 'includes/i18n/getlang.php';
require_once 'includes/i18n/' . $lang . '.php';

require_once 'includes/version.php';
require_once 'includes/theme_helpers.php';
require_once 'includes/default_names.php';

function validate($value)
{
    $value = trim($value);
    $value = stripslashes($value);
    $value = htmlspecialchars($value);
    $value = htmlentities($value);
    return $value;
}

// If logo folder doesn't exist, create it
if (!file_exists('images/uploads/logos')) {
    mkdir('images/uploads/logos', 0777, true);
    mkdir('images/uploads/logos/avatars', 0777, true);
}

// If there's already a user on the database, redirect to login page if registrations are closed or maxn users is reached
$stmt = $db->prepare('SELECT COUNT(*) as userCount FROM user');
$result = $stmt->execute();
$userCountResult = $result->fetchArray(SQLITE3_ASSOC);
$userCount = $userCountResult['userCount'];

if ($userCount == 0) {
    $setupTokenFile = __DIR__ . '/db/setup_token.db';
    if (!file_exists($setupTokenFile)) {
        $setupToken = bin2hex(random_bytes(32));
        file_put_contents($setupTokenFile, $setupToken);
        error_log("Setup token for database restore: " . $setupToken);
    }
}

if ($userCount > 0) {
    $stmt = $db->prepare('SELECT * FROM admin');
    $result = $stmt->execute();
    $settings = $result->fetchArray(SQLITE3_ASSOC);

    if ($settings['registrations_open'] == 0) {
        header("Location: login.php");
        exit();
    }

    if ($settings['max_users'] != 0) {

        if ($userCount >= $settings['max_users']) {
            header("Location: login.php");
            exit();
        }
    }
}


$theme = "light";
$updateThemeSettings = false;
if (isset($_COOKIE['theme'])) {
    $theme = sanitize_theme_mode($_COOKIE['theme']);
} else {
    $updateThemeSettings = true;
}

$colorTheme = "blue";
if (isset($_COOKIE['colorTheme'])) {
    $colorTheme = sanitize_color_theme($_COOKIE['colorTheme']);
}

// [CN-PATCH 6b / 12] 货币表统一由 includes/default_names.php 的 DEFAULT_CURRENCIES
// 提供，不再在这里维护副本。
//
// 这里原本有一份独立的 34 项硬编码数组，admin/adduser.php 和
// oidc_create_user.php 各自还有一份。四处副本没有任何机制保证一致，
// 导致「注册页」和「管理员加用户」写入的货币不同。现在只有一份定义。
$currencies = DEFAULT_CURRENCIES;

$passwordMismatch = false;
$usernameExists = false;
$emailExists = false;
$registrationFailed = false;
$hasErrors = false;
if (isset($_POST['username'])) {
    $username = validate($_POST['username']);
    // [CN-PATCH 5] 表单已改为单个「昵称」字段。这里做兼容：
    // 新表单提交 nickname，旧表单/API 调用仍可能提交 firstname/lastname。
    $firstname = validate($_POST['nickname'] ?? ($_POST['firstname'] ?? ''));
    $lastname = '';
    $email = validate($_POST['email']);
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];
    $main_currency = $_POST['main_currency'];
    // [CN-PATCH 14] 这里原本是 $currencies[$main_currency_index]['id']，
    // 取的是 DEFAULT_CURRENCIES 里硬编码的上游行 id。那个 id 只是抄过来的数字，
    // 和本实例数据库里真正的行 id 没有任何关系 ——
    // currencies.id 是 AUTOINCREMENT，由插入顺序决定。
    //
    // 结果是 user.main_currency 被写成 20（CNY 在上游的 id），而新库里
    // CNY 的实际 id 是 1，于是 calendar.php 的 INNER JOIN 匹配不到行，
    // 货币代码为 null，统计卡片直接抛 TypeError。
    //
    // 正确做法：INSERT user 时这里只放一个占位值，真正的 id 由下面
    // 「Retrieve main currency id」按 code 查出来再 UPDATE 回去。
    // 占位值用 0 而不是某个真实 id，避免它在回填失败时"碰巧指到某个货币"。
    $main_currency_id = 0;
    $language = $_POST['language'];
    $avatar = "images/avatars/0.svg";

    if ($password != $confirm_password) {
        $passwordMismatch = true;
        $hasErrors = true;
    }

    $emailQuery = "SELECT * FROM user WHERE email = :email";
    $stmtEmail = $db->prepare($emailQuery);
    $stmtEmail->bindValue(':email', $email, SQLITE3_TEXT);
    $resultEmail = $stmtEmail->execute();

    if ($resultEmail->fetchArray()) {
        $emailExists = true;
        $hasErrors = true;
    }

    $usernameQuery = "SELECT * FROM user WHERE username = :username";
    $stmtUsername = $db->prepare($usernameQuery);
    $stmtUsername->bindValue(':username', $username, SQLITE3_TEXT);
    $resultUsername = $stmtUsername->execute();

    if ($resultUsername->fetchArray()) {
        $usernameExists = true;
        $hasErrors = true;
    }

    $requireValidation = false;

    if ($hasErrors == false) {
        $query = "INSERT INTO user (username, firstname, lastname, email, password, main_currency, avatar, language, budget, api_key) VALUES (:username, :firstname, :lastname, :email, :password, :main_currency, :avatar, :language, :budget, :api_key)";
        $stmt = $db->prepare($query);
        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
        $stmt->bindValue(':username', $username, SQLITE3_TEXT);
        $stmt->bindValue(':firstname', $firstname, SQLITE3_TEXT);
        $stmt->bindValue(':lastname', $lastname, SQLITE3_TEXT);
        $stmt->bindValue(':email', $email, SQLITE3_TEXT);
        $stmt->bindValue(':password', $hashedPassword, SQLITE3_TEXT);
        $stmt->bindValue(':main_currency', $main_currency_id, SQLITE3_TEXT);
        $stmt->bindValue(':avatar', $avatar, SQLITE3_TEXT);
        $stmt->bindValue(':language', $language, SQLITE3_TEXT);
        $stmt->bindValue(':budget', 0, SQLITE3_INTEGER);
        $stmt->bindValue(':api_key', bin2hex(random_bytes(32)), SQLITE3_TEXT);
        $result = $stmt->execute();

        if ($result) {

            // Get id of the newly created user
            $userId = $db->lastInsertRowID();

            // Add username as household member for that user
            $query = "INSERT INTO household (name, user_id) VALUES (:name, :user_id)";
            $stmt = $db->prepare($query);
            $stmt->bindValue(':name', $username, SQLITE3_TEXT);
            $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
            $stmt->execute();

            if ($userId > 1) {

                // Add categories for that user
                $query = 'INSERT INTO categories (name, "order", user_id) VALUES (:name, :order, :user_id)';
                $stmt = $db->prepare($query);
                foreach (default_categories($language) as $index => $name) {
                    $stmt->bindValue(':name', $name, SQLITE3_TEXT);
                    $stmt->bindValue(':order', $index + 1, SQLITE3_INTEGER);
                    $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
                    $stmt->execute();
                }

                // Add payment methods for that user
                $query = 'INSERT INTO payment_methods (name, icon, "order", user_id) VALUES (:name, :icon, :order, :user_id)';
                $stmt = $db->prepare($query);
                foreach (default_payment_methods($language) as $index => $payment_method) {
                    $stmt->bindValue(':name', $payment_method['name'], SQLITE3_TEXT);
                    $stmt->bindValue(':icon', $payment_method['icon'], SQLITE3_TEXT);
                    $stmt->bindValue(':order', $index + 1, SQLITE3_INTEGER);
                    $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
                    $stmt->execute();
                }

                // Add currencies for that user
                // [CN-PATCH 6] 写入数据库时就存中文名，而不是等界面翻译。
                // 原因是「设置 → 货币」页把 name 当普通文本编辑框直接展示，
                // 如果表里存 "Chinese Yuan"，用户在那里看到的仍是英文。
                // 这里在注册期做一次性映射，之后 name 就是可自由编辑的普通字段。
                $query = "INSERT INTO currencies (name, symbol, code, rate, user_id) VALUES (:name, :symbol, :code, :rate, :user_id)";
                $stmt = $db->prepare($query);
                foreach (default_currencies($language) as $currency) {
                    $stmt->bindValue(':name', $currency['name'], SQLITE3_TEXT);
                    $stmt->bindValue(':symbol', $currency['symbol'], SQLITE3_TEXT);
                    $stmt->bindValue(':code', $currency['code'], SQLITE3_TEXT);
                    $stmt->bindValue(':rate', 1, SQLITE3_FLOAT);
                    $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
                    $stmt->execute();
                }

                // Retrieve main currency id
                //
                // [CN-PATCH 14] 这是 user.main_currency 的唯一正确来源：
                // 按 ISO 代码查本实例数据库里刚插入的那一行。
                // currencies.id 是 AUTOINCREMENT，常量无法预知，不能写死。
                $query = "SELECT id FROM currencies WHERE code = :code AND user_id = :user_id";
                $stmt = $db->prepare($query);
                $stmt->bindValue(':code', $main_currency, SQLITE3_TEXT);
                $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
                $result = $stmt->execute();
                $currency = $result->fetchArray(SQLITE3_ASSOC);

                if ($currency === false) {
                    // 理论上不会发生：上面的循环刚插入了全部 DEFAULT_CURRENCIES。
                    // 但如果真发生了（比如 $main_currency 是伪造的表单值），
                    // 静默把 0 写进 user.main_currency 会让整个实例的货币
                    // 显示全部失效，且很难查。这里直接失败，留下明确线索。
                    die("无法确定主货币 '" . htmlspecialchars($main_currency) . "' 在数据库中的 id");
                }

                // Update user main currency
                $query = "UPDATE user SET main_currency = :main_currency WHERE id = :user_id";
                $stmt = $db->prepare($query);
                $stmt->bindValue(':main_currency', $currency['id'], SQLITE3_INTEGER);
                $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
                $stmt->execute();

                // Add settings for that user
                $query = "INSERT INTO settings (dark_theme, monthly_price, convert_currency, remove_background, color_theme, hide_disabled, user_id, disabled_to_bottom, show_original_price, mobile_nav, week_starts_sunday) 
                          VALUES (2, 0, 0, 0, 'blue', 0, :user_id, 0, 0, 0, 0)";
                $stmt = $db->prepare($query);
                $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
                $stmt->execute();

                // If email verification is required add the user to the email_verification table
                $query = "SELECT * FROM admin";
                $stmt = $db->prepare($query);
                $result = $stmt->execute();
                $settings = $result->fetchArray(SQLITE3_ASSOC);

                if ($settings['require_email_verification'] == 1) {
                    $query = "INSERT INTO email_verification (user_id, email, token, email_sent) VALUES (:user_id, :email, :token, 0)";
                    $stmt = $db->prepare($query);
                    $token = bin2hex(random_bytes(32));
                    $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
                    $stmt->bindValue(':token', $token, SQLITE3_TEXT);
                    $stmt->bindValue(':email', $email, SQLITE3_TEXT);
                    $stmt->execute();

                    $requireValidation = true;
                }
            } else {
                // The first account of an installation adopts the rows
                // createdatabase.php seeded before anybody had stated a
                // language, which is why it is not seeded above. This is the
                // first moment a language is known, so the rows that are
                // still the English default are renamed into it.
                localize_default_names($db, $userId, $language);
            }

            $db->close();
            header("Location: login.php?registered=true&requireValidation=$requireValidation");
            exit();
        } else {
            $registrationFailed = true;
        }
    }
}
?>
<!DOCTYPE html>
<html dir="<?= $languages[$lang]['dir'] ?>">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="theme-color" content="<?= $theme == "light" ? "#FFFFFF" : "#12151C" ?>" id="theme-color" />
    <meta name="apple-mobile-web-app-title" content="Wallos">
    <title>订阅台账 · SubDesk</title>
    <link rel="icon" type="image/png" href="images/icon/favicon.ico" sizes="16x16">
    <link rel="apple-touch-icon" href="images/icon/apple-touch-icon.png">
    <link rel="apple-touch-icon" sizes="152x152" href="images/icon/apple-touch-icon-152.png">
    <link rel="apple-touch-icon" sizes="180x180" href="images/icon/apple-touch-icon-180.png">
    <link rel="manifest" href="manifest.php">
    <link rel="stylesheet" href="styles/theme.css?<?= $version ?>">
    <link rel="stylesheet" href="styles/login.css?<?= $version ?>">
    <link rel="stylesheet" href="styles/themes/red.css?<?= $version ?>" id="red-theme" <?= $colorTheme != "red" ? "disabled" : "" ?>>
    <link rel="stylesheet" href="styles/themes/green.css?<?= $version ?>" id="green-theme" <?= $colorTheme != "green" ? "disabled" : "" ?>>
    <link rel="stylesheet" href="styles/themes/yellow.css?<?= $version ?>" id="yellow-theme" <?= $colorTheme != "yellow" ? "disabled" : "" ?>>
    <link rel="stylesheet" href="styles/themes/purple.css?<?= $version ?>" id="purple-theme" <?= $colorTheme != "purple" ? "disabled" : "" ?>>
    <link rel="stylesheet" href="styles/login-dark-theme.css?<?= $version ?>" id="dark-theme" <?= $theme == "light" ? "disabled" : "" ?>>
    <link rel="stylesheet" href="styles/font-awesome.min.css">
    <link rel="stylesheet" href="styles/barlow.css">
    <script type="text/javascript">
        window.update_theme_settings = "<?= $updateThemeSettings ?>";
        window.colorTheme = <?= json_encode($colorTheme, JSON_HEX_TAG | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_APOS) ?>;
    </script>
    <script type="text/javascript" src="scripts/registration.js?<?= $version ?>"></script>
    <script type="text/javascript" src="scripts/auth-theme.js?<?= $version ?>"></script>
    <script type="text/javascript" src="scripts/password-toggle.js?<?= $version ?>"></script>
</head>

<body class="<?= $languages[$lang]['dir'] ?>">
    <button type="button" class="theme-toggle" id="theme-toggle" title="<?= translate('theme', $i18n) ?>"
        aria-label="<?= translate('theme', $i18n) ?>">
        <i class="fa-solid <?= $theme == "dark" ? "fa-sun" : "fa-moon" ?>"></i>
    </button>
    <div class="content auth-split">
        <aside class="auth-brand" aria-hidden="true">
            <div class="auth-brand-logo">
                <?php include "images/siteicons/svg/logo.php"; ?>
            </div>
            <div class="auth-brand-text">
                <h1><?= translate('auth_tagline', $i18n) ?></h1>
                <p><?= translate('auth_tagline_sub', $i18n) ?></p>
            </div>
            <div class="auth-brand-footer">订阅台账 &mdash; 会员与订阅管理</div>
        </aside>
        <section class="container wide">
            <header>
                <div class="logo-image" title="订阅台账 · SubDesk">
                    <?php include "images/siteicons/svg/logo.php"; ?>
                </div>
                <p>
                    <?= translate('create_account', $i18n) ?>
                </p>
            </header>
            <form action="registration.php" method="post" class="registration-form">
                <div class="form-group">
                    <label for="username"><?= translate('username', $i18n) ?>:</label>
                    <input type="text" id="username" name="username" autocomplete="username" required>
                </div>
                <?php
                // [CN-PATCH 5] 去掉独立的「姓 / 名」两个字段，合并为一个「昵称」。
                //
                // 上游把 firstname / lastname 分开，是欧美命名习惯（Given name /
                // Family name）。中文场景下这两个词的对应关系本身就是错的
                // （"first name" 译成「名」、"last name」译成「姓」，但表单顺序是
                // 名在前姓在后，和中文习惯相反），而且大部分用户根本没有英文姓名的概念。
                //
                // 这里保留数据库两列不迁移（零风险），只改表单：
                // 昵称写入 firstname，lastname 恒为空字符串。
                // index.php 的问候语是 $user['firstname'] ?? $user['username']，
                // header.php 也走同样的回退，所以填了昵称就显示昵称，
                // 没填就回退用户名，不会出现空白。
                ?>
                <div class="form-group">
                    <label for="nickname"><?= translate('nickname', $i18n) ?>:</label>
                    <input type="text" id="nickname" name="nickname" autocomplete="nickname"
                        placeholder="<?= translate('nickname_placeholder', $i18n) ?>">
                </div>
                <div class="form-group">
                    <label for="email"><?= translate('email', $i18n) ?>:</label>
                    <input type="email" id="email" name="email" autocomplete="email" required>
                </div>
                <div class="form-group">
                    <label for="password"><?= translate('password', $i18n) ?>:</label>
                    <input type="password" id="password" name="password" autocomplete="new-password" required>
                </div>
                <div class="form-group">
                    <label for="confirm_password"><?= translate('confirm_password', $i18n) ?>:</label>
                    <input type="password" id="confirm_password" name="confirm_password" autocomplete="new-password" required>
                </div>
                <div class="form-group">
                    <label for="currency"><?= translate('main_currency', $i18n) ?>:</label>
                    <select id="currency" name="main_currency" placeholder="Currency">
                        <?php
                        // [CN-PATCH 6] 默认选中人民币。上游这里没有任何 option 带
                        // selected，浏览器就选第一个（Euro），新用户很容易漏改，
                        // 之后所有统计都按欧元折算。
                        foreach ($currencies as $currency) {
                            $currencySelected = ($currency['code'] === 'CNY') ? 'selected' : '';
                            $localizedName = translate('currency_' . strtolower($currency['code']), $i18n);
                            if ($localizedName === '[i18n String Missing]') {
                                $localizedName = $currency['name'];
                            }
                            ?>
                            <option value="<?= $currency['code'] ?>" <?= $currencySelected ?>>
                                <?= $localizedName ?> (<?= $currency['code'] ?>)
                            </option>
                            <?php
                        }
                        ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="language"><?= translate('language', $i18n) ?>:</label>
                    <select id="language" name="language" placeholder="Language" onchange="changeLanguage(this.value)">
                        <?php
                        foreach ($languages as $code => $language) {
                            $selected = ($code === $lang) ? 'selected' : '';
                            ?>
                            <option value="<?= $code ?>" <?= $selected ?>><?= $language['name'] ?></option>
                            <?php
                        }
                        ?>
                    </select>
                </div>

                <?php
                if ($hasErrors) {
                    ?>
                    <ul class="error-box">
                        <?php
                        if ($passwordMismatch) {
                            ?>
                            <li><i class="fa-solid fa-triangle-exclamation"></i><?= translate('passwords_dont_match', $i18n) ?>
                            </li>
                            <?php
                        }
                        ?>
                        <?php
                        if ($usernameExists) {
                            ?>
                            <li><i class="fa-solid fa-triangle-exclamation"></i><?= translate('username_exists', $i18n) ?></li>
                            <?php
                        }
                        ?>
                        <?php
                        if ($emailExists) {
                            ?>
                            <li><i class="fa-solid fa-triangle-exclamation"></i><?= translate('email_exists', $i18n) ?></li>
                            <?php
                        }
                        ?>
                        <?php
                        if ($registrationFailed) {
                            ?>
                            <li><i class="fa-solid fa-triangle-exclamation"></i><?= translate('registration_failed', $i18n) ?>
                            </li>
                            <?php
                        }
                        ?>
                    </ul>
                    <?php
                }
                ?>


                <div class="form-group">
                    <input type="submit" value="<?= translate('register', $i18n) ?>">
                </div>
            </form>
            <?php
            if ($userCount == 0) {
                ?>
                <div class="separator">
                    <input type="button" class="secondary-button" value="<?= translate('restore_database', $i18n) ?>"
                        onClick="openRestoreModal()" />
                </div>
                <?php
            } else {
                ?>
                <div class="login-form-link account-switch">
                    <span><?= translate('already_have_account', $i18n) ?></span>
                    <a href="login.php"><?= translate('login', $i18n) ?></a>
                </div>
                <?php
            }
            ?>
        </section>
    </div>
    <?php if ($userCount == 0) { ?>
    <div id="restoreModalBackdrop" class="modal-backdrop" onclick="closeRestoreModal()">
        <div id="restoreModal" class="subscription-modal" onclick="event.stopPropagation()">
            <div class="modal-header">
                <h3><?= translate('restore_database', $i18n) ?></h3>
                <span class="fa-solid fa-xmark close-modal" onclick="closeRestoreModal()"></span>
            </div>
            <div class="modal-body">
                <p><?= translate('restore_database_info', $i18n) ?></p>
                <ul>
                    <li><?= translate('setup_token_docker', $i18n) ?></li>
                    <li><?= translate('setup_token_file', $i18n) ?></li>
                </ul>
                <div class="form-group">
                    <input type="text" id="setupToken" placeholder="<?= translate('setup_token', $i18n) ?>" autocomplete="off">
                </div>
                <div class="form-group">
                    <input type="button" class="secondary-button" value="<?= translate('select_backup_file', $i18n) ?>"
                        onClick="openRestoreDBFileSelect()" />
                    <input type="file" name="restoreDBFile" id="restoreDBFile" style="display: none;" onChange="onRestoreFileSelected()" accept=".zip">
                    <span id="restoreFileName" style="font-size: 14px; margin-left: 8px;"></span>
                </div>
                <div class="form-group">
                    <input type="button" value="<?= translate('restore_database', $i18n) ?>" onClick="restoreDB()" />
                </div>
            </div>
        </div>
    </div>
    <?php } ?>
    <?php
    require_once 'includes/footer.php';
    ?>
</body>

</html>
