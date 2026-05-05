<?php
require_once __DIR__ . '/../includes/functions.php';
requireLogin();
$pageTitle = 'QR Code';

$rid = $_SESSION['store_id'];
$r = currentStore($pdo);
requireActivePlan($r);

// 5 modern QR styles — each maps to qr-code-styling library config
$QR_STYLES = [
    'circles' => [
        'name' => 'الدوائر',
        'icon' => '⚫',
        'desc' => 'نقاط دائرية مع عيون دائرية — تصميم ناعم وعصري',
        'config' => ['dots' => 'dots', 'square' => 'dot', 'dot' => 'dot'],
    ],
    'rounded' => [
        'name' => 'المدور',
        'icon' => '🟢',
        'desc' => 'مربعات بحواف مدورة — أنيق ومتوازن',
        'config' => ['dots' => 'rounded', 'square' => 'extra-rounded', 'dot' => 'square'],
    ],
    'leaf' => [
        'name' => 'الورقي',
        'icon' => '🍃',
        'desc' => 'شكل ورقة ناعم — تصميم فريد ومميّز',
        'config' => ['dots' => 'classy-rounded', 'square' => 'extra-rounded', 'dot' => 'dot'],
    ],
    'classy' => [
        'name' => 'الكلاسي',
        'icon' => '💎',
        'desc' => 'أشكال مزخرفة — للمطاعم الراقية',
        'config' => ['dots' => 'classy', 'square' => 'dot', 'dot' => 'dot'],
    ],
    'sharp' => [
        'name' => 'الحاد',
        'icon' => '◆',
        'desc' => 'أشكال هندسية — قوي وواضح',
        'config' => ['dots' => 'extra-rounded', 'square' => 'square', 'dot' => 'square'],
    ],
];
$styleKeys = array_keys($QR_STYLES);

// Handle save
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrfCheck()) {
    $chosen = clean_string($_POST['qr_style'] ?? 'circles', 30);
    $qrColor = clean_hex_color($_POST['qr_color'] ?? '#111827');
    $qrBg = clean_hex_color($_POST['qr_bg_color'] ?? '#FFFFFF');
    $transparent = !empty($_POST['qr_transparent']) ? 1 : 0;

    $maxAllowed = (int) ($r['max_qr_styles'] ?? 1);
    $idx = array_search($chosen, $styleKeys, true);

    if ($idx === false || $idx >= $maxAllowed) {
        flash('error', 'هذا التصميم غير متاح في باقتك الحالية');
    } else {
        $pdo->prepare('UPDATE stores SET qr_style=?, qr_color=?, qr_bg_color=?, qr_transparent=? WHERE id=?')
            ->execute([$chosen, $qrColor, $qrBg, $transparent, $rid]);
        flash('success', 'تم حفظ تصميم الـ QR');
        security_log($pdo, 'qr_style_changed', 'info', ['style' => $chosen], 'store', $rid);
    }
    redirect(BASE_URL . '/admin/qr.php');
}

$currentStyle = $r['qr_style'] ?? 'circles';
$currentIdx = array_search($currentStyle, $styleKeys, true);
$maxStyles = max(1, (int) ($r['max_qr_styles'] ?? 1));
if ($currentIdx === false || $currentIdx >= $maxStyles) $currentStyle = 'circles';

$qrColor = $r['qr_color'] ?? '#111827';
$qrBg = $r['qr_bg_color'] ?? '#FFFFFF';
$transparent = (int) ($r['qr_transparent'] ?? 0);

$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'];
$menuUrl = $protocol . '://' . $host . BASE_URL . '/public/store.php?r=' . urlencode($r['slug']);

require __DIR__ . '/../includes/header_admin.php';
?>

