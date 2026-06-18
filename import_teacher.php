<?php
// import_teacher.php
// 从 CSV 导入教师名单到 demo_site.teacher

header('Content-Type: text/html; charset=utf-8');

$dbHost = '127.0.0.1';
$dbName = 'demo_site';
$dbUser = 'root';
$dbPass = 'admin';
$csvPath = 'C:/Users/javaones-xuan/Desktop/teacher.csv';

function h($str) {
    return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8');
}

function detectEncoding($content) {
    $enc = mb_detect_encoding($content, ['UTF-8', 'GBK', 'GB2312', 'BIG5', 'ISO-8859-1'], true);
    return $enc ?: 'GBK';
}

function normalizeText($text) {
    $text = trim($text);
    $text = preg_replace('/^\xEF\xBB\xBF/', '', $text);
    return $text;
}

try {
    if (!extension_loaded('pdo_mysql')) {
        throw new Exception('未启用 PDO MySQL 扩展。');
    }

    if (!file_exists($csvPath)) {
        throw new Exception('CSV 文件不存在：' . $csvPath);
    }

    $pdo = new PDO(
        "mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4",
        $dbUser,
        $dbPass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );

    $pdo->exec("CREATE TABLE IF NOT EXISTS `teacher` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `name` VARCHAR(100) NOT NULL,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uk_teacher_name` (`name`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

    $raw = file_get_contents($csvPath);
    if ($raw === false) {
        throw new Exception('无法读取 CSV 文件。');
    }

    $encoding = detectEncoding($raw);
    $content = mb_convert_encoding($raw, 'UTF-8', $encoding);
    $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);

    $lines = preg_split('/\R/u', $content);
    $teachers = [];
    foreach ($lines as $line) {
        $name = normalizeText($line);
        if ($name === '') continue;
        if ($name === '姓名' || $name === '老师姓名') continue;
        $teachers[] = $name;
    }

    $teachers = array_values(array_unique($teachers));

    $stmt = $pdo->prepare('INSERT IGNORE INTO `teacher` (`name`) VALUES (:name)');
    $inserted = 0;
    foreach ($teachers as $name) {
        $stmt->execute([':name' => $name]);
        if ($stmt->rowCount() > 0) $inserted++;
    }

    $total = count($teachers);

    echo '<!doctype html><html lang="zh-CN"><head><meta charset="utf-8"><title>导入完成</title>';
    echo '<style>body{font-family:Arial,"Microsoft YaHei",sans-serif;background:#f5f7fb;padding:24px;color:#111827}.card{max-width:760px;margin:0 auto;background:#fff;border-radius:16px;padding:24px;box-shadow:0 10px 30px rgba(0,0,0,.08)}code{background:#f3f4f6;padding:2px 6px;border-radius:6px}</style>';
    echo '</head><body><div class="card">';
    echo '<h2>教师名单导入完成</h2>';
    echo '<p>数据库：<code>' . h($dbName) . '</code></p>';
    echo '<p>表名：<code>teacher</code></p>';
    echo '<p>CSV 编码识别：<code>' . h($encoding) . '</code></p>';
    echo '<p>读取到教师数量：<strong>' . h($total) . '</strong></p>';
    echo '<p>新增写入数量：<strong>' . h($inserted) . '</strong></p>';
    echo '<hr><p>如需重复导入，可直接再次访问本页面，已存在姓名会自动忽略。</p>';
    echo '</div></body></html>';
} catch (Throwable $e) {
    http_response_code(500);
    echo '<!doctype html><html lang="zh-CN"><head><meta charset="utf-8"><title>导入失败</title>';
    echo '<style>body{font-family:Arial,"Microsoft YaHei",sans-serif;background:#f5f7fb;padding:24px;color:#111827}.card{max-width:760px;margin:0 auto;background:#fff;border-radius:16px;padding:24px;box-shadow:0 10px 30px rgba(0,0,0,.08)}pre{white-space:pre-wrap;background:#fef2f2;color:#b91c1c;padding:12px;border-radius:10px}</style>';
    echo '</head><body><div class="card">';
    echo '<h2>导入失败</h2>';
    echo '<pre>' . h($e->getMessage()) . '</pre>';
    echo '</div></body></html>';
}
