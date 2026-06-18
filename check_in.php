<?php
$host = 'localhost';
$user = 'root';
$pass = 'admin';
$dbname = 'demo_site';
$charset = 'utf8mb4';

try {
    $pdo = new PDO("mysql:host={$host};dbname={$dbname};charset={$charset}", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    die('数据库连接失败：' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
}

$pdo->exec("CREATE TABLE IF NOT EXISTS teachers (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    teacher_name VARCHAR(100) NOT NULL UNIQUE,
    employee_no VARCHAR(50) NOT NULL DEFAULT '' COMMENT '员工号',
    gender VARCHAR(20) NOT NULL DEFAULT '' COMMENT '性别',
    subject VARCHAR(100) NOT NULL DEFAULT '' COMMENT '科目',
    class_name VARCHAR(100) NOT NULL DEFAULT '' COMMENT '班级',
    grade VARCHAR(50) NOT NULL DEFAULT '' COMMENT '年级',
    KEY idx_grade_name (grade, teacher_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$pdo->exec("CREATE TABLE IF NOT EXISTS sign_in_records (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '签到记录ID（主键）',
    teacher_id BIGINT UNSIGNED NOT NULL COMMENT '教师ID（关联teachers.id）',
    sign_date DATE NOT NULL COMMENT '签到日期（如2025-06-06）',
    period_7_status TINYINT NOT NULL DEFAULT 0 COMMENT '第七节签到状态：0-未签到，1-已签到',
    period_7_time DATETIME DEFAULT NULL COMMENT '第七节实际签到时间',
    period_8_status TINYINT NOT NULL DEFAULT 0 COMMENT '第八节签到状态：0-未签到，1-已签到',
    period_8_time DATETIME DEFAULT NULL COMMENT '第八节实际签到时间',
    create_time DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '记录创建时间',
    update_time DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '记录更新时间',
    PRIMARY KEY (id),
    UNIQUE KEY uk_teacher_date (teacher_id, sign_date) COMMENT '唯一约束：一个教师一天只能有一条记录',
    KEY idx_teacher_date (teacher_id, sign_date) COMMENT '按教师+日期查询索引',
    KEY idx_sign_date_teacher (sign_date, teacher_id) COMMENT '按日期范围查询索引'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='教师签到记录表'");

$teacherNames = [];
try {
    $stmt = $pdo->query("SELECT teacher_name FROM teachers WHERE teacher_name IS NOT NULL AND teacher_name <> '' ORDER BY teacher_name ASC");
    $teacherNames = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e) {
    $teacherNames = [];
}

$teacherListWithGrades = [];
try {
    $stmt = $pdo->query("SELECT teacher_name, grade FROM teachers WHERE teacher_name IS NOT NULL AND teacher_name <> '' ORDER BY grade ASC, teacher_name ASC");
    $teacherListWithGrades = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $teacherListWithGrades = [];
}

if (isset($_GET['action'])) {
    header('Content-Type: application/json; charset=utf-8');
    $action = $_GET['action'];
    if ($action === 'teachers') {
        $stmt = $pdo->query("SELECT teacher_name FROM teachers WHERE teacher_name IS NOT NULL AND teacher_name <> '' ORDER BY teacher_name ASC");
        echo json_encode(['success' => true, 'teachers' => $stmt->fetchAll(PDO::FETCH_COLUMN)], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($action === 'grade_teachers') {
        $stmt = $pdo->query("SELECT grade, teacher_name FROM teachers WHERE teacher_name IS NOT NULL AND teacher_name <> '' ORDER BY grade ASC, teacher_name ASC");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $groups = [];
        foreach ($rows as $row) {
            $grade = trim((string)($row['grade'] ?? ''));
            if ($grade === '') $grade = '未分年级';
            if (!isset($groups[$grade])) $groups[$grade] = [];
            $groups[$grade][] = $row['teacher_name'];
        }
        echo json_encode(['success' => true, 'groups' => $groups], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($action === 'month_records') {
        $teacher = trim((string)($_GET['teacher'] ?? ''));
        $year = (int)($_GET['year'] ?? date('Y'));
        $month = (int)($_GET['month'] ?? date('n'));
        $start = sprintf('%04d-%02d-01', $year, $month);
        $end = date('Y-m-t', strtotime($start));
        $teacherStmt = $pdo->prepare("SELECT id FROM teachers WHERE teacher_name = :teacher LIMIT 1");
        $teacherStmt->execute([':teacher' => $teacher]);
        $teacherId = (int)($teacherStmt->fetchColumn() ?: 0);
        if ($teacherId <= 0) {
            echo json_encode(['success' => true, 'records' => []], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $stmt = $pdo->prepare("SELECT sign_date, period_7_status, period_8_status FROM sign_in_records WHERE teacher_id = :teacher_id AND sign_date BETWEEN :start AND :end ORDER BY sign_date ASC");
        $stmt->execute([':teacher_id' => $teacherId, ':start' => $start, ':end' => $end]);
        $records = [];
        foreach ($stmt->fetchAll() as $row) {
            if (!empty($row['sign_date'])) {
                $records[$row['sign_date']] = ['period7' => (bool)$row['period_7_status'], 'period8' => (bool)$row['period_8_status']];
            }
        }
        echo json_encode(['success' => true, 'records' => $records], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($action === 'all_month_records') {
        $year = (int)($_GET['year'] ?? date('Y'));
        $month = (int)($_GET['month'] ?? date('n'));
        $start = sprintf('%04d-%02d-01', $year, $month);
        $end = date('Y-m-t', strtotime($start));
        $stmt = $pdo->prepare("SELECT t.teacher_name, r.sign_date, r.period_7_status, r.period_8_status FROM teachers t LEFT JOIN sign_in_records r ON r.teacher_id = t.id AND r.sign_date BETWEEN :start AND :end ORDER BY t.teacher_name ASC, r.sign_date ASC");
        $stmt->execute([':start' => $start, ':end' => $end]);
        $records = [];
        foreach ($stmt->fetchAll() as $row) {
            $name = $row['teacher_name'];
            if (!isset($records[$name])) $records[$name] = [];
            if (!empty($row['sign_date'])) {
                $records[$name][$row['sign_date']] = ['period7' => (bool)$row['period_7_status'], 'period8' => (bool)$row['period_8_status']];
            }
        }
        echo json_encode(['success' => true, 'records' => $records], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($action === 'save_month' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true) ?: [];
        $teacher = trim((string)($input['teacher_name'] ?? ''));
        $year = (int)($input['year'] ?? 0);
        $month = (int)($input['month'] ?? 0);
        $records = $input['records'] ?? [];
        if ($teacher === '' || $year < 1970 || $month < 1 || $month > 12 || !is_array($records)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => '参数错误'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $teacherStmt = $pdo->prepare('SELECT id FROM teachers WHERE teacher_name = :teacher LIMIT 1');
        $teacherStmt->execute([':teacher' => $teacher]);
        $teacherId = (int)($teacherStmt->fetchColumn() ?: 0);
        if ($teacherId <= 0) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => '教师不存在'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $sql = "INSERT INTO sign_in_records (teacher_id, sign_date, period_7_status, period_7_time, period_8_status, period_8_time) VALUES (:teacher_id, :sign_date, :p7_status, :p7_time, :p8_status, :p8_time) ON DUPLICATE KEY UPDATE period_7_status = VALUES(period_7_status), period_7_time = VALUES(period_7_time), period_8_status = VALUES(period_8_status), period_8_time = VALUES(period_8_time), update_time = CURRENT_TIMESTAMP";
        $stmt = $pdo->prepare($sql);
        $pdo->beginTransaction();
        try {
            foreach ($records as $date => $record) {
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$date)) {
                    continue;
                }
                $p7 = !empty($record['period7']) ? 1 : 0;
                $p8 = !empty($record['period8']) ? 1 : 0;
                $stmt->execute([
                    ':teacher_id' => $teacherId,
                    ':sign_date' => $date,
                    ':p7_status' => $p7,
                    ':p7_time' => $p7 ? date('Y-m-d H:i:s') : null,
                    ':p8_status' => $p8,
                    ':p8_time' => $p8 ? date('Y-m-d H:i:s') : null,
                ]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
        exit;
    }
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => '未知操作'], JSON_UNESCAPED_UNICODE);
    exit;
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no" />
  <title>教师社团签到管理</title>
  <style>
    :root {
      --bg: #f4f7fb;
      --card: rgba(255,255,255,0.95);
      --green: #29b35f;
      --green-dark: #1c9a4d;
      --gray: #e7e8eb;
      --gray-text: #70757d;
      --text: #1f2937;
      --muted: #6b7280;
      --border: #e5e7eb;
      --shadow: 0 10px 30px rgba(15, 23, 42, 0.08);
    }
    * { box-sizing: border-box; }
    body {
      margin: 0;
      font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", "PingFang SC", "Microsoft YaHei", sans-serif;
      background: linear-gradient(180deg, #f2f8f4 0%, var(--bg) 180px, #f7f9fc 100%);
      color: var(--text);
    }
    .header {
      background: linear-gradient(135deg, #25a35a, #178f4a);
      color: white;
      padding: 18px 16px 20px;
      text-align: center;
      box-shadow: 0 4px 20px rgba(23, 143, 74, 0.25);
    }
    .header .title {
      font-size: 24px;
      font-weight: 800;
      letter-spacing: 1px;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 10px;
    }
    .header .title .icon {
      width: 28px;
      height: 28px;
      border-radius: 8px;
      background: rgba(255,255,255,0.18);
      display: inline-flex;
      align-items: center;
      justify-content: center;
    }
    .container {
      max-width: 1100px;
      margin: -18px auto 0;
      padding: 0 14px 24px;
    }
    .card {
      background: var(--card);
      border: 1px solid rgba(229,231,235,0.8);
      border-radius: 18px;
      box-shadow: var(--shadow);
      backdrop-filter: blur(8px);
      margin-bottom: 16px;
      overflow: hidden;
    }
    .top-card {
      padding: 16px;
    }
    .toolbar {
      display: flex;
      flex-wrap: wrap;
      gap: 12px;
      align-items: center;
      justify-content: space-between;
    }
    .teacher-select {
      display: flex;
      align-items: center;
      gap: 10px;
      flex-wrap: wrap;
    }
    .teacher-select label {
      white-space: nowrap;
    }
    .teacher-actions {
      display: flex;
      align-items: center;
      gap: 10px;
      flex-wrap: wrap;
    }
    .teacher-select label {
      font-weight: 700;
      color: #374151;
    }
    select {
      min-width: 180px;
      height: 42px;
      padding: 0 14px;
      border: 1px solid var(--border);
      border-radius: 12px;
      background: white;
      font-size: 15px;
      outline: none;
    }
    .month-box {
      display: flex;
      align-items: center;
      gap: 12px;
      color: #374151;
      font-weight: 700;
      flex-wrap: wrap;
    }
    .month-box select {
      min-width: 110px;
      height: 38px;
      border-radius: 10px;
      border: 1px solid var(--border);
      background: white;
      padding: 0 10px;
      font-size: 14px;
    }
    .today-btn,
    .weekend-btn,
    .save-btn {
      height: 38px;
      border: 0;
      border-radius: 10px;
      padding: 0 12px;
      font-weight: 800;
      cursor: pointer;
      white-space: nowrap;
    }
    .today-btn {
      background: #eefbf2;
      color: #178f4a;
    }
    .weekend-btn {
      background: #fff3e8;
      color: #c2410c;
    }
    .weekend-btn.active {
      background: #f97316;
      color: white;
    }
    .save-btn {
      background: linear-gradient(135deg, #22c55e, #16a34a);
      color: #ffffff;
      margin-left: 100px;
      min-width: 120px;
      height: 48px;
      font-size: 18px;
      border-radius: 14px;
      padding: 0 18px;
      box-shadow: 0 10px 22px rgba(34, 197, 94, 0.28);
      transition: transform .12s ease, filter .2s ease, box-shadow .2s ease;
    }
    .save-btn:hover {
      filter: brightness(1.03);
      box-shadow: 0 14px 28px rgba(34, 197, 94, 0.34);
      transform: translateY(-1px);
    }
    .save-btn:active {
      transform: translateY(0);
      box-shadow: 0 8px 16px rgba(34, 197, 94, 0.24);
    }
    .save-btn::before {
      content: '✓';
      display: inline-block;
      margin-right: 8px;
      font-weight: 900;
    }
    .stat-save-btn {
      margin-left: 0;
      width: 100%;
      min-width: 0;
      height: 48px;
      border-radius: 14px;
      font-size: 18px;
      padding: 0 18px;
    }
    .stat-save-wrap {
      display: flex;
      flex-direction: column;
      justify-content: center;
    }
    .hint {
      margin-top: 12px;
      color: var(--muted);
      font-size: 13px;
      display: flex;
      gap: 8px;
      align-items: center;
    }
    .table-wrap {
      padding: 14px;
      overflow-x: auto;
    }
    table {
      width: 100%;
      border-collapse: collapse;
      min-width: 680px;
      background: white;
      border-radius: 14px;
      overflow: hidden;
      table-layout: fixed;
    }
    th, td {
      border: 1px solid var(--border);
      padding: 10px 8px;
      text-align: center;
      vertical-align: middle;
    }
    th {
      background: linear-gradient(180deg, #f4fbf6, #eef8f2);
      font-size: 15px;
      font-weight: 800;
      color: #1f2937;
      line-height: 1.2;
    }
    th:nth-child(1), td.date-cell {
      width: 28%;
    }
    th:nth-child(2), th:nth-child(3) {
      width: 36%;
    }
    td.date-cell {
      text-align: left;
      font-weight: 700;
      color: #334155;
      white-space: nowrap;
    }
    .period-btn {
      border: 0;
      min-width: 120px;
      height: 38px;
      border-radius: 10px;
      font-size: 14px;
      font-weight: 700;
      cursor: pointer;
      transition: transform .08s ease, filter .2s ease, background .2s ease;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 6px;
      user-select: none;
    }
    .period-btn:active { transform: scale(0.98); }
    .period-btn.checked {
      background: linear-gradient(180deg, #36c46b, var(--green-dark));
      color: white;
      box-shadow: inset 0 -1px 0 rgba(255,255,255,0.18);
    }
    .period-btn.unchecked {
      background: var(--gray);
      color: var(--gray-text);
    }
    .stats {
      padding: 16px;
    }
    .section-title {
      display: flex;
      align-items: center;
      gap: 8px;
      font-size: 18px;
      font-weight: 800;
      margin-bottom: 12px;
      color: #1f2937;
    }
    .stats-grid {
      display: grid;
      grid-template-columns: repeat(5, minmax(0, 1fr));
      gap: 12px;
    }
    .stat {
      border-radius: 14px;
      padding: 14px 12px;
      background: linear-gradient(180deg, #fbfdff, #f5f7fb);
      border: 1px solid var(--border);
      text-align: center;
    }
    .stat .label { color: #64748b; font-size: 13px; margin-bottom: 8px; }
    .stat .value { font-size: 28px; font-weight: 900; color: #16a34a; line-height: 1; }
    .stat .unit { font-size: 14px; color: #64748b; margin-left: 6px; font-weight: 700; }
    .month-total {
      margin-top: 12px;
      text-align: center;
      color: #6b7280;
      font-size: 14px;
    }
    .export-wrap {
      position: sticky;
      bottom: 0;
      background: linear-gradient(180deg, rgba(247,249,252,0.1), rgba(247,249,252,0.96) 22%, rgba(247,249,252,1));
      padding: 14px 0 6px;
      margin-top: 10px;
      display: grid;
      gap: 10px;
    }
    .export-btn {
      width: 100%;
      height: 52px;
      border: 0;
      border-radius: 14px;
      background: linear-gradient(135deg, #22a55a, #198f49);
      color: white;
      font-size: 16px;
      font-weight: 800;
      cursor: pointer;
      box-shadow: 0 10px 20px rgba(25, 143, 73, 0.24);
    }
    .export-btn.secondary {
      background: linear-gradient(135deg, #2563eb, #1d4ed8);
      box-shadow: 0 10px 20px rgba(37, 99, 235, 0.24);
    }
    @media (max-width: 860px) {
      .stats-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
      .toolbar { align-items: flex-start; }
      .month-box { width: 100%; justify-content: space-between; }
    }
    @media (max-width: 640px) {
      .container { padding: 0 10px 18px; }
      .header .title { font-size: 20px; }
      select { min-width: 160px; width: 100%; }
      .teacher-select { width: 100%; }
      .teacher-select label { width: 100%; }
      .month-box { font-size: 14px; }
      table { min-width: 620px; }
      .period-btn { min-width: 108px; }
      .stats-grid { grid-template-columns: 1fr 1fr; }
    }
  </style>
</head>
<body>
  <div class="header">
    <div class="title"><span class="icon">✓</span>教师社团签到小程序</div>
  </div>

  <div class="container">
    <div class="card top-card">
      <div class="toolbar">
        <div class="teacher-select">
          <label for="gradeSelect">选择年级：</label>
          <select id="gradeSelect"></select>
          <label for="teacherSelect">选择老师：</label>
          <select id="teacherSelect"></select>
          <button class="weekend-btn" id="weekendBtn" type="button">隐藏周六日</button>
          <button class="save-btn" id="saveBtn" type="button">保存</button>
        </div>
        <div class="month-box">
          <span>📅</span>
          <select id="yearSelect" aria-label="选择年份"></select>
          <select id="monthSelect" aria-label="选择月份"></select>
          <button class="today-btn" id="todayBtn" type="button">返回本月</button>
        </div>
      </div>
      <div class="hint">💡 提示：点击按钮可签到/取消签到，绿色表示已签到，灰色表示未签到，签到后记得保存。</div>
    </div>

    <div class="card">
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th style="width:32%">日期</th>
              <th>第七节签到</th>
              <th>第八节签到</th>
            </tr>
          </thead>
          <tbody id="attendanceTbody"></tbody>
        </table>
      </div>
    </div>

    <div class="card stats">
      <div class="section-title">📊 本月统计 <span id="statTeacherName" style="font-size:14px;color:#64748b;font-weight:700"></span></div>
      <div class="stats-grid">
        <div class="stat"><div class="label">第七节签到次数</div><div class="value" id="stat7">0</div></div>
        <div class="stat"><div class="label">第八节签到次数</div><div class="value" id="stat8">0</div></div>
        <div class="stat"><div class="label">总签到次数</div><div class="value" id="statTotal">0</div></div>
        <div class="stat stat-save-wrap">
          <div class="label"> </div>
          <button class="save-btn stat-save-btn" id="saveBtn2" type="button">保存</button>
        </div>
      </div>
      <div class="month-total" id="monthTotalText"></div>
    </div>

    <div class="export-wrap">
      <button class="export-btn" id="exportBtn">📗 导出所有教师（CSV）</button>
      <button class="export-btn secondary" id="exportGradeBtn">📘 按年级导出（CSV）</button>
    </div>
  </div>

  <script>
    window.initialTeachers = <?php echo json_encode($teacherNames, JSON_UNESCAPED_UNICODE); ?>;
    const API_URL = 'check_in.php';
    const VIEW_KEY = 'teacher_attendance_view_v1';
    const WEEKEND_HIDE_KEY = 'teacher_attendance_hide_weekend_v1';

    const state = {
      teachers: [],
      gradeTeacherGroups: {},
      selectedGrade: '',
      selectedTeacher: '',
      viewYear: new Date().getFullYear(),
      viewMonth: new Date().getMonth() + 1,
      hideWeekends: false,
      records: {}
    };

    const gradeSelect = document.getElementById('gradeSelect');
    const teacherSelect = document.getElementById('teacherSelect');
    const tbody = document.getElementById('attendanceTbody');
    const yearSelect = document.getElementById('yearSelect');
    const monthSelect = document.getElementById('monthSelect');
    const todayBtn = document.getElementById('todayBtn');
    const weekendBtn = document.getElementById('weekendBtn');
    const statTeacherName = document.getElementById('statTeacherName');
    const stat7 = document.getElementById('stat7');
    const stat8 = document.getElementById('stat8');
    const statTotal = document.getElementById('statTotal');
    const statRate = document.getElementById('statRate');
    const monthTotalText = document.getElementById('monthTotalText');
    const exportBtn = document.getElementById('exportBtn');
    const exportGradeBtn = document.getElementById('exportGradeBtn');
    const saveBtn = document.getElementById('saveBtn');
    const saveBtn2 = document.getElementById('saveBtn2');

    async function fetchGradeTeacherGroups() {
      try {
        const res = await fetch(`${API_URL}?action=grade_teachers`);
        const data = await res.json();
        if (data.success && data.groups) return data.groups;
      } catch (e) {}
      return {};
    }

    async function fetchTeachers() {
      return Array.isArray(window.initialTeachers) ? window.initialTeachers : [];
    }

    function dedupeTeachers(list) {
      return [...new Set((Array.isArray(list) ? list : []).filter(Boolean))];
    }

    function loadViewState() {
      try {
        const saved = JSON.parse(localStorage.getItem(VIEW_KEY) || 'null');
        if (saved && typeof saved === 'object') return saved;
      } catch (e) {}
      return {};
    }

    function loadWeekendHideState() {
      const saved = localStorage.getItem(WEEKEND_HIDE_KEY);
      if (saved === null) return true;
      return saved === '1';
    }

    function saveWeekendHideState() {
      localStorage.setItem(WEEKEND_HIDE_KEY, state.hideWeekends ? '1' : '0');
    }

    function saveViewState() {
      localStorage.setItem(VIEW_KEY, JSON.stringify({
        selectedGrade: state.selectedGrade,
        selectedTeacher: state.selectedTeacher,
        viewYear: state.viewYear,
        viewMonth: state.viewMonth
      }));
    }

    async function fetchMonthRecords(teacher, year, month) {
      if (!teacher) return {};
      try {
        const res = await fetch(`${API_URL}?action=month_records&teacher=${encodeURIComponent(teacher)}&year=${year}&month=${month}`);
        const data = await res.json();
        if (data.success && data.records && typeof data.records === 'object') return data.records;
      } catch (e) {}
      return {};
    }

    async function saveMonthRecords() {
      if (!state.selectedTeacher) {
        alert('请先选择老师');
        return;
      }
      const monthKey = `${state.viewYear}-${pad(state.viewMonth)}`;
      const monthData = state.records[state.selectedTeacher]?.[monthKey] || {};
      const payloadRecords = {};
      Object.keys(monthData).forEach((date) => {
        payloadRecords[date] = {
          period7: !!monthData[date]?.period7,
          period8: !!monthData[date]?.period8,
        };
      });
      const saveBtn = document.getElementById('saveBtn');
      const originalText = saveBtn.textContent;
      saveBtn.disabled = true;
      saveBtn.textContent = '保存中...';
      try {
        const res = await fetch(`${API_URL}?action=save_month`, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            teacher_name: state.selectedTeacher,
            year: state.viewYear,
            month: state.viewMonth,
            records: payloadRecords
          })
        });
        const data = await res.json();
        if (!data.success) throw new Error(data.message || '保存失败');
        alert('保存成功');
      } catch (e) {
        alert(e.message || '保存失败');
      } finally {
        saveBtn.disabled = false;
        saveBtn.textContent = originalText;
      }
    }

    function pad(n) { return String(n).padStart(2, '0'); }
    function dateKey(y, m, d) { return `${y}-${pad(m)}-${pad(d)}`; }
    function monthDays(y, m) { return new Date(y, m, 0).getDate(); }
    function monthLabel(y, m) { return `${y}年${m}月`; }
    function weekdayText(dateObj) { return ['日','一','二','三','四','五','六'][dateObj.getDay()]; }

    function getDayRecord(teacher, y, m, d) {
      const month = state.records[teacher]?.[`${y}-${pad(m)}`] || {};
      return month[dateKey(y, m, d)] || { period7: false, period8: false };
    }

    function setDayRecordLocal(teacher, y, m, d, patch) {
      if (!state.records[teacher]) state.records[teacher] = {};
      const key = `${y}-${pad(m)}`;
      if (!state.records[teacher][key]) state.records[teacher][key] = {};
      const month = state.records[teacher][key];
      const date = dateKey(y, m, d);
      const current = month[date] || { teacher, date, period7: false, period8: false, updatedAt: '' };
      month[date] = { ...current, ...patch, teacher, date, updatedAt: new Date().toISOString() };
    }

    function gradeSortValue(grade) {
      const match = String(grade || '').match(/[一二三四五六]年级/);
      const map = { '一年级': 1, '二年级': 2, '三年级': 3, '四年级': 4, '五年级': 5, '六年级': 6 };
      return map[match ? match[0] : ''] || 999;
    }

    function renderGradeTeachers() {
      const groups = state.gradeTeacherGroups || {};
      const grades = Object.keys(groups).sort((a, b) => gradeSortValue(a) - gradeSortValue(b) || a.localeCompare(b, 'zh-Hans-CN'));
      const gradeOptions = ['所有年级', '未分年级', ...grades.filter(g => g !== '未分年级')];
      gradeSelect.innerHTML = gradeOptions.map(g => `<option value="${g}">${g}</option>`).join('');
      if (!state.selectedGrade || !gradeOptions.includes(state.selectedGrade)) {
        state.selectedGrade = '未分年级';
      }
      gradeSelect.value = state.selectedGrade;
      if (state.selectedGrade === '未分年级') {
        teacherSelect.innerHTML = '<option value="">未选择</option>';
        state.selectedTeacher = '';
        teacherSelect.value = '';
        return;
      }
      const teachers = dedupeTeachers(state.selectedGrade === '所有年级'
        ? state.teachers
        : (groups[state.selectedGrade] || []));
      teacherSelect.innerHTML = teachers.length
        ? teachers.map(t => `<option value="${t}">${t}</option>`).join('')
        : '<option value="">未选择</option>';
      state.selectedTeacher = teachers.includes(state.selectedTeacher) ? state.selectedTeacher : (teachers[0] || '');
      teacherSelect.value = state.selectedTeacher;
    }

    function renderMonthSelectors() {
      const currentYear = new Date().getFullYear();
      yearSelect.innerHTML = '';
      const opt = document.createElement('option');
      opt.value = currentYear;
      opt.textContent = `${currentYear}年`;
      yearSelect.appendChild(opt);
      yearSelect.value = String(currentYear);
      state.viewYear = currentYear;

      monthSelect.innerHTML = '';
      for (let m = 1; m <= 12; m++) {
        const optMonth = document.createElement('option');
        optMonth.value = m;
        optMonth.textContent = `${m}月`;
        monthSelect.appendChild(optMonth);
      }
      monthSelect.value = String(state.viewMonth);
    }

    function renderWeekendButton() {
      weekendBtn.textContent = state.hideWeekends ? '显示周六日' : '隐藏周六日';
      weekendBtn.classList.toggle('active', state.hideWeekends);
    }

    function renderMonth() {
      renderMonthSelectors();
      renderWeekendButton();
      const days = monthDays(state.viewYear, state.viewMonth);
      tbody.innerHTML = '';

      for (let d = 1; d <= days; d++) {
        const dt = new Date(state.viewYear, state.viewMonth - 1, d);
        const dow = dt.getDay();
        if (state.hideWeekends && (dow === 0 || dow === 6)) continue;
        const key = dateKey(state.viewYear, state.viewMonth, d);
        const rec = getDayRecord(state.selectedTeacher, state.viewYear, state.viewMonth, d);
        const row = document.createElement('tr');
        row.innerHTML = `
          <td class="date-cell">${state.viewMonth}月${d}日（周${weekdayText(dt)}）</td>
          <td><button class="period-btn ${rec.period7 ? 'checked' : 'unchecked'}" data-date="${key}" data-period="period7">${rec.period7 ? '✓ 已签到' : '○ 未签到'}</button></td>
          <td><button class="period-btn ${rec.period8 ? 'checked' : 'unchecked'}" data-date="${key}" data-period="period8">${rec.period8 ? '✓ 已签到' : '○ 未签到'}</button></td>
        `;
        tbody.appendChild(row);
      }
      updateStats();
      saveViewState();
    }

    function updateStats() {
      const days = monthDays(state.viewYear, state.viewMonth);
      const monthData = state.records[state.selectedTeacher]?.[`${state.viewYear}-${pad(state.viewMonth)}`] || {};
      let c7 = 0, c8 = 0, activeDays = 0;
      for (let d = 1; d <= days; d++) {
        const dt = new Date(state.viewYear, state.viewMonth - 1, d);
        const dow = dt.getDay();
        if (state.hideWeekends && (dow === 0 || dow === 6)) continue;
        activeDays++;
        const rec = monthData[dateKey(state.viewYear, state.viewMonth, d)] || {};
        if (rec.period7) c7++;
        if (rec.period8) c8++;
      }
      const total = c7 + c8;
      const expected = activeDays * 2;
      const rate = expected ? ((total / expected) * 100).toFixed(2) : '0.00';
      statTeacherName.textContent = `（${state.selectedTeacher}）`;
      stat7.textContent = c7;
      stat8.textContent = c8;
      statTotal.textContent = total;
      statRate.textContent = `${rate}%`;
      monthTotalText.textContent = state.hideWeekends
        ? `已隐藏周六日，本月应签到次数：${expected} 次（${activeDays} 天 × 2 节）`
        : `本月应签到次数：${expected} 次（${days} 天 × 2 节）`;
    }

    async function handleToggle(date, period) {
      const [y, m, d] = date.split('-').map(Number);
      const rec = getDayRecord(state.selectedTeacher, y, m, d);
      const next = !rec[period];
      setDayRecordLocal(state.selectedTeacher, y, m, d, { [period]: next });
      renderMonth();
    }

    function csvEscape(value) {
      const text = String(value ?? '');
      return /[",\r\n]/.test(text) ? `"${text.replace(/"/g, '""')}"` : text;
    }

    function downloadTextFile(filename, content, mimeType) {
      const blob = new Blob([content], { type: mimeType });
      const url = URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = url;
      a.download = filename;
      document.body.appendChild(a);
      a.click();
      a.remove();
      URL.revokeObjectURL(url);
    }

    async function exportMonthSummary() {
      return exportCsvByTeachers(state.teachers, '导出所有教师');
    }

    async function exportByGrade() {
      const teachers = state.gradeTeacherGroups[state.selectedGrade] || [];
      if (!teachers.length) {
        alert('当前年级没有可导出的教师');
        return;
      }
      return exportCsvByTeachers(teachers, state.selectedGrade || '按年级导出');
    }

    async function exportCsvByTeachers(teachers, scopeLabel) {
      const password = prompt('请输入导出密码');
      if (password !== 'dpwgyxx66') {
        alert('密码错误，无法导出。');
        return;
      }

      const year = state.viewYear;
      const month = state.viewMonth;
      const monthKey = `${year}-${pad(month)}`;
      const days = monthDays(year, month);
      let allRecords = {};
      try {
        const res = await fetch(`${API_URL}?action=all_month_records&year=${year}&month=${month}`);
        const data = await res.json();
        if (data.success && data.records) allRecords = data.records;
      } catch (e) {}

      const rows = [];
      const chunkSize = 5;

      for (let startDay = 1; startDay <= days; startDay += chunkSize) {
        const endDay = Math.min(startDay + chunkSize - 1, days);
        const isLastGroup = endDay === days;
        const header = ['姓名', '班级'];
        for (let d = startDay; d <= endDay; d++) {
          const dt = new Date(year, month - 1, d);
          header.push(`${year}年${pad(month)}月${pad(d)}日\n第7节`, `${year}年${pad(month)}月${pad(d)}日\n第8节`);
        }
        if (isLastGroup) header.push('总计');
        rows.push(header);

        teachers.forEach(teacher => {
          const monthData = allRecords[teacher] || {};
          let c7 = 0, c8 = 0;
          const row = [teacher, ''];
          for (let d = startDay; d <= endDay; d++) {
            const key = dateKey(year, month, d);
            const rec = monthData[key] || { period7: false, period8: false };
            row.push(rec.period7 ? '1' : '-', rec.period8 ? '1' : '-');
          }
          for (let d = 1; d <= days; d++) {
            const key = dateKey(year, month, d);
            const rec = monthData[key] || { period7: false, period8: false };
            if (rec.period7) c7++;
            if (rec.period8) c8++;
          }
          if (isLastGroup) row.push(String(c7 + c8));
          rows.push(row);
        });

        if (endDay < days) rows.push([], [], []);
      }

      const csv = rows
        .map(r => r.length ? r.map(csvEscape).join(',') : '')
        .join('\r\n');
      downloadTextFile(`${scopeLabel}_${monthKey}.csv`, '\ufeff' + csv, 'text/csv;charset=utf-8');
    }

    gradeSelect.addEventListener('change', async (e) => {
      state.selectedGrade = e.target.value;
      state.selectedTeacher = '';
      renderGradeTeachers();
      if (state.selectedTeacher) {
        state.records[state.selectedTeacher] = state.records[state.selectedTeacher] || {};
        state.records[state.selectedTeacher][`${state.viewYear}-${pad(state.viewMonth)}`] = await fetchMonthRecords(state.selectedTeacher, state.viewYear, state.viewMonth);
      }
      renderMonth();
    });

    teacherSelect.addEventListener('change', async (e) => {
      state.selectedTeacher = e.target.value;
      state.records[state.selectedTeacher] = state.records[state.selectedTeacher] || {};
      state.records[state.selectedTeacher][`${state.viewYear}-${pad(state.viewMonth)}`] = await fetchMonthRecords(state.selectedTeacher, state.viewYear, state.viewMonth);
      renderMonth();
    });

    tbody.addEventListener('click', (e) => {
      const btn = e.target.closest('.period-btn');
      if (!btn) return;
      handleToggle(btn.dataset.date, btn.dataset.period);
    });

    yearSelect.addEventListener('change', async (e) => {
      state.viewYear = Number(e.target.value);
      state.records[state.selectedTeacher] = state.records[state.selectedTeacher] || {};
      state.records[state.selectedTeacher][`${state.viewYear}-${pad(state.viewMonth)}`] = await fetchMonthRecords(state.selectedTeacher, state.viewYear, state.viewMonth);
      renderMonth();
    });

    monthSelect.addEventListener('change', async (e) => {
      state.viewMonth = Number(e.target.value);
      state.records[state.selectedTeacher] = state.records[state.selectedTeacher] || {};
      state.records[state.selectedTeacher][`${state.viewYear}-${pad(state.viewMonth)}`] = await fetchMonthRecords(state.selectedTeacher, state.viewYear, state.viewMonth);
      renderMonth();
    });

    todayBtn.addEventListener('click', () => {
      const now = new Date();
      state.viewYear = now.getFullYear();
      state.viewMonth = now.getMonth() + 1;
      renderMonth();
    });

    exportBtn.addEventListener('click', exportMonthSummary);
    exportGradeBtn.addEventListener('click', exportByGrade);

    function bindSaveButtons() {
      const handler = () => saveMonthRecords();
      saveBtn.addEventListener('click', handler);
      saveBtn2.addEventListener('click', handler);
    }

    bindSaveButtons();

    weekendBtn.addEventListener('click', () => {
      state.hideWeekends = !state.hideWeekends;
      saveWeekendHideState();
      renderMonth();
    });

    async function init() {
      state.teachers = await fetchTeachers();
      state.gradeTeacherGroups = await fetchGradeTeacherGroups();
      const savedView = loadViewState();
      const now = new Date();
      state.viewYear = Number(savedView.viewYear) || now.getFullYear();
      state.viewMonth = Number(savedView.viewMonth) || now.getMonth() + 1;
      const sortedGrades = Object.keys(state.gradeTeacherGroups).sort((a, b) => gradeSortValue(a) - gradeSortValue(b) || a.localeCompare(b, 'zh-Hans-CN'));
      state.selectedGrade = savedView.selectedGrade || '未分年级';
      if (state.selectedGrade !== '所有年级' && state.selectedGrade !== '未分年级' && !sortedGrades.includes(state.selectedGrade)) {
        state.selectedGrade = '未分年级';
      }
      const initialTeachers = state.selectedGrade === '所有年级'
        ? state.teachers
        : (state.gradeTeacherGroups[state.selectedGrade] || []);
      state.selectedTeacher = savedView.selectedTeacher && initialTeachers.includes(savedView.selectedTeacher)
        ? savedView.selectedTeacher
        : (state.selectedGrade === '未分年级' ? '' : (initialTeachers[0] || ''));
      state.hideWeekends = loadWeekendHideState();
      renderGradeTeachers();
      if (state.selectedTeacher) {
        state.records[state.selectedTeacher] = state.records[state.selectedTeacher] || {};
        state.records[state.selectedTeacher][`${state.viewYear}-${pad(state.viewMonth)}`] = await fetchMonthRecords(state.selectedTeacher, state.viewYear, state.viewMonth);
      }
      renderMonth();
    }

    init();
  </script>
</body>
</html>