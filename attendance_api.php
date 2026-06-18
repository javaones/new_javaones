<?php
$host = 'localhost';
$user = 'root';
$pass = 'admin';
$dbname = 'demo_site';
$charset = 'utf8mb4';

header('Content-Type: application/json; charset=utf-8');

$dsn = "mysql:host={$host};dbname={$dbname};charset={$charset}";
$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => '数据库连接失败', 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo->exec("CREATE TABLE IF NOT EXISTS teacher (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$pdo->exec("CREATE TABLE IF NOT EXISTS sign_in_records (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    teacher_name VARCHAR(100) NOT NULL,
    sign_date DATE NOT NULL,
    period_7_status TINYINT(1) NOT NULL DEFAULT 0,
    period_8_status TINYINT(1) NOT NULL DEFAULT 0,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_teacher_date (teacher_name, sign_date),
    INDEX idx_sign_date (sign_date),
    INDEX idx_teacher_name (teacher_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$action = $_GET['action'] ?? $_POST['action'] ?? 'teachers';

function respond(array $data): void {
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'teachers') {
    $stmt = $pdo->query("SELECT name FROM teacher WHERE name IS NOT NULL AND name <> '' ORDER BY id ASC");
    respond(['success' => true, 'teachers' => $stmt->fetchAll(PDO::FETCH_COLUMN)]);
}

if ($action === 'month_records') {
    $teacher = trim((string)($_GET['teacher'] ?? ''));
    $year = (int)($_GET['year'] ?? date('Y'));
    $month = (int)($_GET['month'] ?? date('n'));
    if ($teacher === '') respond(['success' => true, 'records' => []]);

    $start = sprintf('%04d-%02d-01', $year, $month);
    $end = date('Y-m-t', strtotime($start));
    $stmt = $pdo->prepare("SELECT sign_date, period_7_status, period_8_status FROM sign_in_records WHERE teacher_name = :teacher AND sign_date BETWEEN :start AND :end ORDER BY sign_date ASC");
    $stmt->execute([':teacher' => $teacher, ':start' => $start, ':end' => $end]);
    $rows = $stmt->fetchAll();
    $records = [];
    foreach ($rows as $row) {
        $records[$row['sign_date']] = [
            'period7' => (bool)$row['period_7_status'],
            'period8' => (bool)$row['period_8_status'],
        ];
    }
    respond(['success' => true, 'records' => $records]);
}

if ($action === 'save_record') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) $input = $_POST;
    $teacher = trim((string)($input['teacher_name'] ?? ''));
    $signDate = trim((string)($input['sign_date'] ?? ''));
    $period = trim((string)($input['period'] ?? ''));
    $status = !empty($input['status']) ? 1 : 0;

    if ($teacher === '' || $signDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $signDate) || !in_array($period, ['period7', 'period8'], true)) {
        http_response_code(400);
        respond(['success' => false, 'message' => '参数错误']);
    }

    $pdo->prepare("INSERT INTO sign_in_records (teacher_name, sign_date, period_7_status, period_8_status) VALUES (:teacher, :sign_date, :p7, :p8) ON DUPLICATE KEY UPDATE period_7_status = VALUES(period_7_status), period_8_status = VALUES(period_8_status), updated_at = CURRENT_TIMESTAMP")
        ->execute([
            ':teacher' => $teacher,
            ':sign_date' => $signDate,
            ':p7' => $period === 'period7' ? $status : 0,
            ':p8' => $period === 'period8' ? $status : 0,
        ]);

    respond(['success' => true]);
}

http_response_code(404);
respond(['success' => false, 'message' => '未知操作']);
