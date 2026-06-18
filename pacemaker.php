<?php
$host = 'localhost';
$user = 'root';
$pass = 'admin';
$dbname = 'demo_site';
$charset = 'utf8mb4';

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
    $errorMessage = $e->getMessage();
    if (str_contains($errorMessage, '2054') || str_contains($errorMessage, 'authentication method unknown to the client')) {
        $errorMessage .= '。这通常表示 MySQL 账号使用了 `caching_sha2_password` 等新认证方式，而当前 PHP/PDO 客户端不兼容。请将数据库用户改为 `mysql_native_password`，例如执行：ALTER USER \'root\'@\'localhost\' IDENTIFIED WITH mysql_native_password BY \'admin\'; FLUSH PRIVILEGES;';
    }
    exit('数据库连接失败：' . htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8'));
}

function cachePath(string $name): string {
    return sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pacemaker_' . $name . '.cache';
}

function readCache(string $name, int $ttl) {
    $path = cachePath($name);
    if (!is_file($path)) return null;
    $mtime = @filemtime($path);
    if ($mtime === false || (time() - $mtime) > $ttl) return null;
    $raw = @file_get_contents($path);
    if ($raw === false || $raw === '') return null;
    $data = @json_decode($raw, true);
    return is_array($data) ? $data : null;
}

function writeCache(string $name, array $data): void {
    @file_put_contents(cachePath($name), json_encode($data, JSON_UNESCAPED_UNICODE), LOCK_EX);
}

function fetchClasses(PDO $pdo): array {
    $cached = readCache('classes', 300);
    if (is_array($cached)) return $cached;
    $stmt = $pdo->query("SELECT class_name FROM all_stu2 WHERE class_name IS NOT NULL AND class_name <> '' GROUP BY class_name ORDER BY class_name");
    $classes = $stmt->fetchAll(PDO::FETCH_COLUMN);
    writeCache('classes', $classes);
    return $classes;
}

function fetchStudents(PDO $pdo, string $className): array {
    $cacheKey = 'students_' . md5($className);
    $cached = readCache($cacheKey, 300);
    if (is_array($cached)) return $cached;
    $stmt = $pdo->prepare("SELECT student_name FROM all_stu2 WHERE class_name = :class_name AND student_name IS NOT NULL AND student_name <> '' ORDER BY id ASC");
    $stmt->execute([':class_name' => $className]);
    $students = $stmt->fetchAll(PDO::FETCH_COLUMN);
    writeCache($cacheKey, $students);
    return $students;
}

try {
    $pdo->exec("ALTER TABLE all_stu2 ADD INDEX idx_class_name_id (class_name, id)");
} catch (Throwable $e) {
    // 索引已存在或无权限时忽略
}

$classes = fetchClasses($pdo);
$selectedClass = $_GET['class_name'] ?? ($classes[0] ?? '');
$students = $selectedClass !== '' ? fetchStudents($pdo, $selectedClass) : [];

