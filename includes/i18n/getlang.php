<?php

// [CN-PATCH 3] 语言选择顺序：Cookie → 浏览器 Accept-Language → 中文兜底。
//
// 上游这里只有 Cookie 和硬编码的 "en"，所以未登录访客、以及从没点过语言切换的
// 新用户看到的永远是英文界面。国内部署下这个默认值没有意义。
//
// 现在仍然尊重用户的显式选择（Cookie 优先，浏览器语言其次），
// 只有当两者都给不出结论时才落到 zh_cn，而不是 en。
//
// resolve_language() 会把 "zh-CN"、"zh-Hans" 这类标签归一化到 zh_cn。

if (isset($_COOKIE['language'])) {
    $lang = resolve_language($_COOKIE['language']);
} else {
    $lang = function_exists('resolve_language')
        ? resolve_language(isset($_SERVER['HTTP_ACCEPT_LANGUAGE']) ? $_SERVER['HTTP_ACCEPT_LANGUAGE'] : '')
        : 'zh_cn';

    // resolve_language() 对空值和无匹配都返回 'en'。在中文优先的部署里，
    // 这不该是终点：浏览器没有声明偏好（curl、cron、API 调用）时用中文。
    if ($lang === 'en' && !preg_match('/\ben\b/i', isset($_SERVER['HTTP_ACCEPT_LANGUAGE']) ? $_SERVER['HTTP_ACCEPT_LANGUAGE'] : '')) {
        $lang = 'zh_cn';
    }
}

function translate($text, $translations)
{
    if (array_key_exists($text, $translations)) {
        return $translations[$text];
    } else {
        require 'en.php';
        if (array_key_exists($text, $i18n)) {
            return $i18n[$text];
        } else {
            return "[i18n String Missing]";
        }
    }
}

?>
