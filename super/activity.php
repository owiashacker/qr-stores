<?php
require_once __DIR__ . '/../includes/functions.php';
requireAdminLogin();
$pageTitle = 'سجل الأحداث';

// ====================================================
// POST actions: delete, bulk_delete, purge (older than N days), purge_all
// ====================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrfCheck()) {
    $action = $_POST['action'] ?? '';

    if ($action === 'delete') {
        $id = clean_int($_POST['id'] ?? 0, 1);
        $stmt = $pdo->prepare('DELETE FROM activity_logs WHERE id = ?');
        $stmt->execute([$id]);
        flash('success', $stmt->rowCount() ? 'تم حذف السجل' : 'السجل غير موجود');
        redirect(BASE_URL . '/super/activity.php');
    }

    if ($action === 'bulk_delete') {
        $ids = array_filter(array_map('intval', $_POST['ids'] ?? []));
        if ($ids) {
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $pdo->prepare("DELETE FROM activity_logs WHERE id IN ($ph)");
            $stmt->execute($ids);
            flash('success', 'تم حذف ' . $stmt->rowCount() . ' سجل');
        } else {
            flash('error', 'لم تختر أي سجل');
        }
        redirect(BASE_URL . '/super/activity.php');
    }

    // Multi-mode purge: 'older' | 'errors_only' | 'all'
    if ($action === 'purge') {
        $mode = $_POST['mode'] ?? '';
        $sql = null;

        if ($mode === 'older') {
            $days = clean_int($_POST['days'] ?? 30, 1, 365);
            $sql = "DELETE FROM activity_logs WHERE created_at < (NOW() - INTERVAL $days DAY)";
        } elseif ($mode === 'errors_only') {
            // Keep only successful requests; delete error-status entries
            $sql = "DELETE FROM activity_logs WHERE http_status >= 400";
        } elseif ($mode === 'all') {
            if (($_POST['confirm'] ?? '') !== 'DELETE_ALL') {
                flash('error', 'للحذف الكامل اكتب DELETE_ALL في خانة التأكيد');
                redirect(BASE_URL . '/super/activity.php');
            }
            $sql = "DELETE FROM activity_logs";
        }

        if ($sql) {
            $affected = $pdo->exec($sql);
            flash('success', "تم حذف $affected سجل");
        } else {
            flash('error', 'وضع حذف غير صالح');
        }
        redirect(BASE_URL . '/super/activity.php');
    }
}

// ====================================================
// DETAIL VIEW (?view=ID)
// ====================================================
$viewId = clean_int($_GET['view'] ?? 0, 1);
$viewing = null;
if ($viewId > 0) {
    $stmt = $pdo->prepare('SELECT * FROM activity_logs WHERE id = ? LIMIT 1');
    $stmt->execute([$viewId]);
    $viewing = $stmt->fetch();
}

// ====================================================
// LIST VIEW — filters
// ====================================================
$filterUserType = clean_string($_GET['user_type'] ?? '', 20);
$filterStatus = clean_string($_GET['status'] ?? '', 10);    // success / redirect / client_error / server_error
$filterEvent = clean_string($_GET['event'] ?? '', 50);
$filterMethod = clean_string($_GET['method'] ?? '', 10);
$filterIp = clean_string($_GET['ip'] ?? '', 45);
$filterSearch = clean_string($_GET['q'] ?? '', 200);
$filterDate = clean_string($_GET['date'] ?? '', 10);        // YYYY-MM-DD
$limit = clean_int($_GET['limit'] ?? 100, 10, 500);