<style>
.style-option input:checked + .style-card { border-color: #059669; box-shadow: 0 0 0 4px rgba(5,150,105,0.15); transform: translateY(-2px); }
.style-card { transition: all .2s; cursor: pointer; }
.style-card:hover { transform: translateY(-2px); box-shadow: 0 8px 24px rgba(0,0,0,0.08); }
.style-option.locked { cursor: not-allowed; }
.style-option.locked .style-card { opacity: 0.55; filter: grayscale(0.6); }
/* Override Tailwind Preflight (img, svg { max-width: 100%; height: auto }) that shrinks QR */
.qr-preview-mini, #mainPreview { flex-shrink: 0; display: flex; align-items: center; justify-content: center; }
.qr-preview-mini { width: 120px; height: 120px; }
.qr-preview-mini *, #mainPreview * { max-width: none !important; max-height: none !important; }
.qr-preview-mini svg, .qr-preview-mini canvas { width: 120px !important; height: 120px !important; display: block !important; }

#mainPreview { width: 300px; height: 300px; }
#mainPreview svg, #mainPreview canvas { width: 300px !important; height: 300px !important; display: block !important; }

/* Checkered pattern for transparent preview */
.transparent-bg {
    background-image:
        linear-gradient(45deg, #e5e7eb 25%, transparent 25%),
        linear-gradient(-45deg, #e5e7eb 25%, transparent 25%),
        linear-gradient(45deg, transparent 75%, #e5e7eb 75%),
        linear-gradient(-45deg, transparent 75%, #e5e7eb 75%);
    background-size: 20px 20px;
    background-position: 0 0, 0 10px, 10px -10px, 10px 0px;
    background-color: #f9fafb;
}

@media print {
    body * { visibility: hidden; }
    #printArea, #printArea * { visibility: visible; }
    #printArea { position: absolute; left: 0; top: 0; width: 100%; padding: 40px; }
}
</style>

<div>
    <!-- Intro -->
    <div class="bg-white rounded-2xl shadow-soft p-6 md:p-8 mb-6">
        <div class="flex flex-col md:flex-row items-start md:items-center gap-4">
            <div class="w-14 h-14 rounded-2xl bg-gradient-to-br from-emerald-500 to-teal-600 flex items-center justify-center shadow-lg shadow-emerald-500/30 flex-shrink-0">
                <svg class="w-7 h-7 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4h6v6H4V4zm10 0h6v6h-6V4zM4 14h6v6H4v-6zm10 0h2v2h-2v-2zm4 0h2v6h-2v-6z"/></svg>
            </div>
            <div class="flex-1">
                <h2 class="text-xl md:text-2xl font-black text-gray-900">خصّص تصميم QR Code</h2>
                <p class="text-sm text-gray-500 mt-1">
                    اختر الشكل — لوّن بالألوان التي تحبها — نزّله بخلفية أو شفاف
                    <span class="text-emerald-600 font-bold">(<?= $maxStyles ?> من 5 تصاميم في باقتك)</span>
                </p>
            </div>
            <?php if ($maxStyles < 5): ?>
            <a href="upgrade.php" class="inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-gradient-to-r from-amber-500 to-orange-500 text-white font-bold text-sm shadow-lg">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                ترقية
            </a>
            <?php endif; ?>
        </div>
    </div>

    <form method="POST" id="qrForm">
        <?= csrfField() ?>

        <!-- Style Selector -->
        <div class="bg-white rounded-2xl shadow-soft p-6 md:p-8 mb-6">
            <h3 class="text-lg font-bold mb-5">1. اختر الشكل</h3>
            <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-4">
                <?php foreach ($styleKeys as $idx => $key):
                    $locked = $idx >= $maxStyles;
                    $style = $QR_STYLES[$key];
                ?>
                <label class="style-option <?= $locked ? 'locked' : '' ?>">
                    <input type="radio" name="qr_style" value="<?= $key ?>" <?= $currentStyle === $key ? 'checked' : '' ?> <?= $locked ? 'disabled' : '' ?> class="hidden">
                    <div class="style-card bg-white border-2 border-gray-100 rounded-2xl p-4 text-center">
                        <div class="qr-preview-mini mx-auto mb-3 relative">
                            <div id="mini-<?= $key ?>"></div>
                            <?php if ($locked): ?>
                                <div class="absolute inset-0 flex items-center justify-center">
                                    <div class="bg-gray-900/90 backdrop-blur px-2 py-1 rounded-lg">
                                        <svg class="w-4 h-4 text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                        <p class="font-bold text-sm"><?= $style['icon'] ?> <?= $style['name'] ?></p>
                        <p class="text-xs text-gray-500 mt-1 line-clamp-2"><?= $style['desc'] ?></p>
                    </div>
                </label>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-5 gap-6 mb-6">
            <!-- Color Controls -->
            <div class="bg-white rounded-2xl shadow-soft p-6 lg:col-span-2">
                <h3 class="text-lg font-bold mb-5">2. خصّص الألوان</h3>
                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">لون الـ QR</label>
                        <div class="flex gap-2 items-stretch">
                            <input type="color" id="qrColor" name="qr_color" value="<?= e($qrColor) ?>" class="w-14 h-12 rounded-xl border-2 border-gray-100 cursor-pointer">
                            <input type="text" id="qrColorText" value="<?= e($qrColor) ?>" maxlength="7" pattern="#[0-9A-Fa-f]{6}" class="flex-1 px-4 py-3 rounded-xl border-2 border-gray-100 focus:border-emerald-500 transition font-mono text-sm uppercase" dir="ltr">
                        </div>
                        <div class="flex gap-2 mt-2 flex-wrap">
                            <?php foreach (['#111827', '#059669', '#dc2626', '#2563eb', '#7c3aed', '#d97706', '#0891b2'] as $preset): ?>
                                <button type="button" onclick="setColor('qr', '<?= $preset ?>')" class="w-7 h-7 rounded-full border-2 border-white shadow hover:scale-110 transition" style="background:<?= $preset ?>" title="<?= $preset ?>"></button>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div>
                        <label class="flex items-center justify-between mb-2">
                            <span class="text-sm font-semibold text-gray-700">لون الخلفية</span>
                            <label class="inline-flex items-center gap-2 cursor-pointer">
                                <input type="checkbox" id="transparentCheck" name="qr_transparent" value="1" <?= $transparent ? 'checked' : '' ?> class="w-4 h-4 rounded text-emerald-600 focus:ring-emerald-500">
                                <span class="text-xs font-semibold text-gray-600">خلفية شفافة</span>
                            </label>
                        </label>
                        <div class="flex gap-2 items-stretch" id="bgControls">
                            <input type="color" id="qrBg" name="qr_bg_color" value="<?= e($qrBg) ?>" class="w-14 h-12 rounded-xl border-2 border-gray-100 cursor-pointer">
                            <input type="text" id="qrBgText" value="<?= e($qrBg) ?>" maxlength="7" pattern="#[0-9A-Fa-f]{6}" class="flex-1 px-4 py-3 rounded-xl border-2 border-gray-100 focus:border-emerald-500 transition font-mono text-sm uppercase" dir="ltr">
                        </div>
                        <div class="flex gap-2 mt-2 flex-wrap" id="bgPresets">
                            <?php foreach (['#FFFFFF', '#F9FAFB', '#FEF3C7', '#DBEAFE', '#F3E8FF', '#111827'] as $preset): ?>
                                <button type="button" onclick="setColor('bg', '<?= $preset ?>')" class="w-7 h-7 rounded-full border-2 border-white shadow hover:scale-110 transition" style="background:<?= $preset ?>" title="<?= $preset ?>"></button>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <button type="submit" class="w-full py-3 rounded-xl bg-gradient-to-r from-emerald-600 to-teal-600 text-white font-bold shadow-lg shadow-emerald-500/30 hover:shadow-xl transition mt-4">
                        حفظ التصميم
                    </button>
                </div>
            </div>

            <!-- Live Preview -->
            <div class="bg-gradient-to-br from-gray-100 via-gray-50 to-emerald-50/30 rounded-2xl p-4 md:p-8 lg:col-span-3 flex flex-col items-center justify-center">
                <h3 class="text-sm font-bold text-gray-500 mb-4 uppercase tracking-wider">معاينة مباشرة</h3>

                <!-- Printable card -->
                <div id="previewCard" class="rounded-3xl shadow-2xl w-full max-w-[360px] flex flex-col items-center gap-4 p-6 relative overflow-hidden transition-colors">
                    <!-- Top: restaurant name / logo -->
                    <div class="flex flex-col items-center gap-2 w-full">
                        <?php if ($r['logo']): ?>
                            <img src="<?= BASE_URL ?>/assets/uploads/logos/<?= e($r['logo']) ?>" class="w-12 h-12 rounded-xl object-cover shadow-md">
                        <?php endif; ?>
                        <h2 id="previewName" class="font-black text-lg md:text-xl text-center leading-tight break-words w-full px-2"><?= e($r['name']) ?></h2>
                    </div>

                    <!-- Middle: QR -->
                    <div id="mainPreview" class="flex items-center justify-center"></div>

                    <!-- Bottom: scan label -->
                    <div class="flex flex-col items-center gap-1 w-full">
                        <div id="scanLabel" class="flex items-center gap-2 font-bold text-sm opacity-90">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v1m6.364 1.636l-.707.707M20 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"/></svg>
                            <span>امسح للقائمة</span>
                        </div>
                        <div id="dividerLine" class="w-8 h-0.5 rounded-full mt-1 opacity-40"></div>
                    </div>
                </div>

                <p class="text-xs text-gray-400 mt-4 break-all font-mono text-center max-w-xs"><?= e($menuUrl) ?></p>
            </div>
        </div>
    </form>

    <!-- Download Actions -->
    <div class="bg-white rounded-2xl shadow-soft p-6 md:p-8">
        <h3 class="text-lg font-bold mb-4">3. نزّل أو اطبع</h3>
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
            <button type="button" onclick="downloadPNG()" class="flex items-center justify-center gap-2 py-3 rounded-xl bg-gradient-to-r from-emerald-600 to-teal-600 text-white font-bold shadow-lg shadow-emerald-500/30 hover:shadow-xl transition">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                تحميل PNG (HD)
            </button>
            <button type="button" onclick="downloadSVG()" class="flex items-center justify-center gap-2 py-3 rounded-xl bg-gray-900 text-white font-bold hover:bg-gray-800 transition">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                تحميل SVG (قابل للتكبير)
            </button>
            <button type="button" onclick="copyLink()" class="flex items-center justify-center gap-2 py-3 rounded-xl bg-gray-100 hover:bg-gray-200 text-gray-900 font-bold transition">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"/></svg>
                نسخ رابط القائمة
            </button>
        </div>

        <div class="mt-6 pt-6 border-t border-gray-100 grid grid-cols-1 md:grid-cols-3 gap-4 text-sm text-gray-600">
            <div class="flex gap-2"><span class="text-emerald-600 font-bold">✓</span>PNG بجودة 1000×1000 للطباعة الاحترافية</div>
            <div class="flex gap-2"><span class="text-emerald-600 font-bold">✓</span>SVG قابل للتكبير لأي حجم دون فقدان الجودة</div>
            <div class="flex gap-2"><span class="text-emerald-600 font-bold">✓</span>الخلفية الشفافة تعمل على أي تصميم</div>
        </div>
    </div>
</div>

<script src="<?= BASE_URL ?>/assets/lib/qr-code-styling.js"></script>
<script>
// Style configs
const STYLES = <?= json_encode(array_map(fn($s) => $s['config'], $QR_STYLES), JSON_UNESCAPED_UNICODE) ?>;
const MENU_URL = <?= json_encode($menuUrl) ?>;
const SLUG = <?= json_encode($r['slug']) ?>;

// Build options for a given style/color/bg/transparent
function buildOptions(styleKey, color, bg, transparent, size, type) {
    const s = STYLES[styleKey];
    return {
        width: size,
        height: size,
        type: type || 'canvas',
        data: MENU_URL,
        margin: 10,
        qrOptions: { errorCorrectionLevel: 'H' },
        dotsOptions: { color: color, type: s.dots },
        backgroundOptions: { color: transparent ? 'transparent' : bg },
        cornersSquareOptions: { color: color, type: s.square },
        cornersDotOptions: { color: color, type: s.dot },
    };
}

// Render all mini previews (always black on white for clarity)
Object.keys(STYLES).forEach(key => {
    const qr = new QRCodeStyling(buildOptions(key, '#111827', '#FFFFFF', false, 120));
    const mount = document.getElementById('mini-' + key);
    if (!mount) return;
    qr.append(mount);
    const forceMini = () => {
        const el = mount.querySelector('svg, canvas');
        if (!el) return;
        if (el.tagName.toLowerCase() === 'svg') {
            el.setAttribute('width', '120');
            el.setAttribute('height', '120');
        }
        el.style.cssText = 'width:120px !important;height:120px !important;max-width:none !important;max-height:none !important;display:block;';
    };
    forceMini();
    requestAnimationFrame(forceMini);
    setTimeout(forceMini, 100);
});

// Determine best text color for given background (white on dark, dark on light)
function isLightColor(hex) {
    const h = hex.replace('#', '');
    const r = parseInt(h.substr(0, 2), 16);
    const g = parseInt(h.substr(2, 2), 16);
    const b = parseInt(h.substr(4, 2), 16);
    // Perceived luminance (ITU-R BT.709)
    return (0.2126 * r + 0.7152 * g + 0.0722 * b) > 150;
}

// Main live preview
let mainQR;
function renderMain() {
    const style = document.querySelector('input[name="qr_style"]:checked')?.value || 'circles';
    const color = document.getElementById('qrColor').value;
    const bg = document.getElementById('qrBg').value;
    const transparent = document.getElementById('transparentCheck').checked;

    const opts = buildOptions(style, color, bg, transparent, 300);
    const mount = document.getElementById('mainPreview');
    mount.innerHTML = '';
    mainQR = new QRCodeStyling(opts);
    mainQR.append(mount);
    // Force CSS display size only (NEVER set canvas width/height attrs — clears content!)
    const forceSize = () => {
        const el = mount.querySelector('svg, canvas');
        if (!el) return;
        if (el.tagName.toLowerCase() === 'svg') {
            el.setAttribute('width', '300');
            el.setAttribute('height', '300');
        }
        el.style.cssText = 'width:300px !important;height:300px !important;max-width:none !important;max-height:none !important;display:block;';
    };
    forceSize();
    requestAnimationFrame(forceSize);
    setTimeout(forceSize, 100);

    // Update card background + text colors for readability
    const card = document.getElementById('previewCard');
    const name = document.getElementById('previewName');
    const scan = document.getElementById('scanLabel');
    const divider = document.getElementById('dividerLine');

    card.classList.toggle('transparent-bg', transparent);
    if (transparent) {
        card.style.background = '';
        name.style.color = '#0f172a';
        scan.style.color = '#374151';
        divider.style.background = '#94a3b8';
    } else {
        card.style.background = bg;
        // Text contrasts with background
        const lightBg = isLightColor(bg);
        name.style.color = lightBg ? '#0f172a' : '#ffffff';
        scan.style.color = lightBg ? color : '#ffffff';
        divider.style.background = lightBg ? color : '#ffffff';
    }
}
renderMain();

// Handle interactions
document.querySelectorAll('input[name="qr_style"]').forEach(el => el.addEventListener('change', renderMain));
document.getElementById('qrColor').addEventListener('input', e => {
    document.getElementById('qrColorText').value = e.target.value.toUpperCase();
    renderMain();
});
document.getElementById('qrColorText').addEventListener('input', e => {
    if (/^#[0-9A-Fa-f]{6}$/.test(e.target.value)) {
        document.getElementById('qrColor').value = e.target.value;
        renderMain();
    }
});
document.getElementById('qrBg').addEventListener('input', e => {
    document.getElementById('qrBgText').value = e.target.value.toUpperCase();
    renderMain();
});
document.getElementById('qrBgText').addEventListener('input', e => {
    if (/^#[0-9A-Fa-f]{6}$/.test(e.target.value)) {
        document.getElementById('qrBg').value = e.target.value;
        renderMain();
    }
});
document.getElementById('transparentCheck').addEventListener('change', () => {
    document.getElementById('bgControls').style.opacity = document.getElementById('transparentCheck').checked ? '0.5' : '1';
    renderMain();
});
if (document.getElementById('transparentCheck').checked) document.getElementById('bgControls').style.opacity = '0.5';

function setColor(which, value) {
    if (which === 'qr') {
        document.getElementById('qrColor').value = value;
        document.getElementById('qrColorText').value = value.toUpperCase();
    } else {
        document.getElementById('qrBg').value = value;
        document.getElementById('qrBgText').value = value.toUpperCase();
    }
    renderMain();
}

// Downloads — build HD versions
function downloadPNG() {
    const style = document.querySelector('input[name="qr_style"]:checked')?.value || 'circles';
    const color = document.getElementById('qrColor').value;
    const bg = document.getElementById('qrBg').value;
    const transparent = document.getElementById('transparentCheck').checked;
    const hd = new QRCodeStyling(buildOptions(style, color, bg, transparent, 1000, 'canvas'));
    hd.download({ name: 'qr-' + SLUG + '-' + style, extension: 'png' });
}
function downloadSVG() {
    const style = document.querySelector('input[name="qr_style"]:checked')?.value || 'circles';
    const color = document.getElementById('qrColor').value;
    const bg = document.getElementById('qrBg').value;
    const transparent = document.getElementById('transparentCheck').checked;
    const hd = new QRCodeStyling(buildOptions(style, color, bg, transparent, 1000, 'svg'));
    hd.download({ name: 'qr-' + SLUG + '-' + style, extension: 'svg' });
}
function copyLink() {
    navigator.clipboard.writeText(MENU_URL).then(() => alert('تم نسخ الرابط ✓'));
}
</script>

<?php require __DIR__ . '/../includes/footer_admin.php'; ?>
