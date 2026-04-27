<?php
require_once __DIR__ . '/../includes/functions.php';
requireAdminLogin();
$pageTitle = 'سجل الأخطاء';

// ====================================================
// POST actions (status change, notes, delete, purge)
// ====================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrfCheck()) {
    $action = $_POST['action'] ?? '';
    $adminId = (int) $_SESSION['admin_id'];

    if ($action === 'update_status') {
        $id = clean_int($_POST['id'] ?? 0, 1);
        $status = clean_string($_POST['status'] ?? 'new', 20);
        if (!in_array($status, ['new','investigating','resolved','ignored'], true)) {
            flash('error', 'حالة غير صالحة');
        } else {
            $resolvedAt = ($status === 'resolved') ? 'CURRENT_TIMESTAMP' : 'NULL';
            $resolvedBy = ($status === 'resolved') ? $adminId : null;
            $pdo->prepare("UPDATE error_logs SET status = ?, resolved_at = $resolvedAt, resolved_by = ? WHERE id = ?")
                ->execute([$status, $resolvedBy, $id]);
            flash('success', 'تم تحديث الحالة');
        }
        redirect(BASE_URL . '/super/errors.php' . (!empty($_POST['return_view']) ? '?view=' . urlencode($_POST['return_view']) : ''));
    }

    if ($action === 'save_notes') {
        $id = clean_int($_POST['id'] ?? 0, 1);
        $notes = clean_string($_POST['notes'] ?? '', 5000);
        $pdo->prepare('UPDATE error_logs SET notes = ? WHERE id = ?')->execute([$notes, $id]);
        flash('success', 'تم حفظ الملاحظات');
        redirect(BASE_URL . '/super/errors.php' . (!empty($_POST['return_view']) ? '?view=' . urlencode($_POST['return_view']) : ''));
    }

    if ($action === 'delete') {
        $id = clean_int($_POST['id'] ?? 0, 1);
        $stmt = $pdo->prepare('DELETE FROM error_logs WHERE id = ?');
        $stmt->execute([$id]);
        flash('success', $stmt->rowCount() ? 'تم حذف السجل' : 'السجل غير موجود');
        redirect(BASE_URL . '/super/errors.php');
    }

    if ($action === 'bulk_delete') {
        $ids = array_filter(array_map('intval', $_POST['ids'] ?? []));
        if ($ids) {
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $pdo->prepare("DELETE FROM error_logs WHERE id IN ($ph)");
            $stmt->execute($ids);
            flash('success', 'تم حذف ' . $stmt->rowCount() . ' سجل');
        } else {
            flash('error', 'لم تختر أي سجل');
        }
        redirect(BASE_URL . '/super/errors.php');
    }

    // Multi-mode purge: 'resolved' | 'all' | 'older'
    if ($action === 'purge') {
        $mode = $_POST['mode'] ?? '';
        $sql = null;

        if ($mode === 'resolved') {
            $sql = "DELETE FROM error_logs WHERE status IN ('resolved','ignored')";
        } elseif ($mode === 'older') {
            $days = clean_int($_POST['days'] ?? 30, 1, 365);
            $sql = "DELETE FROM error_logs WHERE last_seen_at < (NOW() - INTERVAL $days DAY)";
        } elseif ($mode === 'all') {
            // Strong confirm via typed keyword
            if (($_POST['confirm'] ?? '') !== 'DELETE_ALL') {
                flash('error', 'للحذف الكامل اكتب DELETE_ALL في خانة التأكيد');
                redirect(BASE_URL . '/super/errors.php');
            }
            $sql = "DELETE FROM error_logs";
        }

        if ($sql) {
            $affected = $pdo->exec($sql);
            flash('success', "تم حذف $affected سجل");
        } else {
            flash('error', 'وضع حذف غير صالح');
        }
        redirect(BASE_URL . '/super/errors.php');
    }

    if ($action === 'bulk_resolve') {
        $ids = array_filter(array_map('intval', $_POST['ids'] ?? []));
        if ($ids) {
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $pdo->prepare("UPDATE error_logs SET status = 'resolved', resolved_at = CURRENT_TIMESTAMP, resolved_by = ? WHERE id IN ($ph)");
            $stmt->execute(array_merge([$adminId], $ids));
            flash('success', 'تم تحديد ' . $stmt->rowCount() . ' خطأ كمحلول');
        } else {
            flash('error', 'لم تختر أي سجل');
        }
        redirect(BASE_URL . '/super/errors.php');
    }
}