$where = [];
$params = [];
if (in_array($filterUserType, ['admin','store','visitor'], true)) {
    $where[] = 'user_type = ?'; $params[] = $filterUserType;
}
if ($filterStatus !== '') {
    if ($filterStatus === 'success')       { $where[] = 'http_status BETWEEN 200 AND 299'; }
    elseif ($filterStatus === 'redirect')  { $where[] = 'http_status BETWEEN 300 AND 399'; }
    elseif ($filterStatus === 'client')    { $where[] = 'http_status BETWEEN 400 AND 499'; }
    elseif ($filterStatus === 'server')    { $where[] = 'http_status >= 500'; }
}
if ($filterEvent !== '')  { $where[] = 'event_type = ?'; $params[] = $filterEvent; }
if ($filterMethod !== '') { $where[] = 'method = ?';     $params[] = $filterMethod; }
if ($filterIp !== '')     { $where[] = 'ip = ?';         $params[] = $filterIp; }
if ($filterSearch !== '') {
    $where[] = '(url LIKE ? OR user_agent LIKE ? OR referrer LIKE ?)';
    $like = '%' . $filterSearch . '%';
    $params = array_merge($params, [$like, $like, $like]);
}
if ($filterDate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterDate)) {
    $where[] = 'DATE(created_at) = ?';
    $params[] = $filterDate;
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$sql = "SELECT * FROM activity_logs $whereSql ORDER BY created_at DESC LIMIT " . $limit;
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

// Stats
$stats = [
    'total'       => (int) $pdo->query("SELECT COUNT(*) FROM activity_logs")->fetchColumn(),
    'today'       => (int) $pdo->query("SELECT COUNT(*) FROM activity_logs WHERE DATE(created_at) = CURDATE()")->fetchColumn(),
    'errors_24h'  => (int) $pdo->query("SELECT COUNT(*) FROM activity_logs WHERE http_status >= 400 AND created_at > (NOW() - INTERVAL 24 HOUR)")->fetchColumn(),
    'visitors_24h'=> (int) $pdo->query("SELECT COUNT(DISTINCT ip) FROM activity_logs WHERE created_at > (NOW() - INTERVAL 24 HOUR)")->fetchColumn(),
    'uploads_24h' => (int) $pdo->query("SELECT COUNT(*) FROM activity_logs WHERE event_type = 'upload' AND created_at > (NOW() - INTERVAL 24 HOUR)")->fetchColumn(),
];

// Distinct event types for filter dropdown
$eventTypes = $pdo->query("SELECT event_type, COUNT(*) AS cnt FROM activity_logs GROUP BY event_type ORDER BY cnt DESC LIMIT 30")->fetchAll();

// Top error paths last 24h — useful for super admin
$topErrors = $pdo->query("SELECT url, http_status, COUNT(*) AS cnt FROM activity_logs WHERE http_status >= 400 AND created_at > (NOW() - INTERVAL 24 HOUR) GROUP BY url, http_status ORDER BY cnt DESC LIMIT 8")->fetchAll();

require __DIR__ . '/../includes/header_super.php';

// ====================================================
// Rendering helpers
// ====================================================
function statusBadgeHttp($code) {
    $code = (int) $code;
    if ($code >= 200 && $code < 300) $cls = 'bg-emerald-500/20 text-emerald-300 border-emerald-500/30';
    elseif ($code >= 300 && $code < 400) $cls = 'bg-blue-500/20 text-blue-300 border-blue-500/30';
    elseif ($code >= 400 && $code < 500) $cls = 'bg-amber-500/20 text-amber-300 border-amber-500/30';
    elseif ($code >= 500) $cls = 'bg-red-500/20 text-red-300 border-red-500/30';
    else $cls = 'bg-gray-500/20 text-gray-300';
    return '<span class="px-2 py-0.5 rounded-lg text-xs font-bold border ' . $cls . '">' . $code . '</span>';
}

function userBadgeActivity($type, $id) {
    if (!$type || $type === 'visitor') {
        return '<span class="text-xs text-gray-500">زائر</span>';
    }
    $cls = $type === 'admin'
        ? 'bg-purple-500/20 text-purple-300'
        : 'bg-teal-500/20 text-teal-300';
    $label = $type === 'admin' ? 'مدير' : 'متجر';
    return '<span class="px-2 py-0.5 rounded-lg text-xs font-bold ' . $cls . '">' . $label . ($id ? ' #' . (int) $id : '') . '</span>';
}

function eventLabel($type) {
    $map = [
        'page_view'       => 'مشاهدة صفحة',
        'menu_view'       => 'مشاهدة متجر',
        'admin_view'      => 'لوحة متجر',
        'super_view'      => 'لوحة سوبر',
        'form_submit'     => 'إرسال نموذج',
        'upload'          => 'رفع ملف',
        'login_page'      => 'صفحة دخول',
        'login_attempt'   => 'محاولة دخول',
        'login_success'   => 'دخول ناجح',
        'login_failed'    => 'دخول فاشل',
        'logout'          => 'خروج',
        'register_page'   => 'صفحة تسجيل',
        'register_attempt'=> 'محاولة تسجيل',
        'not_found'       => 'غير موجود',
        'forbidden'       => 'ممنوع',
        'server_error'    => 'خطأ خادم',
        'auth_required'   => 'يتطلب دخول',
        'redirect'        => 'تحويل',
    ];
    return $map[$type] ?? $type;
}

function timeAgoAct($datetime) {
    if (!$datetime) return '—';
    $diff = time() - strtotime($datetime);
    if ($diff < 60) return 'الآن';
    if ($diff < 3600) return floor($diff/60) . ' د';
    if ($diff < 86400) return floor($diff/3600) . ' س';
    if ($diff < 2592000) return floor($diff/86400) . ' يوم';
    return date('m/d/y', strtotime($datetime));
}
?>

<?php if ($viewing): ?>
    <!-- =================== DETAIL VIEW =================== -->
    <div class="mb-6">
        <a href="<?= BASE_URL ?>/super/activity.php" class="inline-flex items-center gap-2 text-sm text-gray-400 hover:text-white">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
            العودة لسجل الأحداث
        </a>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Left: main details -->
        <div class="lg:col-span-2 space-y-6">
            <!-- Header card -->
            <div class="card rounded-2xl p-6">
                <div class="flex items-start justify-between gap-4 flex-wrap mb-4">
                    <div>
                        <div class="flex items-center gap-3 mb-2 flex-wrap">
                            <span class="px-3 py-1 rounded-lg bg-white/10 font-mono text-sm font-bold text-white"><?= e($viewing['method']) ?></span>
                            <?= statusBadgeHttp($viewing['http_status']) ?>
                            <span class="px-2 py-0.5 rounded-lg bg-indigo-500/20 text-indigo-300 text-xs font-bold"><?= e(eventLabel($viewing['event_type'])) ?></span>
                            <?= userBadgeActivity($viewing['user_type'], $viewing['user_id']) ?>
                        </div>
                        <p class="font-mono text-xs text-gray-400 break-all" dir="ltr"><?= e($viewing['url']) ?></p>
                    </div>
                    <div class="text-right">
                        <p class="text-3xl font-black text-white"><?= number_format($viewing['duration_ms']) ?> <span class="text-sm text-gray-400">ms</span></p>
                        <p class="text-xs text-gray-500">زمن التنفيذ</p>
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-3 text-sm">
                    <div class="bg-white/5 rounded-xl p-3">
                        <p class="text-xs text-gray-500 mb-1">الوقت</p>
                        <p class="text-gray-200"><?= e(date('Y-m-d H:i:s', strtotime($viewing['created_at']))) ?></p>
                        <p class="text-xs text-gray-500 mt-1"><?= timeAgoAct($viewing['created_at']) ?></p>
                    </div>
                    <div class="bg-white/5 rounded-xl p-3">
                        <p class="text-xs text-gray-500 mb-1">عنوان IP</p>
                        <p class="font-mono text-gray-200" dir="ltr"><?= e($viewing['ip'] ?: '—') ?></p>
                    </div>
                    <div class="bg-white/5 rounded-xl p-3 md:col-span-2">
                        <p class="text-xs text-gray-500 mb-1">الإحالة (Referrer)</p>
                        <p class="text-gray-200 text-xs break-all" dir="ltr"><?= e($viewing['referrer'] ?: '—') ?></p>
                    </div>
                    <div class="bg-white/5 rounded-xl p-3 md:col-span-2">
                        <p class="text-xs text-gray-500 mb-1">User Agent</p>
                        <p class="text-gray-300 text-xs break-all" dir="ltr"><?= e($viewing['user_agent'] ?: '—') ?></p>
                    </div>
                </div>
            </div>

            <!-- Details (JSON) -->
            <?php $det = json_decode($viewing['details'] ?? '', true); ?>
            <?php if ($det): ?>
            <div class="card rounded-2xl overflow-hidden">
                <div class="p-4 border-b border-white/5 flex items-center justify-between">
                    <h3 class="font-bold text-white">التفاصيل</h3>
                    <button onclick="copyDetails()" class="px-3 py-1.5 rounded-lg bg-white/5 hover:bg-white/10 text-xs text-gray-300">نسخ</button>
                </div>
                <pre id="activityDetails" class="p-5 text-xs text-gray-300 font-mono overflow-x-auto" dir="ltr" style="white-space:pre-wrap;word-break:break-word"><?= e(json_encode($det, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre>
            </div>
            <?php endif; ?>
        </div>

        <!-- Right: metadata + actions -->
        <div class="space-y-6">
            <div class="card rounded-2xl p-6">
                <h3 class="font-bold text-white mb-3">ملخص الطلب</h3>
                <dl class="text-xs space-y-3">
                    <div>
                        <dt class="text-gray-500">نوع الحدث</dt>
                        <dd class="text-gray-200 font-mono"><?= e($viewing['event_type']) ?></dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">المستخدم</dt>
                        <dd class="text-gray-200"><?= userBadgeActivity($viewing['user_type'], $viewing['user_id']) ?></dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">حجم الطلب (POST)</dt>
                        <dd class="text-gray-200 font-mono"><?= number_format((int) $viewing['post_size']) ?> بايت</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">HTTP</dt>
                        <dd><?= statusBadgeHttp($viewing['http_status']) ?></dd>
                    </div>
                </dl>
            </div>

            <!-- Same IP history -->
            <?php if ($viewing['ip']):
                $stmtIp = $pdo->prepare('SELECT COUNT(*) FROM activity_logs WHERE ip = ?');
                $stmtIp->execute([$viewing['ip']]);
                $ipCount = (int) $stmtIp->fetchColumn();
            ?>
            <div class="card rounded-2xl p-6">
                <h3 class="font-bold text-white mb-2">نشاط نفس الـ IP</h3>
                <p class="text-sm text-gray-400 mb-3"><?= number_format($ipCount) ?> سجل من <code class="font-mono text-gray-300" dir="ltr"><?= e($viewing['ip']) ?></code></p>
                <a href="?ip=<?= urlencode($viewing['ip']) ?>" class="block w-full px-4 py-2.5 rounded-xl bg-white/5 hover:bg-white/10 text-center text-sm text-gray-300 font-semibold">عرض كل نشاط هذا الـ IP</a>
            </div>
            <?php endif; ?>

            <!-- Delete -->
            <form method="POST" onsubmit="return confirm('حذف هذا السجل نهائياً؟')">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= (int) $viewing['id'] ?>">
                <button type="submit" class="w-full px-4 py-3 rounded-xl bg-red-500/10 hover:bg-red-500/20 text-red-300 font-bold text-sm border border-red-500/20">حذف السجل</button>
            </form>
        </div>
    </div>

    <script>
    function copyDetails() {
        const text = document.getElementById('activityDetails').innerText;
        navigator.clipboard.writeText(text);
        event.target.textContent = '✓ تم النسخ';
        setTimeout(() => event.target.textContent = 'نسخ', 1500);
    }
    </script>

<?php else: ?>
    <!-- =================== LIST VIEW =================== -->

    <!-- Stats -->
    <div class="grid grid-cols-2 md:grid-cols-5 gap-4 mb-6">
        <?php
        $cards = [
            ['الإجمالي',         $stats['total'],        'from-blue-500 to-blue-600',  ''],
            ['اليوم',            $stats['today'],        'from-emerald-500 to-teal-500','?date=' . date('Y-m-d')],
            ['أخطاء (24س)',     $stats['errors_24h'],   'from-red-500 to-red-600',    '?status=client'],
            ['زوار فريدون (24س)',$stats['visitors_24h'], 'from-purple-500 to-pink-500',''],
            ['رفع ملفات (24س)',  $stats['uploads_24h'],  'from-amber-500 to-orange-500','?event=upload'],
        ];
        foreach ($cards as [$label, $value, $grad, $link]):
            $href = $link ? BASE_URL . '/super/activity.php' . $link : '#';
            $tag = $link ? 'a' : 'div';
        ?>
        <<?= $tag ?> <?= $link ? 'href="' . $href . '"' : '' ?> class="card rounded-2xl p-5 <?= $link ? 'hover:scale-[1.02] transition-transform cursor-pointer block' : '' ?>">
            <div class="w-10 h-10 rounded-xl bg-gradient-to-br <?= $grad ?> flex items-center justify-center mb-3 shadow-lg">
                <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
            </div>
            <p class="text-3xl font-black text-white"><?= number_format($value) ?></p>
            <p class="text-sm text-gray-400 mt-1"><?= e($label) ?></p>
        </<?= $tag ?>>
        <?php endforeach; ?>
    </div>

    <!-- Top errors + Filters -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
        <!-- Top errors 24h -->
        <div class="card rounded-2xl p-6 lg:col-span-1">
            <h3 class="text-lg font-bold text-white mb-4">أكثر الأخطاء تكراراً (24س)</h3>
            <?php if (!$topErrors): ?>
                <p class="text-gray-500 text-sm">لا توجد أخطاء في آخر 24 ساعة 🎉</p>
            <?php else: ?>
                <div class="space-y-2">
                    <?php foreach ($topErrors as $er): ?>
                    <div class="flex items-center justify-between gap-2 p-3 rounded-xl bg-white/5">
                        <div class="min-w-0 flex-1">
                            <p class="text-xs text-white font-mono truncate" dir="ltr" title="<?= e($er['url']) ?>"><?= e(mb_strimwidth($er['url'] ?? '', 0, 40, '…')) ?></p>
                            <?= statusBadgeHttp($er['http_status']) ?>
                        </div>
                        <span class="px-2 py-1 rounded-lg bg-red-500/20 text-red-300 text-xs font-bold"><?= $er['cnt'] ?>×</span>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Filters -->
        <div class="card rounded-2xl p-6 lg:col-span-2">
            <h3 class="text-lg font-bold text-white mb-4">تصفية السجلات</h3>
            <form method="GET" class="grid grid-cols-2 md:grid-cols-4 gap-3">
                <div class="col-span-2 md:col-span-4">
                    <label class="block text-xs font-semibold text-gray-400 mb-1">بحث (URL، User Agent، Referrer)</label>
                    <input type="text" name="q" value="<?= e($filterSearch) ?>" placeholder="جزء من الرابط..." class="w-full px-3 py-2 rounded-xl border-2 text-sm">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-400 mb-1">المستخدم</label>
                    <select name="user_type" class="w-full px-3 py-2 rounded-xl border-2 text-sm">
                        <option value="">الكل</option>
                        <?php foreach (['admin'=>'سوبر أدمن','store'=>'محل','visitor'=>'زائر'] as $k=>$v): ?>
                            <option value="<?= $k ?>" <?= $filterUserType === $k ? 'selected' : '' ?>><?= $v ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-400 mb-1">الحالة</label>
                    <select name="status" class="w-full px-3 py-2 rounded-xl border-2 text-sm">
                        <option value="">الكل</option>
                        <option value="success" <?= $filterStatus === 'success' ? 'selected' : '' ?>>2xx ناجح</option>
                        <option value="redirect" <?= $filterStatus === 'redirect' ? 'selected' : '' ?>>3xx تحويل</option>
                        <option value="client" <?= $filterStatus === 'client' ? 'selected' : '' ?>>4xx خطأ زبون</option>
                        <option value="server" <?= $filterStatus === 'server' ? 'selected' : '' ?>>5xx خطأ خادم</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-400 mb-1">Method</label>
                    <select name="method" class="w-full px-3 py-2 rounded-xl border-2 text-sm">
                        <option value="">الكل</option>
                        <?php foreach (['GET','POST','PUT','DELETE','PATCH'] as $m): ?>
                            <option value="<?= $m ?>" <?= $filterMethod === $m ? 'selected' : '' ?>><?= $m ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-400 mb-1">نوع الحدث</label>
                    <select name="event" class="w-full px-3 py-2 rounded-xl border-2 text-sm">
                        <option value="">الكل</option>
                        <?php foreach ($eventTypes as $et): ?>
                            <option value="<?= e($et['event_type']) ?>" <?= $filterEvent === $et['event_type'] ? 'selected' : '' ?>>
                                <?= e(eventLabel($et['event_type'])) ?> (<?= $et['cnt'] ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-400 mb-1">التاريخ</label>
                    <input type="date" name="date" value="<?= e($filterDate) ?>" class="w-full px-3 py-2 rounded-xl border-2 text-sm">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-400 mb-1">IP</label>
                    <input type="text" name="ip" value="<?= e($filterIp) ?>" placeholder="1.2.3.4" class="w-full px-3 py-2 rounded-xl border-2 text-sm font-mono" dir="ltr">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-400 mb-1">العدد</label>
                    <select name="limit" class="w-full px-3 py-2 rounded-xl border-2 text-sm">
                        <?php foreach ([50,100,200,500] as $l): ?>
                            <option value="<?= $l ?>" <?= $limit === $l ? 'selected' : '' ?>><?= $l ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-span-2 md:col-span-4 flex flex-wrap gap-2 justify-between items-center">
                    <div class="flex gap-2 flex-wrap">
                        <button type="submit" class="px-5 py-2 rounded-xl bg-gradient-to-r from-emerald-500 to-teal-500 text-white font-bold text-sm">تطبيق</button>
                        <a href="<?= BASE_URL ?>/super/activity.php" class="px-5 py-2 rounded-xl bg-white/5 text-gray-300 hover:bg-white/10 font-bold text-sm">إعادة تعيين</a>
                    </div>
                    <button type="button" onclick="openPurgeModal()" class="px-4 py-2 rounded-xl bg-red-500/10 text-red-300 hover:bg-red-500/20 font-bold text-sm">🗑 تنظيف السجلات</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Activity table -->
    <div class="card rounded-2xl overflow-hidden">
        <div class="p-4 border-b border-white/5 flex items-center justify-between">
            <h3 class="font-bold text-white">الأحداث <span class="text-sm text-gray-500 font-normal">(<?= count($rows) ?> من آخر <?= $limit ?>)</span></h3>
        </div>

        <?php if (!$rows): ?>
            <div class="p-12 text-center">
                <p class="text-gray-400 font-semibold">لا توجد سجلات مطابقة</p>
                <p class="text-gray-500 text-sm mt-1">جرّب تعديل الفلاتر</p>
            </div>
        <?php else: ?>
        <form method="POST" id="actBulkForm">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="bulk_delete">
            <div class="table-scroll">
            <table class="w-full min-w-[1100px]">
                <thead>
                    <tr class="text-xs text-gray-400">
                        <th class="text-center py-3 px-3 w-10"><input type="checkbox" onchange="document.querySelectorAll('.act-check').forEach(c=>c.checked=this.checked)"></th>
                        <th class="text-right py-3 px-4 font-semibold">الوقت</th>
                        <th class="text-right py-3 px-3 font-semibold">Method</th>
                        <th class="text-right py-3 px-3 font-semibold">الحالة</th>
                        <th class="text-right py-3 px-4 font-semibold">الرابط</th>
                        <th class="text-right py-3 px-3 font-semibold">الحدث</th>
                        <th class="text-right py-3 px-3 font-semibold">المستخدم</th>
                        <th class="text-right py-3 px-3 font-semibold">IP</th>
                        <th class="text-center py-3 px-3 font-semibold">ms</th>
                        <th class="text-center py-3 px-3 font-semibold w-16">إجراء</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $row):
                        $st = (int) $row['http_status'];
                        $rowCls = $st >= 500 ? 'bg-red-500/5 hover:bg-red-500/10'
                                : ($st >= 400 ? 'bg-amber-500/5 hover:bg-amber-500/10'
                                : 'hover:bg-white/5');
                    ?>
                    <tr class="border-b border-white/5 <?= $rowCls ?>">
                        <td class="text-center py-3 px-3">
                            <input type="checkbox" name="ids[]" value="<?= (int) $row['id'] ?>" class="act-check">
                        </td>
                        <td class="py-3 px-4 text-xs text-gray-400 whitespace-nowrap">
                            <span class="block" title="<?= e($row['created_at']) ?>"><?= e(date('m/d H:i:s', strtotime($row['created_at']))) ?></span>
                            <span class="text-[10px] text-gray-500"><?= timeAgoAct($row['created_at']) ?></span>
                        </td>
                        <td class="py-3 px-3">
                            <span class="px-2 py-0.5 rounded-lg bg-white/5 font-mono text-xs font-bold text-gray-200"><?= e($row['method']) ?></span>
                        </td>
                        <td class="py-3 px-3"><?= statusBadgeHttp($row['http_status']) ?></td>
                        <td class="py-3 px-4">
                            <a href="?view=<?= (int) $row['id'] ?>" class="block text-xs text-gray-200 font-mono truncate max-w-[360px] hover:text-white" dir="ltr" title="<?= e($row['url']) ?>"><?= e(mb_strimwidth($row['url'] ?? '', 0, 80, '…')) ?></a>
                            <?php if ($row['referrer']): ?>
                                <p class="text-[10px] text-gray-500 mt-0.5 truncate max-w-[360px]" dir="ltr" title="<?= e($row['referrer']) ?>">← <?= e(mb_strimwidth($row['referrer'] ?? '', 0, 60, '…')) ?></p>
                            <?php endif; ?>
                        </td>
                        <td class="py-3 px-3">
                            <span class="text-xs text-indigo-300"><?= e(eventLabel($row['event_type'])) ?></span>
                        </td>
                        <td class="py-3 px-3"><?= userBadgeActivity($row['user_type'], $row['user_id']) ?></td>
                        <td class="py-3 px-3 font-mono text-xs text-gray-300" dir="ltr">
                            <a href="?ip=<?= urlencode($row['ip']) ?>" class="hover:text-white"><?= e($row['ip']) ?></a>
                        </td>
                        <td class="py-3 px-3 text-center">
                            <span class="text-xs font-mono <?= $row['duration_ms'] > 1000 ? 'text-amber-300' : 'text-gray-400' ?>"><?= number_format($row['duration_ms']) ?></span>
                        </td>
                        <td class="py-3 px-3 text-center">
                            <div class="flex items-center gap-1 justify-center">
                                <a href="?view=<?= (int) $row['id'] ?>" class="p-1.5 rounded-lg bg-white/5 hover:bg-white/10 text-gray-300" title="عرض">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                                </a>
                                <button type="button" onclick="actDeleteOne(<?= (int) $row['id'] ?>)" class="p-1.5 rounded-lg bg-red-500/10 hover:bg-red-500/20 text-red-300" title="حذف">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6M1 7h22M9 7V4a1 1 0 011-1h4a1 1 0 011 1v3"/></svg>
                                </button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <div class="p-4 border-t border-white/5 flex items-center justify-between flex-wrap gap-2">
                <p class="text-sm text-gray-400">حدد أكثر من سجل للحذف الجماعي</p>
                <button type="button" onclick="actBulkDelete()" class="px-4 py-2 rounded-xl bg-red-500/20 text-red-300 hover:bg-red-500/30 font-bold text-sm">🗑 حذف المحدّدين</button>
            </div>
        </form>
        <?php endif; ?>
    </div>

    <!-- Hidden single-delete form -->
    <form method="POST" id="actSingleDelete" style="display:none">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="id" id="actSingleId">
    </form>

    <!-- Purge modal -->
    <div id="purgeModal" class="fixed inset-0 bg-black/70 z-50 hidden items-center justify-center p-4" onclick="if(event.target===this) closePurgeModal()">
        <div class="card rounded-2xl w-full max-w-lg">
            <div class="p-6 border-b border-white/5 flex items-center justify-between">
                <h3 class="text-lg font-bold text-white">🗑 تنظيف السجلات</h3>
                <button type="button" onclick="closePurgeModal()" class="text-gray-400 hover:text-white">&times;</button>
            </div>
            <div class="p-6 space-y-3">
                <!-- Older than X days -->
                <form method="POST" class="p-4 rounded-xl bg-blue-500/10 border border-blue-500/20" onsubmit="return confirm('حذف السجلات الأقدم من المدة المحددة؟')">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="purge">
                    <input type="hidden" name="mode" value="older">
                    <p class="font-bold text-blue-300 mb-2 text-right">حذف الأقدم من مدة محددة</p>
                    <div class="flex gap-2">
                        <select name="days" class="flex-1 px-3 py-2 rounded-xl border-2 text-sm">
                            <option value="7">7 أيام</option>
                            <option value="30" selected>30 يوم</option>
                            <option value="60">60 يوم</option>
                            <option value="90">90 يوم</option>
                            <option value="180">180 يوم</option>
                            <option value="365">سنة كاملة</option>
                        </select>
                        <button type="submit" class="px-4 py-2 rounded-xl bg-blue-500 hover:bg-blue-600 text-white font-bold text-sm">حذف</button>
                    </div>
                </form>

                <!-- Error entries only -->
                <form method="POST" onsubmit="return confirm('حذف كل سجلات الأخطاء (4xx و 5xx)؟')">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="purge">
                    <input type="hidden" name="mode" value="errors_only">
                    <button type="submit" class="w-full p-4 rounded-xl bg-amber-500/10 hover:bg-amber-500/20 text-right border border-amber-500/20">
                        <p class="font-bold text-amber-300">حذف سجلات الأخطاء فقط</p>
                        <p class="text-xs text-gray-400 mt-1">كل الأحداث التي حالتها HTTP 4xx أو 5xx</p>
                    </button>
                </form>

                <!-- Purge all -->
                <form method="POST" class="p-4 rounded-xl bg-red-500/10 border border-red-500/30">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="purge">
                    <input type="hidden" name="mode" value="all">
                    <p class="font-bold text-red-300 mb-2 text-right">⚠️ حذف كل السجلات</p>
                    <p class="text-xs text-gray-400 mb-3 text-right">هذا الإجراء لا يمكن التراجع عنه. اكتب <code class="bg-black/30 px-2 py-0.5 rounded text-red-300">DELETE_ALL</code></p>
                    <div class="flex gap-2">
                        <input type="text" name="confirm" placeholder="DELETE_ALL" class="flex-1 px-3 py-2 rounded-xl border-2 text-sm font-mono" required>
                        <button type="submit" class="px-4 py-2 rounded-xl bg-red-500 hover:bg-red-600 text-white font-bold text-sm">حذف الكل</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
    function openPurgeModal() {
        const m = document.getElementById('purgeModal');
        m.classList.remove('hidden'); m.classList.add('flex');
    }
    function closePurgeModal() {
        const m = document.getElementById('purgeModal');
        m.classList.add('hidden'); m.classList.remove('flex');
    }
    function actDeleteOne(id) {
        if (!confirm('حذف هذا السجل نهائياً؟')) return;
        document.getElementById('actSingleId').value = id;
        document.getElementById('actSingleDelete').submit();
    }
    function actBulkDelete() {
        const checked = document.querySelectorAll('.act-check:checked');
        if (!checked.length) return alert('لم تختر أي سجل');
        if (!confirm('حذف ' + checked.length + ' سجل نهائياً؟')) return;
        document.getElementById('actBulkForm').submit();
    }
    </script>

<?php endif; ?>

<?php require __DIR__ . '/../includes/footer_super.php'; ?>