if (isset($_GET['ajax'])) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'classes' => $classes,
        'students' => $students,
        'selectedClass' => $selectedClass,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$honors = [
    '纪律标兵',
    '文明礼貌标兵',
    '卫生标兵',
    '阅读标兵',
    '体育标兵',
    '守纪安全标兵',
    '助人爱心标兵',
    '自律标兵',
    '书写标兵',
    '进步标兵',
    '值日标兵',
    '节约标兵',
];
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>模范标兵</title>
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet" />
  <style>
    :root {
      --bg1: #eef4ff;
      --bg2: #f8fbff;
      --card: rgba(255,255,255,.84);
      --line: rgba(226,232,240,.95);
      --text: #0f172a;
      --muted: #64748b;
      --blue: #2563eb;
      --blue2: #4f46e5;
      --shadow: 0 18px 50px rgba(86, 118, 255, .10);
    }
    * { box-sizing: border-box; }
    html, body { min-height: 100%; }
    body {
      margin: 0;
      font-family: Inter, "PingFang SC", "Microsoft YaHei", sans-serif;
      color: var(--text);
      background:
        radial-gradient(circle at top left, rgba(59,130,246,.18), transparent 30%),
        radial-gradient(circle at 92% 8%, rgba(168,85,247,.16), transparent 24%),
        linear-gradient(180deg, var(--bg1) 0%, var(--bg2) 40%, #eef2ff 100%);
    }
    .page { max-width: 1320px; margin: 0 auto; padding: 18px; }
    .hero, .panel, .aside, .card {
      background: var(--card);
      border: 1px solid rgba(255,255,255,.72);
      border-radius: 26px;
      box-shadow: var(--shadow);
      backdrop-filter: blur(12px);
    }
    .hero { padding: 22px 24px; margin-bottom: 16px; }
    .hero-top { display:flex; justify-content:space-between; gap:16px; align-items:flex-start; flex-wrap:wrap; }
    .badge { display:inline-flex; align-items:center; gap:8px; padding:8px 12px; border-radius:999px; background: rgba(255,255,255,.8); color:#475569; font-size:12px; font-weight:700; }
    .title { margin: 12px 0 6px; font-size: clamp(30px, 4vw, 44px); font-weight: 900; letter-spacing: .12em; }
    .subtitle { margin: 0; color: var(--muted); font-size: 14px; line-height: 1.7; max-width: 760px; }
    .select-wrap { min-width: 240px; }
    .select-label, .mini-label { font-size: 12px; font-weight: 800; color: #64748b; margin-bottom: 8px; }
    .select, .date-pill, .text-pill {
      width: 100%;
      border: 1px solid var(--line);
      background: rgba(255,255,255,.88);
      border-radius: 14px;
      padding: 12px 14px;
      color: #334155;
      transition: .16s ease;
    }
    .select:focus, .date-pill:focus, .text-pill:focus { border-color: rgba(59,130,246,.55); box-shadow: 0 0 0 4px rgba(59,130,246,.12); background:#fff; }
    .layout { display:grid; grid-template-columns: minmax(0, 1fr) 360px; gap: 16px; align-items:start; }
    .panel { padding: 16px; }
    .panel-head { display:flex; justify-content:space-between; gap:12px; align-items:center; flex-wrap:wrap; margin-bottom: 14px; }
    .status { color: var(--muted); font-size: 13px; }
    .stats { display:flex; gap:10px; align-items:center; flex-wrap:wrap; }
    .pill { background: rgba(255,255,255,.8); border:1px solid rgba(255,255,255,.9); padding: 10px 12px; border-radius: 999px; color:#475569; font-size: 13px; font-weight:700; }
    .honor-grid { display:grid; grid-template-columns: repeat(3, minmax(0,1fr)); gap: 12px; }
    .card { padding: 14px; transition: transform .16s ease, box-shadow .16s ease, border-color .16s ease; }
    .card:hover { transform: translateY(-1px); }
    .card.dragover { border-color: rgba(59,130,246,.65); box-shadow: 0 0 0 4px rgba(59,130,246,.14), var(--shadow); }
    .card-top { display:flex; justify-content:space-between; gap:10px; align-items:center; margin-bottom:10px; }
    .honor-left { display:flex; align-items:center; gap:10px; min-width:0; }
    .index { width:30px; height:30px; border-radius:999px; display:grid; place-items:center; background: #dbeafe; color:#2563eb; font-size:12px; font-weight:900; flex:0 0 auto; }
    .honor-name { font-size: 15px; font-weight: 900; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
    .clear-btn { border:1px solid var(--line); background: rgba(255,255,255,.9); border-radius:999px; padding:7px 10px; font-size:12px; font-weight:700; color:#64748b; cursor:pointer; }
    .clear-btn:hover { background:#fff; }
    .drop-input { width:100%; border:1px solid rgba(148,163,184,.22); border-radius:14px; padding: 12px 14px; background: rgba(255,255,255,.86); color:#334155; font-weight:700; }
    .aside { padding: 16px; position: sticky; top: 14px; }
    .student-grid { display:grid; grid-template-columns: repeat(3, minmax(0,1fr)); gap: 10px; }
    .student { user-select:none; cursor:grab; padding: 12px 10px; text-align:center; border-radius: 16px; background: linear-gradient(180deg, rgba(255,255,255,.97), rgba(241,245,255,.99)); border:1px solid var(--line); box-shadow: 0 6px 14px rgba(134,151,205,.08); font-size: 14px; font-weight: 800; color:#334155; }
    .student.dragging { opacity:.45; cursor:grabbing; }
    .tip { margin-top: 12px; background: rgba(255,255,255,.72); border:1px solid rgba(255,255,255,.8); border-radius: 18px; padding: 12px; color: var(--muted); font-size: 13px; line-height: 1.6; }
    .icon-dot { width:16px; height:16px; border-radius:999px; background: linear-gradient(135deg, var(--blue), var(--blue2)); box-shadow: 0 8px 16px rgba(37,99,235,.28); }
    @media (max-width: 1100px) { .layout { grid-template-columns: 1fr; } .aside { position: static; } .honor-grid { grid-template-columns: repeat(2, minmax(0,1fr)); } }
    @media (max-width: 760px) { .page { padding: 12px; } .hero, .panel, .aside { border-radius: 22px; } .honor-grid, .student-grid { grid-template-columns: repeat(2, minmax(0,1fr)); } .select-wrap { width: 100%; } }
  </style>
</head>
<body>
  <main class="page">
    <section class="hero">
      <div class="hero-top">
        <div>
          <div class="badge"><span class="icon-dot"></span> 模范标兵小程序</div>
          <h1 class="title">模范标兵</h1>
          <p class="subtitle">将左侧学生姓名拖到右侧对应标兵文本框中，支持按班级切换与日期记录。页面已改成自带样式，不依赖外部 CSS，加载更稳更快。</p>
        </div>
        <div class="select-wrap">
          <div class="select-label">选择班级</div>
          <select id="classSelect" class="select">
            <?php foreach ($classes as $className): ?>
              <option value="<?= htmlspecialchars($className, ENT_QUOTES, 'UTF-8') ?>" <?= $className === $selectedClass ? 'selected' : '' ?>>
                <?= htmlspecialchars($className, ENT_QUOTES, 'UTF-8') ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
    </section>

    <section class="layout">
      <div class="panel">
        <div class="panel-head">
          <div class="status" id="statusText">从左侧拖拽学生姓名到右侧标兵文本框</div>
          <div class="stats">
            <div class="pill"><span class="mini-label" style="display:inline; margin:0 8px 0 0;">日期</span><input id="datePicker" type="date" class="date-pill" style="width:auto; padding:8px 10px; border:none; box-shadow:none; background:transparent;"></div>
            <div class="pill" id="countText">共 <?= count($students) ?> 人</div>
          </div>
        </div>
        <div id="honorBoard" class="honor-grid"></div>
      </div>

      <aside class="aside">
        <div class="panel-head" style="margin-bottom:12px;">
          <div>
            <div class="mini-label">学生姓名</div>
            <div style="font-size:18px; font-weight:900;">可拖动名单</div>
          </div>
          <div class="pill">拖到右侧文本框</div>
        </div>
        <div id="studentList" class="student-grid"></div>
        <div class="tip">提示：姓名卡片可重复拖动；点击“清空”即可重选。若名单较多，右侧会自动滚动适配。</div>
      </aside>
    </section>
  </main>

  <script>
    const initialStudents = <?= json_encode($students, JSON_UNESCAPED_UNICODE) ?>;
    const honors = <?= json_encode($honors, JSON_UNESCAPED_UNICODE) ?>;
    const classSelect = document.getElementById('classSelect');
    const datePicker = document.getElementById('datePicker');
    const honorBoard = document.getElementById('honorBoard');
    const studentList = document.getElementById('studentList');
    const statusText = document.getElementById('statusText');
    const countText = document.getElementById('countText');
    let students = initialStudents.slice();
    let assignments = new Map();
    let draggedName = '';

    const today = new Date();
    const pad = n => String(n).padStart(2, '0');
    datePicker.value = `${today.getFullYear()}-${pad(today.getMonth() + 1)}-${pad(today.getDate())}`;

    function escapeHtml(str) {
      return String(str)
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#39;');
    }

    function renderStudents() {
      studentList.innerHTML = students.map(name => `
        <div class="student" draggable="true" data-name="${escapeHtml(name)}">${escapeHtml(name)}</div>
      `).join('');
      studentList.querySelectorAll('.student').forEach(chip => {
        chip.addEventListener('dragstart', () => { draggedName = chip.dataset.name || ''; chip.classList.add('dragging'); });
        chip.addEventListener('dragend', () => chip.classList.remove('dragging'));
      });
      countText.textContent = `共 ${students.length} 人`;
    }

    function renderHonors() {
      honorBoard.innerHTML = honors.map((honor, index) => {
        const value = assignments.get(honor) || '';
        return `
          <div class="card" data-honor="${escapeHtml(honor)}">
            <div class="card-top">
              <div class="honor-left">
                <div class="index">${index + 1}</div>
                <div class="honor-name">${escapeHtml(honor)}</div>
              </div>
              <button class="clear-btn" type="button" data-clear="${escapeHtml(honor)}">清空</button>
            </div>
            <input class="drop-input" type="text" placeholder="把姓名拖到这里" value="${escapeHtml(value)}" readonly />
          </div>
        `;
      }).join('');

      honorBoard.querySelectorAll('.card').forEach(card => {
        card.addEventListener('dragover', e => { e.preventDefault(); card.classList.add('dragover'); });
        card.addEventListener('dragleave', () => card.classList.remove('dragover'));
        card.addEventListener('drop', e => {
          e.preventDefault();
          card.classList.remove('dragover');
          const honor = card.dataset.honor;
          if (!honor || !draggedName) return;
          assignments.set(honor, draggedName);
          renderHonors();
          renderStudents();
          statusText.textContent = `已分配：${honor} → ${draggedName}`;
        });
      });

      honorBoard.querySelectorAll('[data-clear]').forEach(btn => {
        btn.addEventListener('click', () => {
          assignments.delete(btn.dataset.clear);
          renderHonors();
          renderStudents();
          statusText.textContent = `已清空：${btn.dataset.clear}`;
        });
      });
    }

    classSelect.addEventListener('change', async () => {
      assignments = new Map();
      statusText.textContent = '正在加载班级名单...';
      const res = await fetch(`pacemaker.php?ajax=1&class_name=${encodeURIComponent(classSelect.value)}`, { cache: 'no-store' });
      const data = await res.json();
      students = data.students || [];
      renderStudents();
      renderHonors();
      statusText.textContent = '从左侧拖拽学生姓名到右侧标兵文本框';
    });

    renderStudents();
    renderHonors();
  </script>
</body>
</html>