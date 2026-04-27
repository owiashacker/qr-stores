<?php
require_once __DIR__ . '/../includes/functions.php';
requireAdminLogin();
$pageTitle = 'الأمن والسجلات';

// POST actions: delete, bulk_delete, purge, purge_all
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrfCheck()) {
    $action = $_POST['action'] ?? '';

    if ($action === 'delete') {
        $id = clean_int($_POST['id'] ?? 0, 1);
        $stmt = $pdo->prepare('DELETE FROM security_logs WHERE id = ?');
        $stmt->execute([$id]);
        flash('success', $stmt->rowCount() ? 'تم حذف السجل' : 'السجل غير موجود');
        redirect(BASE_URL . '/super/security.php');
    }

    if ($action === 'bulk_delete') {
        $ids = array_filter(array_map('intval', $_POST['ids'] ?? []));
        if ($ids) {
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $pdo->prepare("DELETE FROM security_logs WHERE id IN ($ph)");
            $stmt->execute($ids);
            flash('success', 'تم حذف ' . $stmt->rowCount() . ' سجل');
        } else {
            flash('error', 'لم تختر أي سجل');
        }
        redirect(BASE_URL . '/super/security.php');
    }

    if ($action === 'purge') {
        // Embed int directly (safe after clean_int)
        $days = clean_int($_POST['days'] ?? 90, 1, 365);
        $a = $pdo->exec("DELETE FROM security_logs WHERE created_at < (NOW() - INTERVAL $days DAY)");
        $b = $pdo->exec("DELETE FROM login_attempts WHERE created_at < (NOW() - INTERVAL $days DAY)");
        security_log($pdo, 'logs_purged', 'info', ['days' => $days, 'security_deleted' => $a, 'login_deleted' => $b], 'admin', $_SESSION['admin_id']);
        flash('success', "تم حذف " . ($a + $b) . " سجل (الأقدم من $days يوم)");
        redirect(BASE_URL . '/super/security.php');
    }

    if ($action === 'purge_all') {
        if (($_POST['confirm'] ?? '') !== 'DELETE_ALL') {
            flash('error', 'اكتب DELETE_ALL للتأكيد');
            redirect(BASE_URL . '/super/security.php');
        }
        $a = $pdo->exec("DELETE FROM security_logs");
        $b = $pdo->exec("DELETE FROM login_attempts");
        flash('success', "تم حذف كل السجلات ($a + $b)");
        redirect(BASE_URL . '/super/security.php');
    }
}

// Filters
$severityFilter = clean_string($_GET['severity'] ?? '', 20);
$eventFilter = clean_string($_GET['event'] ?? '', 50);
$limit = clean_int($_GET['limit'] ?? 100, 10, 500);

$sql = 'SELECT * FROM security_logs WHERE 1=1';
$params = [];
if ($severityFilter) { $sql .= ' AND severity = ?'; $params[] = $severityFilter; }
if ($eventFilter) { $sql .= ' AND event_type = ?'; $params[] = $eventFilter; }
$sql .= ' ORDER BY created_at DESC LIMIT ' . $limit;
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$logs = $stmt->fetchAll();

// Counters for dashboard
$counts = [
    'total' => (int) $pdo->query("SELECT COUNT(*) FROM security_logs")->fetchColumn(),
    'critical' => (int) $pdo->query("SELECT COUNT(*) FROM security_logs WHERE severity='critical'")->fetchColumn(),
    'warning' => (int) $pdo->query("SELECT COUNT(*) FROM security_logs WHERE severity='warning'")->fetchColumn(),
    'failed_logins' => (int) $pdo->query("SELECT COUNT(*) FROM login_attempts WHERE success=0 AND created_at > (NOW() - INTERVAL 24 HOUR)")->fetchColumn(),
];

// Top suspicious IPs (last 7 days)
$topIps = $pdo->query("SELECT ip, COUNT(*) AS cnt, MAX(created_at) AS last_seen FROM security_logs WHERE severity IN ('warning', 'critical') AND created_at > (NOW() - INTERVAL 7 DAY) GROUP BY ip ORDER BY cnt DESC LIMIT 10")->fetchAll();

// Distinct event types for filter
$eventTypes = $pdo->query("SELECT DISTINCT event_type FROM security_logs ORDER BY event_type")->fetchAll(PDO::FETCH_COLUMN);

require __DIR__ . '/../includes/header_super.php';
?>

