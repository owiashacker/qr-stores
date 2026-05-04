<?php
require_once __DIR__ . '/../includes/functions.php';
$pageTitle = 'لوحة الإحصائيات';

require_once __DIR__ . '/../includes/header_affiliate.php';

$affId = (int) $aff['id'];

// Stats
$totalStores = (int) $pdo->query("SELECT COUNT(*) FROM stores WHERE affiliate_id = $affId")->fetchColumn();
$activeStores = (int) $pdo->query("SELECT COUNT(*) FROM stores WHERE affiliate_id = $affId AND is_active = 1")->fetchColumn();
$totalEarnings = (float) $pdo->query("SELECT COALESCE(SUM(affiliate_amount), 0) FROM payments WHERE affiliate_id = $affId")->fetchColumn();
$paidEarnings = (float) $pdo->query("SELECT COALESCE(SUM(affiliate_amount), 0) FROM payments WHERE affiliate_id = $affId AND affiliate_paid = 1")->fetchColumn();
$pendingEarnings = $totalEarnings - $paidEarnings;

// Recent referrals (last 5)
$recentStmt = $pdo->prepare("
    SELECT s.id, s.name, s.slug, s.is_active, s.referred_at, p.code AS plan_code, p.name AS plan_name
    FROM stores s
    LEFT JOIN plans p ON p.id = s.plan_id
    WHERE s.affiliate_id = ?
    ORDER BY s.referred_at DESC, s.id DESC
    LIMIT 5
");
$recentStmt->execute([$affId]);
$recentStores = $recentStmt->fetchAll();

$referralUrl = affiliateReferralUrl($aff['referral_code']);
?>

<div class="max-w-6xl mx-auto space-y-6">

    <!-- Greeting -->
    <div class="bg-gradient-to-l from-orange-500 to-amber-600 rounded-2xl p-6 text-white shadow-lg shadow-orange-500/20">
        <h1 class="text-2xl md:text-3xl font-black mb-2">أهلاً، <?= e($aff['name']) ?> 👋</h1>
        <p class="text-orange-50">إليك أحدث إحصائياتك ورابط الإحالة الخاص بك.</p>
    </div>

    <!-- Referral link card (the most important thing) -->
    <div class="bg-white rounded-2xl shadow-card p-6 border-r-4 border-orange-500">
        <div class="flex items-start gap-3 mb-4">
            <div class="w-10 h-10 rounded-xl bg-orange-100 flex items-center justify-center flex-shrink-0">
                <svg class="w-5 h-5 text-orange-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"/>
                </svg>
            </div>
            <div class="flex-1">
                <h2 class="text-lg font-black text-gray-900">رابط الإحالة الخاص بك</h2>
                <p class="text-sm text-gray-500">شارك هذا الرابط — كل من يسجّل عبره يُحتسب كإحالتك</p>
            </div>
        </div>

        <div class="flex flex-col sm:flex-row gap-2">
            <input id="referralUrl" type="text" readonly value="<?= e($referralUrl) ?>"
                   onclick="this.select()" dir="ltr"
                   class="flex-1 px-4 py-3 rounded-xl bg-gray-50 border-2 border-gray-100 font-mono text-sm text-gray-700">
            <button onclick="copyReferralUrl()" id="copyBtn"
                    class="px-6 py-3 rounded-xl bg-gradient-to-r from-orange-500 to-amber-600 text-white font-bold shadow hover:shadow-lg active:scale-95 transition">
                نسخ الرابط
            </button>
        </div>

        <div class="mt-4 flex flex-wrap gap-2">
            <a href="https://wa.me/?text=<?= urlencode('سجّل في QR Stores من خلال هذا الرابط: ' . $referralUrl) ?>"
               target="_blank"
               class="inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-green-50 hover:bg-green-100 text-green-700 font-bold text-sm transition">
                <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51a12.8 12.8 0 00-.57-.01c-.198 0-.52.074-.792.372s-1.04 1.016-1.04 2.479 1.065 2.876 1.213 3.074c.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413Z"/></svg>
                مشاركة عبر واتساب
            </a>
            <span class="inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-orange-50 text-orange-700 font-bold text-sm">
                كودك: <span class="font-mono"><?= e($aff['referral_code']) ?></span>
            </span>
            <span class="inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-blue-50 text-blue-700 font-bold text-sm">
                نسبتك الافتراضية: <?= number_format((float) $aff['commission_rate'], 2) ?>%
            </span>
        </div>
    </div>

    <!-- Stats grid -->
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">

        <div class="bg-white rounded-2xl shadow-card p-5">
            <div class="w-10 h-10 rounded-xl bg-emerald-100 flex items-center justify-center mb-3">
                <svg class="w-5 h-5 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"/>
                </svg>
            </div>
            <p class="text-3xl font-black text-gray-900"><?= number_format($totalStores) ?></p>
            <p class="text-sm text-gray-500 mt-1">إجمالي المتاجر المُحالة</p>
        </div>

        <div class="bg-white rounded-2xl shadow-card p-5">
            <div class="w-10 h-10 rounded-xl bg-blue-100 flex items-center justify-center mb-3">
                <svg class="w-5 h-5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
                </svg>
            </div>
            <p class="text-3xl font-black text-gray-900"><?= number_format($activeStores) ?></p>
            <p class="text-sm text-gray-500 mt-1">متاجر نشطة حالياً</p>
        </div>

        <div class="bg-white rounded-2xl shadow-card p-5">
            <div class="w-10 h-10 rounded-xl bg-orange-100 flex items-center justify-center mb-3">
                <svg class="w-5 h-5 text-orange-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
            </div>
            <p class="text-3xl font-black text-gray-900"><?= number_format($totalEarnings) ?></p>
            <p class="text-sm text-gray-500 mt-1">إجمالي الأرباح (ل.س)</p>
        </div>

        <div class="bg-white rounded-2xl shadow-card p-5">
            <div class="w-10 h-10 rounded-xl bg-amber-100 flex items-center justify-center mb-3">
                <svg class="w-5 h-5 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
            </div>
            <p class="text-3xl font-black text-gray-900"><?= number_format($pendingEarnings) ?></p>
            <p class="text-sm text-gray-500 mt-1">معلّق (لم يُدفع بعد)</p>
        </div>

    </div>

    <!-- Recent referrals -->
    <div class="bg-white rounded-2xl shadow-card p-6">
        <div class="flex items-center justify-between mb-5">
            <h2 class="text-lg font-black text-gray-900">آخر المتاجر المُحالة</h2>
            <a href="<?= BASE_URL ?>/affiliate/stores.php" class="text-sm text-orange-600 font-bold hover:underline">عرض الكل ←</a>
        </div>

        <?php if (!$recentStores): ?>
            <div class="text-center py-8 text-gray-400">
                <p class="text-4xl mb-2">📭</p>
                <p>لا توجد إحالات بعد. ابدأ بمشاركة رابطك أعلاه!</p>
            </div>
        <?php else: ?>
            <div class="space-y-2">
                <?php foreach ($recentStores as $s): ?>
                    <div class="flex items-center justify-between p-3 rounded-xl hover:bg-gray-50 transition">
                        <div class="flex items-center gap-3 min-w-0">
                            <div class="w-10 h-10 rounded-lg bg-gradient-to-br from-orange-100 to-amber-100 flex items-center justify-center text-orange-600 font-bold text-lg flex-shrink-0">
                                <?= e(mb_substr($s['name'], 0, 1)) ?>
                            </div>
                            <div class="min-w-0">
                                <p class="font-bold text-gray-900 truncate"><?= e($s['name']) ?></p>
                                <p class="text-xs text-gray-500">
                                    <?= e($s['plan_name'] ?? '—') ?>
                                    · <?= $s['referred_at'] ? date('Y-m-d', strtotime($s['referred_at'])) : '—' ?>
                                </p>
                            </div>
                        </div>
                        <span class="px-3 py-1 rounded-full text-xs font-bold flex-shrink-0
                                <?= $s['is_active'] ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500' ?>">
                            <?= $s['is_active'] ? 'نشط' : 'غير نشط' ?>
                        </span>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

</div>

<script>
function copyReferralUrl() {
    const input = document.getElementById('referralUrl');
    const btn = document.getElementById('copyBtn');
    input.select();
    document.execCommand('copy');
    if (navigator.clipboard) navigator.clipboard.writeText(input.value);
    const original = btn.textContent;
    btn.textContent = '✓ تم النسخ!';
    btn.classList.add('bg-green-600');
    setTimeout(() => {
        btn.textContent = original;
        btn.classList.remove('bg-green-600');
    }, 1500);
}
</script>

<?php require_once __DIR__ . '/../includes/footer_affiliate.php'; ?>
