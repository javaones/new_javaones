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
    return sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'roll_call_' . $name . '.cache';
}

function readCache(string $name, int $ttl) {
    $path = cachePath($name);
    if (!is_file($path)) return null;
    $mtime = @filemtime($path);
    if ($mtime === false || (time() - $mtime) > $ttl) return null;
    $raw = @file_get_contents($path);
    if ($raw === false || $raw === '') return null;
    $data = @unserialize($raw);
    return is_array($data) ? $data : null;
}

function writeCache(string $name, array $data): void {
    @file_put_contents(cachePath($name), serialize($data), LOCK_EX);
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

$classesData = $classes;
$studentsData = $students;
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  
  <style>
    :root { color-scheme: light; }
    html, body { height: 100%; }
    body {
      font-family: Inter, "PingFang SC", "Microsoft YaHei", sans-serif;
      background:
        radial-gradient(circle at top left, rgba(59, 130, 246, 0.18), transparent 28%),
        radial-gradient(circle at 90% 10%, rgba(168, 85, 247, 0.16), transparent 24%),
        linear-gradient(180deg, #eff6ff 0%, #f8fbff 36%, #eef2ff 100%);
      overflow: hidden;
      color: #0f172a;
    }
    .glass { background: rgba(255, 255, 255, 0.72); border: 1px solid rgba(255, 255, 255, 0.72); box-shadow: 0 20px 60px rgba(91, 113, 173, 0.12); backdrop-filter: blur(14px); }
    .student { transition: all 180ms ease; background: linear-gradient(180deg, rgba(255,255,255,0.96), rgba(241,244,255,0.98)); border: 1px solid rgba(226,232,240,0.95); box-shadow: inset 0 1px 0 rgba(255,255,255,0.9), 0 6px 16px rgba(134, 151, 205, 0.08); }
    .student:hover { transform: translateY(-1px); box-shadow: 0 8px 18px rgba(108, 127, 189, 0.14); }
    .student.active { color: #173b7a; background: linear-gradient(180deg, rgba(219,234,254,0.98), rgba(233,213,255,0.95)); border-color: rgba(96, 165, 250, 0.7); box-shadow: 0 0 0 3px rgba(95, 178, 255, 0.16), 0 0 22px rgba(69, 164, 255, 0.32), inset 0 1px 0 rgba(255,255,255,1); transform: scale(1.02); }
    .floating { animation: float 7s ease-in-out infinite; }
    @keyframes float { 0%, 100% { transform: translateY(0px); } 50% { transform: translateY(-10px); } }
    .btn-primary { background: linear-gradient(135deg, #2563eb 0%, #4f46e5 52%, #7c3aed 100%); box-shadow: 0 18px 36px rgba(86, 118, 255, 0.35), inset 0 1px 0 rgba(255,255,255,0.35); }
    .content-wrap { content-visibility: auto; contain-intrinsic-size: 900px; }

    .relative { position: relative; }
    .absolute { position: absolute; }
    .left-1\/2 { left: 50%; }
    .left-10 { left: 2.5rem; }
    .right-12 { right: 3rem; }
    .top-8 { top: 2rem; }
    .top-10 { top: 2.5rem; }
    .top-14 { top: 3.5rem; }
    .h-28 { height: 7rem; }
    .h-40 { height: 10rem; }
    .h-56 { height: 14rem; }
    .w-28 { width: 7rem; }
    .w-40 { width: 10rem; }
    .w-56 { width: 14rem; }
    .-translate-x-1\/2 { transform: translateX(-50%); }
    .rounded-full { border-radius: 9999px; }
    .bg-white\/45 { background-color: rgba(255,255,255,.45); }
    .bg-white\/30 { background-color: rgba(255,255,255,.30); }
    .bg-white\/70 { background-color: rgba(255,255,255,.70); }
    .bg-white\/80 { background-color: rgba(255,255,255,.80); }
    .bg-white\/90 { background-color: rgba(255,255,255,.90); }
    .bg-sky-100\/80 { background-color: rgba(224,242,254,.80); }
    .blur-3xl { filter: blur(64px); }
    .px-3 { padding-left: .75rem; padding-right: .75rem; }
    .px-4 { padding-left: 1rem; padding-right: 1rem; }
    .px-5 { padding-left: 1.25rem; padding-right: 1.25rem; }
    .px-6 { padding-left: 1.5rem; padding-right: 1.5rem; }
    .py-1\.5 { padding-top: .375rem; padding-bottom: .375rem; }
    .py-2\.5 { padding-top: .625rem; padding-bottom: .625rem; }
    .py-3 { padding-top: .75rem; padding-bottom: .75rem; }
    .py-4 { padding-top: 1rem; padding-bottom: 1rem; }
    .py-5 { padding-top: 1.25rem; padding-bottom: 1.25rem; }
    .pt-2 { padding-top: .5rem; }
    .pt-3 { padding-top: .75rem; }
    .pb-20 { padding-bottom: 5rem; }
    .gap-2 { gap: .5rem; }
    .gap-3 { gap: .75rem; }
    .gap-4 { gap: 1rem; }
    .gap-5 { gap: 1.25rem; }
    .gap-6 { gap: 1.5rem; }
    .grid { display: grid; }
    .flex { display: flex; }
    .hidden { display: none; }
    .block { display: block; }
    .h-full { height: 100%; }
    .w-full { width: 100%; }
    .max-w-\[1200px\] { max-width: 1200px; }
    .mx-auto { margin-left: auto; margin-right: auto; }
    .mt-1\.5 { margin-top: .375rem; }
    .mt-2 { margin-top: .5rem; }
    .mt-3 { margin-top: .75rem; }
    .mb-3 { margin-bottom: .75rem; }
    .pb-1 { padding-bottom: .25rem; }
    .text-center { text-align: center; }
    .text-left { text-align: left; }
    .items-center { align-items: center; }
    .justify-between { justify-content: space-between; }
    .justify-center { justify-content: center; }
    .justify-end { justify-content: flex-end; }
    .flex-col { flex-direction: column; }
    .flex-wrap { flex-wrap: wrap; }
    .inline-flex { display: inline-flex; }
    .rounded-2xl { border-radius: 1rem; }
    .rounded-[22px] { border-radius: 22px; }
    .rounded-[28px] { border-radius: 28px; }
    .border { border-width: 1px; border-style: solid; }
    .border-slate-200 { border-color: #e2e8f0; }
    .border-sky-100 { border-color: #e0f2fe; }
    .border-white\/72 { border-color: rgba(255,255,255,.72); }
    .bg-white { background-color: #fff; }
    .bg-sky-50 { background-color: #f0f9ff; }
    .from-white { --tw-gradient-from: #fff; }
    .to-slate-50 { --tw-gradient-to: #f8fafc; }
    .bg-gradient-to-b { background-image: linear-gradient(to bottom, var(--tw-gradient-from), var(--tw-gradient-to)); }
    .shadow-sm { box-shadow: 0 1px 2px rgba(0,0,0,.06); }
    .shadow-[inset_0_0_0_3px_rgba(82,163,255,0.12)] { box-shadow: inset 0 0 0 3px rgba(82,163,255,0.12); }
    .shadow-[0_16px_36px_rgba(98,119,181,0.14)] { box-shadow: 0 16px 36px rgba(98,119,181,0.14); }
    .tracking-[0.08em] { letter-spacing: .08em; }
    .tracking-[0.12em] { letter-spacing: .12em; }
    .tracking-[0.14em] { letter-spacing: .14em; }
    .tracking-[0.26em] { letter-spacing: .26em; }
    .font-black { font-weight: 900; }
    .font-bold { font-weight: 700; }
    .font-medium { font-weight: 500; }
    .font-semibold { font-weight: 600; }
    .text-xs { font-size: .75rem; }
    .text-sm { font-size: .875rem; }
    .text-[10px] { font-size: 10px; }
    .text-[11px] { font-size: 11px; }
    .text-[15px] { font-size: 15px; }
    .text-2xl { font-size: 1.5rem; }
    .text-3xl { font-size: 1.875rem; }
    .text-[24px] { font-size: 24px; }
    .sm\:flex-row, .sm\:grid-cols-4, .sm\:px-4, .sm\:py-3, .sm\:py-5, .sm\:text-sm, .sm\:text-left, .sm\:justify-end, .sm\:p-5, .sm\:min-w-\[220px\], .sm\:py-5, .sm\:px-6, .sm\:text-3xl { }
    @media (min-width: 640px) {
      .sm\:flex-row { flex-direction: row; }
      .sm\:grid-cols-4 { grid-template-columns: repeat(4, minmax(0, 1fr)); }
      .sm\:px-4 { padding-left: 1rem; padding-right: 1rem; }
      .sm\:py-3 { padding-top: .75rem; padding-bottom: .75rem; }
      .sm\:py-5 { padding-top: 1.25rem; padding-bottom: 1.25rem; }
      .sm\:text-sm { font-size: .875rem; }
      .sm\:text-left { text-align: left; }
      .sm\:justify-end { justify-content: flex-end; }
      .sm\:p-5 { padding: 1.25rem; }
      .sm\:min-w-\[220px\] { min-width: 220px; }
      .sm\:px-6 { padding-left: 1.5rem; padding-right: 1.5rem; }
      .sm\:text-3xl { font-size: 1.875rem; }
    }
    @media (min-width: 768px) {
      .md\:grid-cols-3 { grid-template-columns: repeat(3, minmax(0, 1fr)); }
    }
    @media (min-width: 1024px) {
      .lg\:px-6 { padding-left: 1.5rem; padding-right: 1.5rem; }
      .lg\:p-6 { padding: 1.5rem; }
      .lg\:grid-cols-6 { grid-template-columns: repeat(6, minmax(0, 1fr)); }
    }
    @media (min-width: 1280px) {
      .xl\:grid-cols-8 { grid-template-columns: repeat(8, minmax(0, 1fr)); }
    }
  </style>
</head>
<body>
  <main class="content-wrap relative h-full w-full px-3 py-3 sm:px-4 lg:px-6 pb-20">
    <div class="absolute -left-10 top-14 h-40 w-40 rounded-full bg-white/45 blur-3xl"></div>
    <div class="absolute right-12 top-10 h-28 w-28 rounded-full bg-sky-100/80 blur-3xl"></div>
    <div class="absolute left-1/2 top-8 h-56 w-56 -translate-x-1/2 rounded-full bg-white/30 blur-3xl"></div>

    <section class="mx-auto flex h-full max-w-[1200px] flex-col gap-5">
      <header class="relative w-full pt-2 sm:pt-3">
        <div class="flex flex-col items-center justify-between gap-4 rounded-[28px] p-4 sm:flex-row sm:gap-6 sm:p-5 glass">
          <div class="text-center sm:text-left">
            <div class="inline-flex items-center gap-2 rounded-full bg-sky-50 px-3 py-1.5 text-[11px] font-semibold text-sky-700 shadow-sm">
              随机点名 · 课堂互动
            </div>
            <h1 class="mt-3 text-[24px] font-black tracking-[0.12em] text-slate-900 sm:text-[34px]">课堂随机点名</h1>
            <p class="mt-1.5 text-xs text-slate-500 sm:text-sm">开始后自动滚动，再点一次停止并锁定结果</p>
          </div>
          <div class="flex flex-wrap items-center justify-center gap-2 sm:justify-end">
            <button id="drawBtn" class="btn-primary rounded-full px-6 py-2.5 text-xs font-bold tracking-[0.14em] text-white transition hover:brightness-105 sm:px-8 sm:py-3 sm:text-sm">开始抽取</button>
            <button id="resetBtn" class="rounded-full border border-slate-200 bg-white/90 px-5 py-2.5 text-xs font-bold tracking-[0.12em] text-slate-700 shadow-sm transition hover:bg-white sm:px-6 sm:py-3 sm:text-sm">重新开始</button>
          </div>
        </div>
        <div class="mt-3 flex flex-wrap items-center justify-between gap-3 rounded-2xl p-3 glass">
          <div class="flex items-center gap-2 text-sm text-slate-600">
            <span class="font-semibold text-slate-500">选择班级</span>
            <select id="classSelect" class="rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-medium text-slate-700 outline-none transition focus:border-sky-400 focus:ring-4 focus:ring-sky-100">
              <?php foreach ($classes as $className): ?>
                <option value="<?= htmlspecialchars($className, ENT_QUOTES, 'UTF-8') ?>" <?= $className === $selectedClass ? 'selected' : '' ?>>
                  <?= htmlspecialchars($className, ENT_QUOTES, 'UTF-8') ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="rounded-full bg-white/80 px-3 py-1.5 text-sm font-medium text-slate-600 shadow-sm">
            <span id="countText">共 <?= count($students) ?> 人</span>
          </div>
        </div>
      </header>

      <section class="glass w-full rounded-[28px] p-4 sm:p-5 lg:p-6">
        <div class="mb-3 flex items-center justify-between gap-2 text-xs text-slate-500 sm:text-sm">
          <span id="statusText">点击“开始抽取”进入滚动模式</span>
        </div>
        <div class="grid grid-cols-2 gap-2 sm:grid-cols-4 lg:grid-cols-6 xl:grid-cols-8" id="studentGrid"></div>
      </section>

      <section class="flex flex-col items-center gap-3 pb-1">
        <div class="floating rounded-[28px] bg-white/70 px-5 py-4 shadow-[0_16px_36px_rgba(98,119,181,0.14)] backdrop-blur-md sm:px-6 sm:py-5">
          <div class="text-center text-[10px] font-semibold tracking-[0.26em] text-slate-500 sm:text-xs">当前点名结果</div>
          <div id="currentName" class="mt-2 min-w-[180px] rounded-[22px] border border-sky-100 bg-gradient-to-b from-white to-slate-50 px-5 py-4 text-center text-2xl font-black tracking-[0.08em] text-slate-800 shadow-[inset_0_0_0_3px_rgba(82,163,255,0.12)] sm:min-w-[220px] sm:text-3xl">等待抽取</div>
        </div>
      </section>
    </section>
  </main>



  <script>
    const initialStudents = <?= json_encode($studentsData, JSON_UNESCAPED_UNICODE) ?>;
    const initialClasses = <?= json_encode($classesData, JSON_UNESCAPED_UNICODE) ?>;
    const classSelect = document.getElementById('classSelect');
    const grid = document.getElementById('studentGrid');
    const currentName = document.getElementById('currentName');
    const drawBtn = document.getElementById('drawBtn');
    const resetBtn = document.getElementById('resetBtn');
    const statusText = document.getElementById('statusText');
    const countText = document.getElementById('countText');
    let students = initialStudents.slice();
    let timer = null, activeIndex = -1, drawing = false, selectedName = '', audioCtx = null;

    function renderGrid() {
      grid.innerHTML = students.map((name, index) => `<div class="student rounded-2xl px-3 py-4 text-center text-[15px] font-medium text-slate-700 sm:py-5 ${index === activeIndex ? 'active' : ''}">${name}</div>`).join('');
      countText.textContent = `共 ${students.length} 人`;
      classSelect.title = `当前班级共 ${students.length} 人`;
    }
    function pickRandom() { if (!students.length) return; activeIndex = Math.floor(Math.random() * students.length); selectedName = students[activeIndex]; currentName.textContent = selectedName; renderGrid(); }
    function ensureAudio() { if (!audioCtx) audioCtx = new (window.AudioContext || window.webkitAudioContext)(); if (audioCtx.state === 'suspended') audioCtx.resume(); }
    function playSuccessSound() { if (!students.length) return; ensureAudio(); const ctx = audioCtx; const now = ctx.currentTime; [523.25, 659.25, 783.99].forEach((freq, index) => { const osc = ctx.createOscillator(); const gain = ctx.createGain(); osc.type = 'sine'; osc.frequency.value = freq; gain.gain.setValueAtTime(0.0001, now + index * 0.08); gain.gain.exponentialRampToValueAtTime(0.18, now + index * 0.08 + 0.02); gain.gain.exponentialRampToValueAtTime(0.0001, now + index * 0.08 + 0.22); osc.connect(gain); gain.connect(ctx.destination); osc.start(now + index * 0.08); osc.stop(now + index * 0.08 + 0.25); }); }
    function stopDraw() { if (timer) clearInterval(timer); timer = null; drawing = false; drawBtn.textContent = '开始抽取'; statusText.textContent = selectedName ? `本次结果已确定：${selectedName}` : '点击“开始抽取”进入滚动模式'; playSuccessSound(); }
    function startDraw() { if (!students.length) return; if (drawing) { stopDraw(); return; } ensureAudio(); drawing = true; drawBtn.textContent = '停止抽取'; statusText.textContent = '滚动中... 再点一次即可停止并确定结果'; timer = setInterval(pickRandom, 70); }
    function resetRollCall() { if (timer) clearInterval(timer); timer = null; drawing = false; activeIndex = -1; selectedName = ''; currentName.textContent = '等待抽取'; drawBtn.textContent = '开始抽取'; statusText.textContent = '点击“开始抽取”进入滚动模式'; renderGrid(); }

    classSelect.addEventListener('change', async () => {
      const className = classSelect.value;
      resetRollCall();
      const res = await fetch(`roll_call.php?ajax=1&class_name=${encodeURIComponent(className)}`, { cache: 'no-store' });
      const data = await res.json();
      students = data.students || [];
      renderGrid();
    });

    drawBtn.addEventListener('click', startDraw);
    resetBtn.addEventListener('click', resetRollCall);

    classSelect.addEventListener('focus', async () => {
      if (classSelect.dataset.loaded === '1') return;
      classSelect.dataset.loaded = '1';
      if (Array.isArray(initialClasses) && initialClasses.length > 0) {
        classSelect.title = `已加载 ${initialClasses.length} 个班级`;
      }
    });

    renderGrid();
  </script>
</body>
</html>