<!-- Stats -->
<div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
    <?php
    $statCards = [
        ['إجمالي الأحداث', $counts['total'], 'from-blue-500 to-blue-600'],
        ['تحذيرات', $counts['warning'], 'from-amber-500 to-orange-500'],
        ['حرجة', $counts['critical'], 'from-red-500 to-red-600'],
        ['محاولات دخول فاشلة (24h)', $counts['failed_logins'], 'from-purple-500 to-pink-500'],
    ];
    foreach ($statCards as [$label, $value, $grad]):
    ?>
    <div class="card rounded-2xl p-5">
        <div class="w-10 h-10 rounded-xl bg-gradient-to-br <?= $grad ?> flex items-center justify-center mb-3 shadow-lg">
            <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
        </div>
        <p class="text-3xl font-black text-white"><?= (int) $value ?></p>
        <p class="text-sm text-gray-400 mt-1"><?= e($label) ?></p>
    </div>
    <?php endforeach; ?>
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
    <!-- Top Suspicious IPs -->
    <div class="card rounded-2xl p-6 lg:col-span-1">
        <h3 class="text-lg font-bold text-white mb-4">أعلى IPs مشبوهة (7 أيام)</h3>
        <?php if (!$topIps): ?>
            <p class="text-gray-500 text-sm">لا توجد نشاطات مشبوهة</p>
        <?php else: ?>
            <div class="space-y-2">
                <?php foreach ($topIps as $ip): ?>
                <div class="flex items-center justify-between p-3 rounded-xl bg-white/5">
                    <div>
                        <p class="font-mono text-sm text-white"><?= e($ip['ip']) ?></p>
                        <p class="text-xs text-gray-500"><?= e(date('m/d H:i', strtotime($ip['last_seen']))) ?></p>
                    </div>
                    <span class="px-2 py-1 rounded-lg bg-red-500/20 text-red-300 text-xs font-bold"><?= $ip['cnt'] ?> حدث</span>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Filters -->
    <div class="card rounded-2xl p-6 lg:col-span-2">
        <h3 class="text-lg font-bold text-white mb-4">تصفية السجلات</h3>
        <form method="GET" class="space-y-3">
            <div class="grid grid-cols-2 md:grid-cols-3 gap-3">
                <div>
                    <label class="block text-xs font-semibold text-gray-400 mb-1">الخطورة</label>
                    <select name="severity" class="w-full px-3 py-2 rounded-xl border-2 text-sm">
                        <option value="">الكل</option>
                        <option value="info" <?= $severityFilter === 'info' ? 'selected' : '' ?>>معلومة</option>
                        <option value="warning" <?= $severityFilter === 'warning' ? 'selected' : '' ?>>تحذير</option>
                        <option value="critical" <?= $severityFilter === 'critical' ? 'selected' : '' ?>>حرج</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-400 mb-1">نوع الحدث</label>
                    <select name="event" class="w-full px-3 py-2 rounded-xl border-2 text-sm">
                        <option value="">الكل</option>
                        <?php foreach ($eventTypes as $et): ?>
                            <option value="<?= e($et) ?>" <?= $eventFilter === $et ? 'selected' : '' ?>><?= e($et) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-400 mb-1">عدد السجلات</label>
                    <select name="limit" class="w-full px-3 py-2 rounded-xl border-2 text-sm">
                        <?php foreach ([50, 100, 200, 500] as $l): ?>
                            <option value="<?= $l ?>" <?= $limit === $l ? 'selected' : '' ?>><?= $l ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="flex justify-between items-center">
                <button type="submit" class="px-5 py-2 rounded-xl bg-gradient-to-r from-emerald-500 to-teal-500 text-white font-bold text-sm">تطبيق</button>
                <button type="button" onclick="document.getElementById('purgeModal').classList.remove('hidden');document.getElementById('purgeModal').classList.add('flex')" class="px-5 py-2 rounded-xl bg-red-500/20 text-red-300 hover:bg-red-500/30 font-bold text-sm">🗑 تنظيف السجلات القديمة</button>
            </div>
        </form>
    </div>
</div>

