<?php
/*
  The categories and payment methods a new account starts with, in the language
  of that account.

  The lists were repeated verbatim in every place that creates an account -
  registration.php, endpoints/admin/adduser.php and
  includes/oidc/oidc_create_user.php - and every copy was English. Somebody who
  registered in German got an application that was German everywhere except in
  its own data, on the very first screen they saw.

  The categories and the generic payment methods are held here as translation
  keys and resolved once, at the moment the account is created. What is stored
  is ordinary text the owner renames, reorders and deletes like any other row,
  so changing the account language later never rewrites it - the same contract
  the rows have always had.

  Brand names - PayPal, SEPA, Klarna - are the same word in every language and
  stay literal.

  Currencies are deliberately not here. Their names are a separate list with a
  separate problem (a currency is identified by its ISO code, not by its name),
  and folding them in would make this change about something else.
*/

/**
 * Translation keys of the default categories, in display order.
 *
 * The keys are the contract; the English strings live in the language files
 * like every other translation. "no_category" is an existing key, translated
 * in every language file already.
 *
 * @var string[]
 */
const DEFAULT_CATEGORY_KEYS = [
    'no_category',
    'category_entertainment',
    'category_music',
    'category_utilities',
    'category_food_and_beverages',
    'category_health_and_wellbeing',
    'category_productivity',
    'category_banking',
    'category_transport',
    'category_education',
    'category_insurance',
    'category_gaming',
    'category_news_and_magazines',
    'category_software',
    'category_technology',
    'category_cloud_services',
    'category_charity_and_donations',
];

/**
 * The default payment methods, in display order.
 *
 * [CN-PATCH 2] 国内使用场景：这张表决定「设置 → 支付方式」的初始列表，
 * 也决定订阅表单里支付方式下拉框的顺序。
 *
 * 上游默认是欧洲/北美方案（PayPal、SEPA、Klarna、GiroPay、iDeal…），
 * 国内用户基本用不上。这里改成以支付宝/微信/银联/银行卡/花呗/白条为主，
 * 海外那几个（PayPal / Apple Pay / Google Pay）放在最后保留，
 * 因为港区 Netflix、美区 ChatGPT 这类订阅仍然需要。
 *
 * 注意：没有对应图标的条目复用最接近的内置图标，
 * 否则设置页会显示裂图。图标名对应 images/uploads/icons/ 下的文件。
 *
 * @var array<int, array{name?: string, key?: string, icon: string}>
 */
const DEFAULT_PAYMENT_METHODS = [
    ['name' => '支付宝', 'icon' => 'images/uploads/icons/alipay.png'],
    ['name' => '微信支付', 'icon' => 'images/uploads/icons/wechat.png'],
    ['name' => '银联云闪付', 'icon' => 'images/uploads/icons/unionpay.png'],
    ['key' => 'payment_method_credit_card', 'icon' => 'images/uploads/icons/creditcard.png'],
    ['key' => 'payment_method_bank_transfer', 'icon' => 'images/uploads/icons/banktransfer.png'],
    ['name' => '花呗', 'icon' => 'images/uploads/icons/alipay.png'],
    ['name' => '京东白条', 'icon' => 'images/uploads/icons/creditcard.png'],
    ['name' => '虚拟信用卡', 'icon' => 'images/uploads/icons/creditcard.png'],
    ['key' => 'payment_method_direct_debit', 'icon' => 'images/uploads/icons/directdebit.png'],
    ['key' => 'payment_method_money', 'icon' => 'images/uploads/icons/money.png'],
    // 海外订阅仍可能用到，保留在列表末尾
    ['name' => 'PayPal', 'icon' => 'images/uploads/icons/paypal.png'],
    ['name' => 'Apple Pay', 'icon' => 'images/uploads/icons/applepay.png'],
    ['name' => 'Google Pay', 'icon' => 'images/uploads/icons/googlepay.png'],
];

/**
 * The translation table of one specific language.
 *
 * translate() answers in the language of the current request, which is not the
 * language of the account being created: an administrator working in English
 * creates an account for somebody whose language is German, and an identity
 * provider names the language of an account nobody is looking at yet.
 *
 * A key the language file does not carry falls back to English, so a
 * translation that has not been updated yet produces English rather than
 * nothing.
 *
 * @param string $language
 * @return array<string, string>
 */
