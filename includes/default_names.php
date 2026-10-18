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

  Currencies were deliberately left out of this file: their names are a separate
  list with a separate problem (a currency is identified by its ISO code, not by
  its name), and folding them in would have made the original change about
  something else.

  [CN-PATCH 12] That decision turned out to be the reason a localization patch
  missed two of the four places an account is created. The four copies of the
  currency list - createdatabase.php, registration.php, admin/adduser.php and
  oidc_create_user.php - have to agree, and nothing made them agree. Patching two
  of them left new accounts created through the admin panel or an identity
  provider with 34 English currencies. DEFAULT_CURRENCIES below is the single
  definition; the four call sites read it instead of carrying their own copy.
  Same contract as the categories: what is stored is ordinary editable text, and
  the ISO code, not the name, is the identity.
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
 * A generic term - "Credit Card", "Bank Transfer", "Direct Debit", "Money" -
 * reads differently to a German and to an English speaker, so it carries a
 * translation "key". A brand is the same word in every language, so it keeps a
 * literal "name".
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
 * The currencies a new account starts with, in display order.
 *
 * [CN-PATCH 12] Chinese-market default: eleven currencies, CNY first. Upstream
 * seeded thirty-four, starting with the Euro, which meant a registration form
 * with no preselected option handed a Chinese user a Euro-denominated account.
 *
 * 'name' is the English name; the localized name is resolved at account creation
 * via translate('currency_' . strtolower($code)), falling back to this name when
 * the language file has no key for it.
 *
 * [CN-PATCH 14] There is deliberately no 'id' here, and this is the fix for a
 * bug the previous version caused.
 *
 * The currencies table has `id INTEGER PRIMARY KEY AUTOINCREMENT`, so the row id
 * is assigned by insertion order, not by anything the caller asks for. The
 * previous version carried upstream's row ids (CNY => 20, USD => 2, ...) because
 * upstream's own seed happens to insert all thirty-four in that same order, so
 * the numbers lined up. Shortening the list to eleven broke the coincidence: the
 * rows now get ids 1..11, but registration.php wrote user.main_currency = 20
 * (the id it believed CNY had) into the user row. Nothing pointed at row 20, so
 * calendar.php's `INNER JOIN currencies ON c.id = u.main_currency` matched no
 * row, $row['code'] was null, and CurrencyFormatter::format() raised a
 * TypeError - the two "NumberFormatter::formatCurrency(): Argument #2 must be of
 * type string, null given" boxes on the calendar page.
 *
 * The id is not data this file can know: it belongs to the database. Callers
 * that need it must look it up by ISO code after inserting the rows - which is
 * what the 'Retrieve main currency id' step in registration.php already does.
 * What this constant supplies is the identifier that is stable across
 * installations: the ISO code.
 *
 * @var array<int, array{name: string, symbol: string, code: string}>
 */