<!-- Logs Table -->
<div class="card rounded-2xl overflow-hidden">
    <div class="p-4 border-b border-white/5">
        <h3 class="font-bold text-white">سجل الأحداث <span class="text-sm text-gray-500 font-normal">(<?= count($logs) ?> من آخر <?= $limit ?>)</span></h3>
    </div>
    <form method="POST" id="secBulkForm">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="bulk_delete">
        <div class="table-scroll">
        <table class="w-full min-w-[860px]">
            <thead>
                <tr class="text-xs text-gray-400">
                    <th class="text-center py-3 px-3 w-10"><input type="checkbox" onchange="document.querySelectorAll('.sec-check').forEach(c=>c.checked=this.checked)"></th>
                    <th class="text-right py-3 px-4 font-semibold">الوقت</th>
                    <th class="text-right py-3 px-4 font-semibold">الخطورة</th>
                    <th class="text-right py-3 px-4 font-semibold">الحدث</th>
                    <th class="text-right py-3 px-4 font-semibold">المستخدم</th>
                    <th class="text-right py-3 px-4 font-semibold">IP</th>
                    <th class="text-right py-3 px-4 font-semibold">التفاصيل</th>
                    <th class="text-center py-3 px-3 font-semibold w-16">إجراء</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$logs): ?>
                    <tr><td colspan="8" class="text-center py-12 text-gray-500">لا توجد سجلات</td></tr>
                <?php else: foreach ($logs as $log):
                    $sevClass = [
                        'info' => 'bg-blue-500/20 text-blue-300',
                        'warning' => 'bg-amber-500/20 text-amber-300',
                        'critical' => 'bg-red-500/20 text-red-300',
                    ];
                ?>
                <tr class="border-b border-white/5 hover:bg-white/5">
                    <td class="text-center py-3 px-3">
                        <input type="checkbox" name="ids[]" value="<?= (int) $log['id'] ?>" class="sec-check">
                    </td>
                    <td class="py-3 px-4 text-xs text-gray-400 whitespace-nowrap"><?= e(date('m/d H:i:s', strtotime($log['created_at']))) ?></td>
                    <td class="py-3 px-4">
                        <span class="px-2 py-1 rounded-lg text-xs font-bold <?= $sevClass[$log['severity']] ?? 'bg-gray-500/20 text-gray-300' ?>"><?= e($log['severity']) ?></span>
                    </td>
                    <td class="py-3 px-4 font-mono text-xs text-gray-200"><?= e($log['event_type']) ?></td>
                    <td class="py-3 px-4 text-xs text-gray-400">
                        <?= $log['user_type'] ? e($log['user_type']) . ($log['user_id'] ? ' #' . (int) $log['user_id'] : '') : '—' ?>
                    </td>
                    <td class="py-3 px-4 font-mono text-xs text-gray-300"><?= e($log['ip']) ?></td>
                    <td class="py-3 px-4 text-xs text-gray-400 max-w-md truncate" title="<?= e($log['details']) ?>"><?= e($log['details']) ?: '—' ?></td>
                    <td class="py-3 px-3 text-center">
                        <button type="button" onclick="secDeleteOne(<?= (int) $log['id'] ?>)" class="p-1.5 rounded-lg bg-red-500/10 hover:bg-red-500/20 text-red-300" title="حذف">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6M1 7h22M9 7V4a1 1 0 011-1h4a1 1 0 011 1v3"/></svg>
                        </button>
                    </td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
        </div>
        <?php if ($logs): ?>
        <div class="p-4 border-t border-white/5 flex items-center justify-between flex-wrap gap-2">
            <p class="text-sm text-gray-400">حدد أكثر من سجل للحذف الجماعي</p>
            <button type="button" onclick="secBulkDelete()" class="px-4 py-2 rounded-xl bg-red-500/20 text-red-300 hover:bg-red-500/30 font-bold text-sm">🗑 حذف المحدّدين</button>
        </div>
        <?php endif; ?>
    </form>
</div>

<!-- Hidden single-delete form -->
<form method="POST" id="secSingleDelete" style="display:none">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="id" id="secSingleId">
</form>

<!-- Purge Modal (older + all) -->
<div id="purgeModal" class="fixed inset-0 bg-black/70 z-50 hidden items-center justify-center p-4" onclick="if(event.target===this) this.classList.add('hidden')">
    <div class="card rounded-2xl w-full max-w-lg">
        <div class="p-6 border-b border-white/5 flex items-center justify-between">
            <h3 class="text-lg font-bold text-white">🗑 تنظيف السجلات</h3>
            <button type="button" onclick="document.getElementById('purgeModal').classList.add('hidden')" class="text-gray-400 hover:text-white">&times;</button>
        </div>
        <div class="p-6 space-y-3">
            <!-- Older than X days -->
            <form method="POST" class="p-4 rounded-xl bg-blue-500/10 border border-blue-500/20" onsubmit="return confirm('حذف السجلات الأقدم من المدة المحددة؟')">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="purge">
                <p class="font-bold text-blue-300 mb-2 text-right">حذف الأقدم من مدة محددة</p>
                <div class="flex gap-2">
                    <select name="days" class="flex-1 px-3 py-2 rounded-xl border-2 text-sm">
                        <option value="7">7 أيام</option>
                        <option value="30">30 يوم</option>
                        <option value="60">60 يوم</option>
                        <option value="90" selected>90 يوم</option>
                        <option value="180">180 يوم</option>
                        <option value="365">سنة</option>
                    </select>
                    <button type="submit" class="px-4 py-2 rounded-xl bg-blue-500 hover:bg-blue-600 text-white font-bold text-sm">حذف</button>
                </div>
            </form>

            <!-- Purge all -->
            <form method="POST" class="p-4 rounded-xl bg-red-500/10 border border-red-500/30">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="purge_all">
                <p class="font-bold text-red-300 mb-2 text-right">⚠️ حذف كل السجلات (أمان + محاولات الدخول)</p>
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
function secDeleteOne(id) {
    if (!confirm('حذف هذا السجل نهائياً؟')) return;
    document.getElementById('secSingleId').value = id;
    document.getElementById('secSingleDelete').submit();
}
function secBulkDelete() {
    const checked = document.querySelectorAll('.sec-check:checked');
    if (!checked.length) return alert('لم تختر أي سجل');
    if (!confirm('حذف ' + checked.length + ' سجل نهائياً؟')) return;
    document.getElementById('secBulkForm').submit();
}
</script>

<?php require __DIR__ . '/../includes/footer_super.php'; ?>