function default_names_translations($language)
{
    static $tables = [];

    // The value reaches this from a form field, an OIDC claim or the user
    // table, and it decides a file name, so it is not taken on trust: anything
    // that is not a plain identifier with a translation file is English, which
    // is what the callers stored before.
    $name = strtolower(str_replace('-', '_', trim((string) $language)));

    if (preg_match('/^[a-z]{2}(_[a-z]{2,4})?$/', $name) !== 1
        || !is_file(__DIR__ . '/i18n/' . $name . '.php')) {
        $name = 'en';
    }

    if (!isset($tables[$name])) {
        // The language files assign $i18n; required from inside a function it
        // stays local, which is what makes loading a second language safe.
        $i18n = [];
        require __DIR__ . '/i18n/' . $name . '.php';
        $table = $i18n;

        if ($name !== 'en') {
            $i18n = [];
            require __DIR__ . '/i18n/en.php';
            $table += $i18n;
        }

        $tables[$name] = $table;
    }

    return $tables[$name];
}

/**
 * The default categories in one language, in display order.
 *
 * @param string $language
 * @return string[]
 */
function default_categories($language)
{
    $translations = default_names_translations($language);

    $categories = [];
    foreach (DEFAULT_CATEGORY_KEYS as $key) {
        $categories[] = $translations[$key] ?? $key;
    }

    return $categories;
}

/**
 * The default payment methods in one language, in display order.
 *
 * @param string $language
 * @return array<int, array{name: string, icon: string}>
 */
function default_payment_methods($language)
{
    $translations = default_names_translations($language);

    $methods = [];
    foreach (DEFAULT_PAYMENT_METHODS as $method) {
        $methods[] = [
            'name' => isset($method['key'])
                ? ($translations[$method['key']] ?? $method['key'])
                : $method['name'],
            'icon' => $method['icon'],
        ];
    }

    return $methods;
}

/**
 * Renames the English default rows of the first account into its language.
 *
 * The first account of an installation is the one case where the rows exist
 * before a language does: createdatabase.php seeds the categories and payment
 * methods while the database is being created, and migration 000020 gives them
 * user_id 1, so registration.php never seeds them for that account. Its owner
 * picks a language on the registration form - the first moment anybody states
 * one - and gets English data anyway.
 *
 * Only a row whose name is still exactly the English default is touched, so
 * this cannot rewrite something somebody named themselves, and only the name
 * column changes: subscriptions reference a category and a payment method by
 * id, never by name.
 *
 * @param SQLite3 $db
 * @param int     $userId
 * @param string  $language
 * @return int how many rows were renamed
 */
function localize_default_names($db, $userId, $language)
{
    $renamed = 0;

    $renamed += default_names_rename(
        $db,
        $db->prepare('UPDATE categories SET name = :name WHERE user_id = :user_id AND name = :english'),
        $userId,
        default_categories('en'),
        default_categories($language)
    );

    $renamed += default_names_rename(
        $db,
        $db->prepare('UPDATE payment_methods SET name = :name WHERE user_id = :user_id AND name = :english'),
        $userId,
        array_column(default_payment_methods('en'), 'name'),
        array_column(default_payment_methods($language), 'name')
    );

    return $renamed;
}

/**
 * Runs one prepared rename over a pair of equally ordered name lists.
 *
 * @param SQLite3           $db
 * @param SQLite3Stmt|false $stmt
 * @param int               $userId
 * @param string[]          $english   the name a still-default row carries
 * @param string[]          $localized what it should say instead
 * @return int how many rows were renamed
 */
function default_names_rename($db, $stmt, $userId, $english, $localized)
{
    if ($stmt === false) {
        return 0;
    }

    $renamed = 0;

    foreach ($english as $index => $englishName) {
        // A brand, or a language that has no translation for this name yet.
        if ($localized[$index] === $englishName) {
            continue;
        }

        $stmt->bindValue(':name', $localized[$index], SQLITE3_TEXT);
        $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
        $stmt->bindValue(':english', $englishName, SQLITE3_TEXT);

        if ($stmt->execute() !== false) {
            $renamed += $db->changes();
        }

        $stmt->reset();
    }

    return $renamed;
}

/**
 * The "No category" text a fresh account in the given language got as its
 * untouched placeholder name.
 *
 * categories.id is one global sequence shared by every account, not one per
 * user, so only the very first row this application ever created is actually
 * id 1 - nothing here can tell "the placeholder row" apart from an ordinary
 * category by id. Comparing against the literal English string had the same
 * problem the other way once this file gave every language its own default:
 * a category still carrying its untouched default name no longer answers
 * "No category" for anybody who registered in a language other than English,
 * so it stopped being recognised as the placeholder at all.
 *
 * What a display site actually wants to know is whether this row is still
 * exactly what the account was seeded with, so it compares against that
 * account's own creation-language default, not a literal English string, and
 * re-translates it into whichever language is being viewed right now. A
 * category the owner has renamed matches neither and is shown as stored,
 * same as before this existed.
 *
 * @param string $language
 * @return string
 */
function default_no_category_name($language)
{
    $translations = default_names_translations($language);

    return $translations['no_category'] ?? 'No category';
}