// ====================================================
// DETAIL VIEW (?view=E-XXXXXX)
// ====================================================
$viewCode = clean_string($_GET['view'] ?? '', 20);
$viewing = null;
if ($viewCode !== '') {
    $stmt = $pdo->prepare('SELECT e.*, a.name AS resolver_name FROM error_logs e LEFT JOIN admins a ON e.resolved_by = a.id WHERE e.code = ? LIMIT 1');
    $stmt->execute([$viewCode]);
    $viewing = $stmt->fetch();
}

// ====================================================
// LIST VIEW — filters + counts
// ====================================================
$filterStatus = clean_string($_GET['status'] ?? '', 20);
$filterSeverity = clean_string($_GET['severity'] ?? '', 20);
$filterSearch = clean_string($_GET['q'] ?? '', 200);
$limit = clean_int($_GET['limit'] ?? 100, 10, 500);

$where = [];
$params = [];
if (in_array($filterStatus, ['new','investigating','resolved','ignored'], true)) {
    $where[] = 'status = ?'; $params[] = $filterStatus;
}
if (in_array($filterSeverity, ['low','medium','high','critical'], true)) {
    $where[] = 'severity = ?'; $params[] = $filterSeverity;
}
if ($filterSearch !== '') {
    $where[] = '(code LIKE ? OR message LIKE ? OR file LIKE ? OR type LIKE ?)';
    $like = '%' . $filterSearch . '%';
    $params = array_merge($params, [$like, $like, $like, $like]);
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$sql = "SELECT * FROM error_logs $whereSql ORDER BY (status = 'new') DESC, last_seen_at DESC LIMIT " . $limit;
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$errors = $stmt->fetchAll();

// Stats
$stats = [
    'total'         => (int) $pdo->query("SELECT COUNT(*) FROM error_logs")->fetchColumn(),
    'new'           => (int) $pdo->query("SELECT COUNT(*) FROM error_logs WHERE status='new'")->fetchColumn(),
    'investigating' => (int) $pdo->query("SELECT COUNT(*) FROM error_logs WHERE status='investigating'")->fetchColumn(),
    'resolved'      => (int) $pdo->query("SELECT COUNT(*) FROM error_logs WHERE status='resolved'")->fetchColumn(),
    'critical_24h'  => (int) $pdo->query("SELECT COUNT(*) FROM error_logs WHERE severity='critical' AND last_seen_at > (NOW() - INTERVAL 24 HOUR)")->fetchColumn(),
];

require __DIR__ . '/../includes/header_super.php';

// ====================================================
// Helpers for rendering
// ====================================================
function sevBadge($sev) {
    $map = [
        'critical' => 'bg-red-500/20 text-red-300 border-red-500/30',
        'high'     => 'bg-orange-500/20 text-orange-300 border-orange-500/30',
        'medium'   => 'bg-amber-500/20 text-amber-300 border-amber-500/30',
        'low'      => 'bg-blue-500/20 text-blue-300 border-blue-500/30',
    ];
    $label = ['critical'=>'حرج','high'=>'عالي','medium'=>'متوسط','low'=>'منخفض'];
    $cls = $map[$sev] ?? 'bg-gray-500/20 text-gray-300';
    return '<span class="px-2 py-1 rounded-lg text-xs font-bold border ' . $cls . '">' . ($label[$sev] ?? $sev) . '</span>';
}
function statusBadge($s) {
    $map = [
        'new'           => ['bg-red-500/20 text-red-300', 'جديد'],
        'investigating' => ['bg-amber-500/20 text-amber-300', 'قيد الفحص'],
        'resolved'      => ['bg-emerald-500/20 text-emerald-300', 'محلول'],
        'ignored'       => ['bg-gray-500/20 text-gray-400', 'متجاهل'],
    ];
    [$cls, $label] = $map[$s] ?? ['bg-gray-500/20 text-gray-300', $s];
    return '<span class="px-2 py-1 rounded-lg text-xs font-bold ' . $cls . '">' . $label . '</span>';
}
function timeAgo($datetime) {
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
        <a href="<?= BASE_URL ?>/super/errors.php" class="inline-flex items-center gap-2 text-sm text-gray-400 hover:text-white">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
            العودة لقائمة الأخطاء
        </a>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Left: main error details -->
        <div class="lg:col-span-2 space-y-6">
            <!-- Header card -->
            <div class="card rounded-2xl p-6">
                <div class="flex items-start justify-between gap-4 flex-wrap mb-4">
                    <div>
                        <div class="flex items-center gap-3 mb-2">
                            <code class="text-2xl font-black text-amber-400 font-mono"><?= e($viewing['code']) ?></code>
                            <?= sevBadge($viewing['severity']) ?>
                            <?= statusBadge($viewing['status']) ?>
                        </div>
                        <p class="font-mono text-sm text-gray-400"><?= e($viewing['type']) ?></p>
                    </div>
                    <div class="text-right">
                        <p class="text-3xl font-black text-white"><?= number_format($viewing['count']) ?></p>
                        <p class="text-xs text-gray-500">مرة حدث</p>
                    </div>
                </div>

                <div class="bg-red-500/10 border border-red-500/20 rounded-xl p-4 mb-4">
                    <p class="text-xs text-red-300 font-semibold mb-1">رسالة الخطأ</p>
                    <p class="text-white font-medium" style="word-break:break-word"><?= e($viewing['message']) ?></p>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-3 text-sm">
                    <div class="bg-white/5 rounded-xl p-3">
                        <p class="text-xs text-gray-500 mb-1">الملف والسطر</p>
                        <p class="font-mono text-gray-200 text-xs break-all" dir="ltr"><?= e($viewing['file']) ?>:<?= (int) $viewing['line'] ?></p>
                    </div>
                    <div class="bg-white/5 rounded-xl p-3">
                        <p class="text-xs text-gray-500 mb-1">أول ظهور</p>
                        <p class="text-gray-200"><?= e(date('Y-m-d H:i:s', strtotime($viewing['first_seen_at']))) ?></p>
                    </div>
                    <div class="bg-white/5 rounded-xl p-3">
                        <p class="text-xs text-gray-500 mb-1">آخر ظهور</p>
                        <p class="text-gray-200"><?= e(date('Y-m-d H:i:s', strtotime($viewing['last_seen_at']))) ?> <span class="text-gray-500">(<?= timeAgo($viewing['last_seen_at']) ?>)</span></p>
                    </div>
                    <div class="bg-white/5 rounded-xl p-3">
                        <p class="text-xs text-gray-500 mb-1">الرابط</p>
                        <p class="text-gray-200 text-xs break-all" dir="ltr"><?= $viewing['method'] ? e($viewing['method']) . ' ' : '' ?><?= e($viewing['url'] ?: '—') ?></p>
                    </div>
                </div>
            </div>

            <!-- Stack Trace -->
            <div class="card rounded-2xl overflow-hidden">
                <div class="p-4 border-b border-white/5 flex items-center justify-between">
                    <h3 class="font-bold text-white">Stack Trace</h3>
                    <button onclick="copyTrace()" class="px-3 py-1.5 rounded-lg bg-white/5 hover:bg-white/10 text-xs text-gray-300">نسخ</button>
                </div>
                <pre id="stackTrace" class="p-5 text-xs text-gray-300 font-mono overflow-x-auto" dir="ltr" style="white-space:pre-wrap;word-break:break-word"><?= e($viewing['stack_trace'] ?: '(لا يوجد stack trace)') ?></pre>
            </div>

            <!-- Request Context -->
            <?php $ctx = json_decode($viewing['context'] ?? '', true); ?>
            <?php if ($ctx): ?>
            <div class="card rounded-2xl overflow-hidden">
                <div class="p-4 border-b border-white/5">
                    <h3 class="font-bold text-white">سياق الطلب</h3>
                    <p class="text-xs text-gray-500 mt-1">بيانات الطلب المرسلة وقت الخطأ (كلمات المرور محجوبة)</p>
                </div>
                <pre class="p-5 text-xs text-gray-300 font-mono overflow-x-auto" dir="ltr" style="white-space:pre-wrap;word-break:break-word"><?= e(json_encode($ctx, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre>
            </div>
            <?php endif; ?>
        </div>

        <!-- Right: actions + metadata -->
        <div class="space-y-6">
            <!-- Status actions -->
            <div class="card rounded-2xl p-6">
                <h3 class="font-bold text-white mb-4">تغيير الحالة</h3>
                <form method="POST" class="space-y-2">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="update_status">
                    <input type="hidden" name="id" value="<?= (int) $viewing['id'] ?>">
                    <input type="hidden" name="return_view" value="<?= e($viewing['code']) ?>">
                    <?php
                    $statuses = [
                        'new' => ['جديد', 'bg-red-500/20 text-red-300 hover:bg-red-500/30'],
                        'investigating' => ['قيد الفحص', 'bg-amber-500/20 text-amber-300 hover:bg-amber-500/30'],
                        'resolved' => ['محلول', 'bg-emerald-500/20 text-emerald-300 hover:bg-emerald-500/30'],
                        'ignored' => ['متجاهل', 'bg-gray-500/20 text-gray-300 hover:bg-gray-500/30'],
                    ];
                    foreach ($statuses as $k => [$label, $cls]):
                        $isActive = $viewing['status'] === $k;
                    ?>
                    <button type="submit" name="status" value="<?= $k ?>" class="w-full px-4 py-2.5 rounded-xl font-semibold text-sm text-right flex items-center justify-between <?= $cls ?> <?= $isActive ? 'ring-2 ring-white/30' : '' ?>">
                        <span><?= $label ?></span>
                        <?php if ($isActive): ?><span class="text-xs">● الحالي</span><?php endif; ?>
                    </button>
                    <?php endforeach; ?>
                </form>

                <?php if ($viewing['status'] === 'resolved' && $viewing['resolver_name']): ?>
                <div class="mt-4 pt-4 border-t border-white/5 text-xs text-gray-500">
                    <p>حُلّ بواسطة <strong class="text-emerald-300"><?= e($viewing['resolver_name']) ?></strong></p>
                    <p><?= e(date('Y-m-d H:i', strtotime($viewing['resolved_at']))) ?></p>
                </div>
                <?php endif; ?>
            </div>

            <!-- Notes -->
            <div class="card rounded-2xl p-6">
                <h3 class="font-bold text-white mb-3">ملاحظات</h3>
                <form method="POST">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="save_notes">
                    <input type="hidden" name="id" value="<?= (int) $viewing['id'] ?>">
                    <input type="hidden" name="return_view" value="<?= e($viewing['code']) ?>">
                    <textarea name="notes" rows="5" class="w-full px-3 py-2 rounded-xl border-2 text-sm" placeholder="سبب المشكلة، خطوات الحل، إلخ..."><?= e($viewing['notes'] ?? '') ?></textarea>
                    <button type="submit" class="mt-3 w-full px-4 py-2.5 rounded-xl bg-gradient-to-r from-emerald-500 to-teal-500 text-white font-bold text-sm">حفظ</button>
                </form>
            </div>

            <!-- Request info -->
            <div class="card rounded-2xl p-6">
                <h3 class="font-bold text-white mb-3">معلومات الطلب</h3>
                <dl class="text-xs space-y-2">
                    <div>
                        <dt class="text-gray-500">المستخدم</dt>
                        <dd class="text-gray-200 font-mono"><?= $viewing['user_type'] ? e($viewing['user_type']) . ($viewing['user_id'] ? ' #' . (int) $viewing['user_id'] : '') : 'زائر' ?></dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">IP</dt>
                        <dd class="text-gray-200 font-mono" dir="ltr"><?= e($viewing['ip'] ?: '—') ?></dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">User Agent</dt>
                        <dd class="text-gray-300 break-all" dir="ltr" style="font-size:10px"><?= e($viewing['user_agent'] ?: '—') ?></dd>
                    </div>
                </dl>
            </div>

            <!-- Delete -->
            <form method="POST" onsubmit="return confirm('هل أنت متأكد من حذف هذا السجل؟ سيُعاد إنشاؤه إذا ظهر الخطأ مرة أخرى.')">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= (int) $viewing['id'] ?>">
                <button type="submit" class="w-full px-4 py-3 rounded-xl bg-red-500/10 hover:bg-red-500/20 text-red-300 font-bold text-sm border border-red-500/20">حذف السجل</button>
            </form>
        </div>
    </div>

    <script>
    function copyTrace() {
        const text = document.getElementById('stackTrace').innerText;
        navigator.clipboard.writeText(text);
        event.target.textContent = '✓ تم النسخ';
        setTimeout(() => event.target.textContent = 'نسخ', 1500);
    }
    </script>

<?php else: ?>
    <!-- =================== LIST VIEW =================== -->

    <!-- Stats cards -->
    <div class="grid grid-cols-2 md:grid-cols-5 gap-4 mb-6">
        <?php
        $cards = [
            ['الإجمالي', $stats['total'], 'from-blue-500 to-blue-600', ''],
            ['جديد', $stats['new'], 'from-red-500 to-red-600', 'new'],
            ['قيد الفحص', $stats['investigating'], 'from-amber-500 to-orange-500', 'investigating'],
            ['محلول', $stats['resolved'], 'from-emerald-500 to-teal-500', 'resolved'],
            ['حرج (24س)', $stats['critical_24h'], 'from-pink-500 to-rose-600', ''],
        ];
        foreach ($cards as [$label, $value, $grad, $statusLink]):
            $href = $statusLink ? BASE_URL . '/super/errors.php?status=' . $statusLink : '#';
            $tag = $statusLink ? 'a' : 'div';
        ?>
        <<?= $tag ?> <?= $statusLink ? 'href="' . $href . '"' : '' ?> class="card rounded-2xl p-5 <?= $statusLink ? 'hover:scale-[1.02] transition-transform cursor-pointer block' : '' ?>">
            <div class="w-10 h-10 rounded-xl bg-gradient-to-br <?= $grad ?> flex items-center justify-center mb-3 shadow-lg">
                <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M4.93 19h14.14a2 2 0 001.73-3L13.73 4a2 2 0 00-3.46 0L3.2 16a2 2 0 001.73 3z"/></svg>
            </div>
            <p class="text-3xl font-black text-white"><?= number_format($value) ?></p>
            <p class="text-sm text-gray-400 mt-1"><?= e($label) ?></p>
        </<?= $tag ?>>
        <?php endforeach; ?>
    </div>

    <!-- Filters -->
    <div class="card rounded-2xl p-6 mb-6">
        <form method="GET" class="grid grid-cols-1 md:grid-cols-5 gap-3">
            <div class="md:col-span-2">
                <label class="block text-xs font-semibold text-gray-400 mb-1">بحث</label>
                <input type="text" name="q" value="<?= e($filterSearch) ?>" placeholder="كود، رسالة، ملف، نوع..." class="w-full px-3 py-2 rounded-xl border-2 text-sm">
            </div>
            <div>
                <label class="block text-xs font-semibold text-gray-400 mb-1">الحالة</label>
                <select name="status" class="w-full px-3 py-2 rounded-xl border-2 text-sm">
                    <option value="">الكل</option>
                    <?php foreach (['new'=>'جديد','investigating'=>'قيد الفحص','resolved'=>'محلول','ignored'=>'متجاهل'] as $k=>$v): ?>
                        <option value="<?= $k ?>" <?= $filterStatus === $k ? 'selected' : '' ?>><?= $v ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-xs font-semibold text-gray-400 mb-1">الخطورة</label>
                <select name="severity" class="w-full px-3 py-2 rounded-xl border-2 text-sm">
                    <option value="">الكل</option>
                    <?php foreach (['critical'=>'حرج','high'=>'عالي','medium'=>'متوسط','low'=>'منخفض'] as $k=>$v): ?>
                        <option value="<?= $k ?>" <?= $filterSeverity === $k ? 'selected' : '' ?>><?= $v ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-xs font-semibold text-gray-400 mb-1">العدد</label>
                <select name="limit" class="w-full px-3 py-2 rounded-xl border-2 text-sm">
                    <?php foreach ([50,100,200,500] as $l): ?>
                        <option value="<?= $l ?>" <?= $limit === $l ? 'selected' : '' ?>><?= $l ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="md:col-span-5 flex flex-wrap gap-2 justify-between items-center">
                <div class="flex gap-2 flex-wrap">
                    <button type="submit" class="px-5 py-2 rounded-xl bg-gradient-to-r from-emerald-500 to-teal-500 text-white font-bold text-sm">تطبيق</button>
                    <a href="<?= BASE_URL ?>/super/errors.php" class="px-5 py-2 rounded-xl bg-white/5 text-gray-300 hover:bg-white/10 font-bold text-sm">إعادة تعيين</a>
                </div>
                <div class="flex gap-2 flex-wrap">
                    <button type="button" onclick="openPurgeModal()" class="px-4 py-2 rounded-xl bg-red-500/10 text-red-300 hover:bg-red-500/20 font-bold text-sm">🗑 تنظيف السجلات</button>
                </div>
            </div>
        </form>
    </div>

    <!-- Errors table -->
    <div class="card rounded-2xl overflow-hidden">
        <div class="p-4 border-b border-white/5 flex items-center justify-between">
            <h3 class="font-bold text-white">الأخطاء <span class="text-sm text-gray-500 font-normal">(<?= count($errors) ?>)</span></h3>
        </div>

        <?php if (!$errors): ?>
            <div class="p-12 text-center">
                <div class="w-16 h-16 mx-auto rounded-full bg-emerald-500/10 flex items-center justify-center mb-4">
                    <svg class="w-8 h-8 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                </div>
                <p class="text-gray-400 font-semibold">لا توجد أخطاء مطابقة</p>
                <p class="text-gray-500 text-sm mt-1">إذا لم تكن هناك أخطاء مسجّلة، فهذه إشارة جيدة!</p>
            </div>
        <?php else: ?>
        <form method="POST" id="bulkForm">
            <?= csrfField() ?>
            <input type="hidden" name="action" id="bulkActionInput" value="bulk_resolve">
            <div class="table-scroll">
            <table class="w-full min-w-[980px]">
                <thead>
                    <tr class="text-xs text-gray-400">
                        <th class="text-center py-3 px-3 w-10"><input type="checkbox" onchange="document.querySelectorAll('.err-check').forEach(c=>c.checked=this.checked)"></th>
                        <th class="text-right py-3 px-4 font-semibold">المعرّف</th>
                        <th class="text-right py-3 px-4 font-semibold">الخطورة</th>
                        <th class="text-right py-3 px-4 font-semibold">الرسالة</th>
                        <th class="text-right py-3 px-4 font-semibold">الموقع</th>
                        <th class="text-center py-3 px-3 font-semibold">التكرار</th>
                        <th class="text-right py-3 px-4 font-semibold">آخر ظهور</th>
                        <th class="text-right py-3 px-4 font-semibold">الحالة</th>
                        <th class="text-center py-3 px-3 font-semibold w-20">إجراء</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($errors as $err):
                        $isNew = $err['status'] === 'new';
                        $rowCls = $isNew ? 'bg-red-500/5 hover:bg-red-500/10' : 'hover:bg-white/5';
                    ?>
                    <tr class="border-b border-white/5 <?= $rowCls ?>">
                        <td class="text-center py-3 px-3">
                            <input type="checkbox" name="ids[]" value="<?= (int) $err['id'] ?>" class="err-check">
                        </td>
                        <td class="py-3 px-4">
                            <a href="?view=<?= urlencode($err['code']) ?>" class="font-mono font-bold text-amber-400 hover:text-amber-300"><?= e($err['code']) ?></a>
                        </td>
                        <td class="py-3 px-4"><?= sevBadge($err['severity']) ?></td>
                        <td class="py-3 px-4">
                            <a href="?view=<?= urlencode($err['code']) ?>" class="block">
                                <p class="text-sm text-white font-medium truncate max-w-md" title="<?= e($err['message']) ?>"><?= e(mb_strimwidth($err['message'] ?? '', 0, 100, '…')) ?></p>
                                <p class="text-xs text-gray-500 font-mono mt-0.5"><?= e($err['type']) ?></p>
                            </a>
                        </td>
                        <td class="py-3 px-4">
                            <p class="text-xs text-gray-400 font-mono truncate max-w-[240px]" dir="ltr" title="<?= e($err['file']) ?>"><?= e(basename($err['file'] ?? '')) ?>:<?= (int) $err['line'] ?></p>
                        </td>
                        <td class="py-3 px-3 text-center">
                            <span class="inline-block px-2.5 py-1 rounded-lg bg-white/5 text-sm font-bold text-white"><?= number_format($err['count']) ?></span>
                        </td>
                        <td class="py-3 px-4 text-xs text-gray-400 whitespace-nowrap"><?= timeAgo($err['last_seen_at']) ?></td>
                        <td class="py-3 px-4"><?= statusBadge($err['status']) ?></td>
                        <td class="py-3 px-3 text-center">
                            <button type="button" onclick="deleteOne(<?= (int) $err['id'] ?>, '<?= e($err['code']) ?>')" class="p-1.5 rounded-lg bg-red-500/10 hover:bg-red-500/20 text-red-300" title="حذف">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6M1 7h22M9 7V4a1 1 0 011-1h4a1 1 0 011 1v3"/></svg>
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <div class="p-4 border-t border-white/5 flex items-center justify-between flex-wrap gap-2">
                <p class="text-sm text-gray-400">حدد أكثر من سجل لتطبيق إجراء جماعي</p>
                <div class="flex gap-2 flex-wrap">
                    <button type="button" onclick="submitBulk('bulk_resolve', 'تحديد المحدّدين كمحلول؟')" class="px-4 py-2 rounded-xl bg-emerald-500/20 text-emerald-300 hover:bg-emerald-500/30 font-bold text-sm">✓ تحديد كمحلول</button>
                    <button type="button" onclick="submitBulk('bulk_delete', 'حذف السجلات المحدّدة نهائياً؟')" class="px-4 py-2 rounded-xl bg-red-500/20 text-red-300 hover:bg-red-500/30 font-bold text-sm">🗑 حذف المحدّدين</button>
                </div>
            </div>
        </form>
        <?php endif; ?>
    </div>

    <!-- Single-delete form (hidden, submitted via JS) -->
    <form method="POST" id="singleDeleteForm" style="display:none">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="id" id="singleDeleteId">
    </form>

    <!-- Purge modal -->
    <div id="purgeModal" class="fixed inset-0 bg-black/70 z-50 hidden items-center justify-center p-4" onclick="if(event.target===this) closePurgeModal()">
        <div class="card rounded-2xl w-full max-w-lg">
            <div class="p-6 border-b border-white/5 flex items-center justify-between">
                <h3 class="text-lg font-bold text-white">🗑 تنظيف السجلات</h3>
                <button type="button" onclick="closePurgeModal()" class="text-gray-400 hover:text-white">&times;</button>
            </div>
            <div class="p-6 space-y-3">
                <!-- Option 1: Purge resolved -->
                <form method="POST" onsubmit="return confirm('حذف كل السجلات المحلولة والمتجاهلة؟')">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="purge">
                    <input type="hidden" name="mode" value="resolved">
                    <button type="submit" class="w-full p-4 rounded-xl bg-amber-500/10 hover:bg-amber-500/20 text-right border border-amber-500/20">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="font-bold text-amber-300">حذف المحلولة/المتجاهلة</p>
                                <p class="text-xs text-gray-400 mt-1">كل الأخطاء التي حالتها "محلول" أو "متجاهل"</p>
                            </div>
                            <span class="text-amber-400"><?= (int) ($stats['resolved']) ?></span>
                        </div>
                    </button>
                </form>

                <!-- Option 2: Purge older than X days -->
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
                        </select>
                        <button type="submit" class="px-4 py-2 rounded-xl bg-blue-500 hover:bg-blue-600 text-white font-bold text-sm">حذف</button>
                    </div>
                </form>

                <!-- Option 3: Purge all (dangerous) -->
                <form method="POST" class="p-4 rounded-xl bg-red-500/10 border border-red-500/30">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="purge">
                    <input type="hidden" name="mode" value="all">
                    <p class="font-bold text-red-300 mb-2 text-right">⚠️ حذف كل السجلات</p>
                    <p class="text-xs text-gray-400 mb-3 text-right">هذا الإجراء لا يمكن التراجع عنه. اكتب <code class="bg-black/30 px-2 py-0.5 rounded text-red-300">DELETE_ALL</code> للتأكيد.</p>
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
    function deleteOne(id, code) {
        if (!confirm('حذف الخطأ ' + code + ' نهائياً؟\nسيُعاد إنشاؤه إذا ظهر مرة أخرى.')) return;
        document.getElementById('singleDeleteId').value = id;
        document.getElementById('singleDeleteForm').submit();
    }
    function submitBulk(action, confirmMsg) {
        const checked = document.querySelectorAll('.err-check:checked');
        if (!checked.length) return alert('لم تختر أي سجل');
        if (!confirm(confirmMsg + ' (' + checked.length + ')')) return;
        document.getElementById('bulkActionInput').value = action;
        document.getElementById('bulkForm').submit();
    }
    </script>

<?php endif; ?>

<?php require __DIR__ . '/../includes/footer_super.php'; ?>