const DEFAULT_CURRENCIES = [
    ['name' => 'Chinese Yuan', 'symbol' => '¥', 'code' => 'CNY'],
    ['name' => 'US Dollar', 'symbol' => '$', 'code' => 'USD'],
    ['name' => 'Hong Kong Dollar', 'symbol' => 'HK$', 'code' => 'HKD'],
    ['name' => 'Japanese Yen', 'symbol' => '¥', 'code' => 'JPY'],
    ['name' => 'Euro', 'symbol' => '€', 'code' => 'EUR'],
    ['name' => 'British Pound Sterling', 'symbol' => '£', 'code' => 'GBP'],
    ['name' => 'New Taiwan Dollar', 'symbol' => 'NT$', 'code' => 'TWD'],
    ['name' => 'Singapore Dollar', 'symbol' => 'S$', 'code' => 'SGD'],
    ['name' => 'South Korean Won', 'symbol' => '₩', 'code' => 'KRW'],
    ['name' => 'Australian Dollar', 'symbol' => '$', 'code' => 'AUD'],
    ['name' => 'Canadian Dollar', 'symbol' => '$', 'code' => 'CAD'],
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
 * The default currencies for one language, with localized names.
 *
 * [CN-PATCH 12] Mirrors default_categories() / default_payment_methods():
 * resolves at the moment the account is created, stores plain text, never
 * re-translates later.
 *
 * [CN-PATCH 14] The returned rows carry no 'id' - see the note on
 * DEFAULT_CURRENCIES. Callers that need the row id look it up by code.
 *
 * @param string $language
 * @return array<int, array{name: string, symbol: string, code: string}>
 */
function default_currencies($language)
{
    $translations = default_names_translations($language);

    $currencies = [];
    foreach (DEFAULT_CURRENCIES as $currency) {
        $key = 'currency_' . strtolower($currency['code']);
        $currency['name'] = $translations[$key] ?? $currency['name'];
        $currencies[] = $currency;
    }

    return $currencies;
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
 * @param SQLite3  $db
 * @param int      $userId
 * @param string   $language
 * @param string[] $from The languages the account's default names may still be
 *                       in. English by default, because that is what the rows
 *                       of an installation and of a first account are seeded in.
 * @return int how many rows were renamed
 */
function localize_default_names($db, $userId, $language, array $from = ['en'])
{
    // The language a name is already in has nothing to be renamed to.
    $from = array_values(array_diff(array_unique($from), [$language]));

    $renamed = 0;

    $renamed += default_names_rename(
        $db,
        'categories',
        $userId,
        array_map('default_categories', $from),
        default_categories($language)
    );

    $renamed += default_names_rename(
        $db,
        'payment_methods',
        $userId,
        array_map(fn($source) => array_column(default_payment_methods($source), 'name'), $from),
        array_column(default_payment_methods($language), 'name')
    );

    return $renamed;
}

/**
 * Renames an account's still-default names when it changes its language.
 *
 * Seeding in the account's language covers accounts that are created after it
 * existed; an account that switches its language later kept the names it was
 * seeded with, so a household that moved Wallos to German went on reading
 * "Entertainment" and "Credit Card" in a German interface.
 *
 * The guard is here rather than at the call site so it can be tested: a save
 * that does not touch the language must not rename anything, and neither must
 * a save that "changes" it to the value it already had.
 *
 * Only rows that still carry a default are renamed, which is
 * localize_default_names()'s own promise: a name somebody chose is never
 * rewritten, and subscriptions reference a category or payment method by id,
 * so nothing but the name column moves.
 *
 * The default a row carries is the one of the language the account had until
 * now - German names after a switch to German, so a later switch to French has
 * to start from those - or English, which is what an account has whose names
 * were seeded before it ever chose a language.
 *
 * @param SQLite3 $db
 * @param int     $userId
 * @param string  $before The language the account had.
 * @param string  $after  The language it has now.
 * @return int how many rows were renamed
 */
function localize_default_names_on_language_change($db, $userId, $before, $after)
{
    if ($after === '' || $before === $after) {
        return 0;
    }

    return localize_default_names($db, $userId, $after, [$before, 'en']);
}

/**
 * Renames the rows of one table whose name is a default, into the language
 * given.
 *
 * Every row to rename is found before any is written, and each is renamed by
 * id. Renaming name by name would let one rename feed the next: a name that
 * one language gives to the first default and another language to the second
 * would be renamed twice on the way through.
 *
 * A row that matches the defaults of more than one source language is
 * renamed from the first of them.
 *
 * @param SQLite3    $db
 * @param string     $table     "categories" or "payment_methods"
 * @param int        $userId
 * @param string[][] $fromLists the names a still-default row carries, one
 *                              equally ordered list per source language
 * @param string[]   $localized what it should say instead
 * @return int how many rows were renamed
 */
function default_names_rename($db, $table, $userId, array $fromLists, $localized)
{
    $find = $db->prepare("SELECT id FROM $table WHERE user_id = :user_id AND name = :name");
    $rename = $db->prepare("UPDATE $table SET name = :name WHERE id = :id AND user_id = :user_id");

    if ($find === false || $rename === false) {
        return 0;
    }

    $moves = [];

    foreach ($fromLists as $names) {
        foreach ($names as $index => $name) {
            // A brand, or a language that has no translation for this name yet.
            if (!isset($localized[$index]) || $localized[$index] === $name) {
                continue;
            }

            $find->bindValue(':name', $name, SQLITE3_TEXT);
            $find->bindValue(':user_id', $userId, SQLITE3_INTEGER);
            $result = $find->execute();

            while ($result !== false && ($row = $result->fetchArray(SQLITE3_ASSOC))) {
                if (!isset($moves[$row['id']])) {
                    $moves[$row['id']] = $localized[$index];
                }
            }

            $find->reset();
        }
    }

    $renamed = 0;

    foreach ($moves as $id => $name) {
        $rename->bindValue(':name', $name, SQLITE3_TEXT);
        $rename->bindValue(':id', $id, SQLITE3_INTEGER);
        $rename->bindValue(':user_id', $userId, SQLITE3_INTEGER);

        if ($rename->execute() !== false) {
            $renamed += $db->changes();
        }

        $rename->reset();
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
