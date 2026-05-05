<?php
require_once __DIR__ . '/../includes/functions.php';
requireLogin();
$rid = $_SESSION['store_id'];
// $r needed both inside POST (limit check on create) and GET (render footer badges
// with $r['max_categories']). Load once at the top so it's always defined.
$r = currentStore($pdo);
requireActivePlan($r);
// Sector-aware labels (e.g. "الأقسام" for restaurants vs "الفئات" for clothing).
$pageTitle = bizLabel($r, 'categories');
$labelCat = bizLabel($r, 'category');        // singular e.g. "القسم"
$labelCats = bizLabel($r, 'categories');     // plural e.g. "الأقسام"
$labelItem = bizLabel($r, 'singular');       // e.g. "صنف" / "منتج"
$labelItems = bizLabel($r, 'plural');        // e.g. "الأصناف" / "المنتجات"
$iconDefault = defaultCategoryIcon($r);      // sector fallback icon (🍽️/👕/🚗...)
$iconPalette = categoryIconPalette($r);      // sector-relevant emoji suggestions
$sectorCode = $r['biz_code'] ?? 'restaurant'; // used by renderCategoryIcon to pick local Fluent PNGs

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrfCheck()) {
    $action = $_POST['action'] ?? '';

    // AJAX reorder: accepts array of IDs in desired order, returns JSON
    if ($action === 'reorder') {
        $ids = $_POST['ids'] ?? [];
        if (is_array($ids)) {
            $stmt = $pdo->prepare('UPDATE categories SET sort_order = ? WHERE id = ? AND store_id = ?');
            foreach ($ids as $i => $id) {
                $stmt->execute([$i, (int) $id, $rid]);
            }
        }
        header('Content-Type: application/json');
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($action === 'create') {
        if (!isWithinLimit($pdo, $r, 'categories')) {
            flash('error', 'وصلت للحد الأقصى من ' . $labelCats . ' في باقتك (' . $r['max_categories'] . '). قم بالترقية لإضافة المزيد.');
            redirect(BASE_URL . '/admin/categories.php');
        }
        $name = trim($_POST['name'] ?? '');
        // Icon is optional — 'none' marker or empty string → stored as empty,
        // which renderCategoryIcon() displays as a neutral tag SVG.
        $icon = trim($_POST['icon'] ?? $iconDefault);
        if ($icon === 'none') $icon = '';
        if ($name) {
            $maxSort = (int) $pdo->query("SELECT COALESCE(MAX(sort_order),0) FROM categories WHERE store_id = $rid")->fetchColumn();
            $stmt = $pdo->prepare('INSERT INTO categories (store_id, name, icon, sort_order) VALUES (?, ?, ?, ?)');
            $stmt->execute([$rid, $name, $icon, $maxSort + 1]);
            flash('success', 'تم إضافة ' . $labelCat);
        }
    } elseif ($action === 'update') {
        $id = (int) ($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $icon = trim($_POST['icon'] ?? $iconDefault);
        if ($icon === 'none') $icon = '';
        if ($id && $name) {
            $stmt = $pdo->prepare('UPDATE categories SET name=?, icon=? WHERE id=? AND store_id=?');
            $stmt->execute([$name, $icon, $id, $rid]);
            flash('success', 'تم تحديث ' . $labelCat);
        }
    } elseif ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        $stmt = $pdo->prepare('DELETE FROM categories WHERE id=? AND store_id=?');
        $stmt->execute([$id, $rid]);
        flash('success', 'تم حذف ' . $labelCat);
    } elseif ($action === 'toggle') {
        $id = (int) ($_POST['id'] ?? 0);
        $stmt = $pdo->prepare('UPDATE categories SET is_active = 1 - is_active WHERE id=? AND store_id=?');
        $stmt->execute([$id, $rid]);
    }
    redirect(BASE_URL . '/admin/categories.php');
}

$categories = $pdo->prepare('SELECT c.*, (SELECT COUNT(*) FROM items i WHERE i.category_id = c.id) AS items_count FROM categories c WHERE c.store_id = ? ORDER BY c.sort_order ASC, c.id ASC');
$categories->execute([$rid]);
$categories = $categories->fetchAll();

require __DIR__ . '/../includes/header_admin.php';

$currentCount = count($categories);
$maxCats = (int) $r['max_categories'];
$isLimited = $maxCats !== -1;
$canAddMore = !$isLimited || $currentCount < $maxCats;
?>

<div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
    <div>
        <h2 class="text-xl font-bold text-gray-900"><?= e($labelCats) ?></h2>
        <p class="text-sm text-gray-500 mt-1">
            <?= $isLimited ? "$currentCount / $maxCats " . e($labelCat) : "$currentCount " . e($labelCat) . " · غير محدود" ?>
            <?php if ($isLimited && !$canAddMore): ?>
                — <a href="upgrade.php" class="text-emerald-600 font-bold">ترقّ لإضافة المزيد</a>
            <?php endif; ?>
        </p>
    </div>
    <?php if ($canAddMore): ?>
    <button onclick="openCategoryModal()" class="inline-flex items-center justify-center gap-2 px-5 py-2.5 rounded-xl bg-gradient-to-r from-emerald-600 to-teal-600 text-white font-bold shadow-lg shadow-emerald-500/30 hover:shadow-xl transition">
        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/></svg>
        <?= e($labelCat) ?> جديد
    </button>
    <?php else: ?>
    <a href="upgrade.php" class="inline-flex items-center justify-center gap-2 px-5 py-2.5 rounded-xl bg-gradient-to-r from-amber-500 to-orange-500 text-white font-bold shadow-lg transition">
        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
        ترقية لإضافة المزيد
    </a>
    <?php endif; ?>
</div>

<?php if (!$categories): ?>
    <div class="bg-white rounded-2xl shadow-soft p-12 text-center">
        <div class="inline-flex items-center justify-center w-20 h-20 rounded-full bg-emerald-50 text-emerald-600 mb-4">
            <svg class="w-10 h-10" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 10h16M4 14h16M4 18h16"/></svg>
        </div>
        <h3 class="text-lg font-bold mb-2">لا توجد <?= e($labelCats) ?> بعد</h3>
        <p class="text-gray-500 mb-6">ابدأ بتنظيم <?= e($labelItems) ?> في مجموعات واضحة</p>
        <button onclick="openCategoryModal()" class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl bg-emerald-600 text-white font-bold hover:bg-emerald-700 transition">أضف أول <?= e($labelCat) ?></button>
    </div>
<?php else: ?>

<!-- Reorder hint -->
<div class="mb-4 flex items-center gap-2 text-xs text-gray-500">
    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 8h16M4 16h16"/></svg>
    <span>اسحب لإعادة الترتيب · اضغط على البطاقة لعرض <?= e($labelItems) ?></span>
</div>

<div id="categoriesGrid" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
    <?php foreach ($categories as $cat):
        $count = (int) $cat['items_count'];
        $isHidden = !$cat['is_active'];
        $countClass = $count === 0
            ? 'bg-gray-100 text-gray-500'
            : ($count >= 10 ? 'bg-emerald-100 text-emerald-700' : 'bg-emerald-50 text-emerald-600');
    ?>
    <div class="cat-card group bg-white rounded-2xl shadow-soft hover:shadow-card transition relative overflow-hidden <?= $isHidden ? 'opacity-70' : '' ?>" data-id="<?= $cat['id'] ?>">
        <!-- Hidden ribbon -->
        <?php if ($isHidden): ?>
            <div class="absolute top-3 left-3 z-10 px-2 py-0.5 text-[10px] font-bold bg-gray-900/80 text-white rounded-full backdrop-blur">مخفي</div>
        <?php endif; ?>

        <!-- Drag handle (top-left corner) -->
        <button type="button" class="drag-handle absolute top-3 <?= $isHidden ? 'left-16' : 'left-3' ?> z-10 w-8 h-8 flex items-center justify-center rounded-lg text-gray-300 hover:text-gray-600 hover:bg-gray-50 cursor-grab active:cursor-grabbing transition" title="اسحب لإعادة الترتيب" aria-label="إعادة ترتيب">
            <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20"><path d="M7 4a1 1 0 100 2 1 1 0 000-2zm0 5a1 1 0 100 2 1 1 0 000-2zm0 5a1 1 0 100 2 1 1 0 000-2zm6-10a1 1 0 100 2 1 1 0 000-2zm0 5a1 1 0 100 2 1 1 0 000-2zm0 5a1 1 0 100 2 1 1 0 000-2z"/></svg>
        </button>

        <!-- Card body (clickable → items filtered by this category) -->
        <a href="items.php?category=<?= $cat['id'] ?>" class="block p-5 pt-4">
            <div class="flex items-center gap-4">
                <div class="w-14 h-14 rounded-2xl bg-gradient-to-br from-emerald-50 to-teal-50 flex items-center justify-center flex-shrink-0 group-hover:scale-105 transition-transform">
                    <?php /* Empty icon is intentional ("no-icon" opt-out); renderCategoryIcon shows a neutral tag SVG in that case. */ ?>
                    <?= renderCategoryIcon($cat['icon'] ?? '', $sectorCode, 'w-10 h-10', $cat['name']) ?>
                </div>
                <div class="min-w-0 flex-1">
                    <h3 class="font-bold text-gray-900 truncate mb-1"><?= e($cat['name']) ?></h3>
                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-bold <?= $countClass ?>">
                        <?php if ($count === 0): ?>
                            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                            لا <?= e($labelItems) ?> بعد
                        <?php else: ?>
                            <?= $count ?> <?= e($labelItem) ?>
                        <?php endif; ?>
                    </span>
                </div>
                <svg class="w-5 h-5 text-gray-300 group-hover:text-emerald-500 transition flex-shrink-0 rtl-flip" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
            </div>
        </a>

        <!-- Action bar -->
        <div class="border-t border-gray-50 px-3 py-2 flex items-center justify-around bg-gray-50/30">
            <a href="items.php?new=1&category=<?= $cat['id'] ?>" class="flex-1 inline-flex items-center justify-center gap-1.5 px-2 py-2 rounded-lg text-xs font-semibold text-emerald-700 hover:bg-emerald-50 transition" title="إضافة <?= e($labelItem) ?> لهذا <?= e($labelCat) ?>">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/></svg>
                <span><?= e($labelItem) ?></span>
            </a>

            <form method="POST" class="flex-1 flex">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="toggle">
                <input type="hidden" name="id" value="<?= $cat['id'] ?>">
                <button type="submit" class="w-full inline-flex items-center justify-center gap-1.5 px-2 py-2 rounded-lg text-xs font-semibold text-gray-700 hover:bg-gray-100 transition" title="<?= $isHidden ? 'إظهار' : 'إخفاء' ?>">
                    <?php if (!$isHidden): ?>
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                        <span>إخفاء</span>
                    <?php else: ?>
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.542-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.542 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21"/></svg>
                        <span>إظهار</span>
                    <?php endif; ?>
                </button>
            </form>

            <button type="button" onclick='editCategory(<?= json_encode(["id"=>$cat["id"],"name"=>$cat["name"],"icon"=>$cat["icon"]]) ?>)' class="flex-1 inline-flex items-center justify-center gap-1.5 px-2 py-2 rounded-lg text-xs font-semibold text-gray-700 hover:bg-gray-100 transition">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                <span>تعديل</span>
            </button>

            <form method="POST" class="flex-1 flex" onsubmit="return confirm('حذف هذا <?= e($labelCat) ?> وجميع <?= e($labelItems) ?>؟')">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= $cat['id'] ?>">
                <button type="submit" class="w-full inline-flex items-center justify-center gap-1.5 px-2 py-2 rounded-lg text-xs font-semibold text-red-600 hover:bg-red-50 transition">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                    <span>حذف</span>
                </button>
            </form>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Modal -->
<div id="categoryModal" class="fixed inset-0 bg-black/50 z-50 hidden items-center justify-center p-4" onclick="if(event.target===this)closeCategoryModal()">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md max-h-[90vh] flex flex-col">
        <form method="POST" id="categoryForm" class="flex flex-col min-h-0">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="create" id="modalAction">
            <input type="hidden" name="id" value="" id="modalId">

            <div class="p-6 border-b border-gray-100 flex items-center justify-between">
                <h3 class="text-lg font-bold" id="modalTitle"><?= e($labelCat) ?> جديد</h3>
                <button type="button" onclick="closeCategoryModal()" class="p-1 hover:bg-gray-100 rounded-lg">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>
            <div class="p-6 space-y-4 overflow-y-auto">
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-2">اسم <?= e($labelCat) ?></label>
                    <input type="text" name="name" id="modalName" required class="w-full px-4 py-3 rounded-xl border-2 border-gray-100 focus:border-emerald-500 transition">
                </div>
                <div>
                    <div class="flex items-center justify-between mb-2">
                        <label class="block text-sm font-semibold text-gray-700">الأيقونة <span class="text-gray-400 font-normal text-xs">(اختيارية)</span></label>
                        <button type="button"
                                data-icon="none"
                                onclick="selectIcon(this)"
                                class="icon-option-none inline-flex items-center gap-1.5 px-3 py-1 rounded-lg border-2 border-gray-200 text-gray-600 hover:border-emerald-500 hover:bg-emerald-50 hover:text-emerald-700 transition text-xs font-semibold">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"/></svg>
                            بدون أيقونة
                        </button>
                    </div>
                    <div class="grid grid-cols-6 gap-2 mb-3" id="iconPicker">
                        <?php foreach ($iconPalette as $emoji): ?>
                            <button type="button"
                                    data-icon="<?= e($emoji) ?>"
                                    onclick="selectIcon(this)"
                                    class="icon-option aspect-square rounded-xl border-2 border-gray-100 hover:border-emerald-500 hover:bg-emerald-50 transition flex items-center justify-center p-1.5">
                                <?= renderCategoryIcon($emoji, $sectorCode, 'w-full h-full', '') ?>
                            </button>
                        <?php endforeach; ?>
                    </div>
                    <!-- Preview of current choice -->
                    <div class="flex items-center gap-3 p-3 rounded-xl bg-gray-50 border-2 border-gray-100">
                        <div class="w-14 h-14 rounded-xl bg-white flex items-center justify-center flex-shrink-0" id="iconPreview">
                            <?= renderCategoryIcon($iconDefault, $sectorCode, 'w-10 h-10', '') ?>
                        </div>
                        <div class="text-xs text-gray-500">
                            <div class="font-semibold text-gray-700 mb-0.5" id="iconPreviewLabel">الأيقونة المختارة</div>
                            <div id="iconPreviewHint">اختر من الأيقونات أعلاه أو «بدون أيقونة»</div>
                        </div>
                    </div>
                    <input type="hidden" name="icon" id="modalIcon" value="<?= e($iconDefault) ?>">
                </div>
            </div>
            <div class="p-6 border-t border-gray-100 flex justify-end gap-3">
                <button type="button" onclick="closeCategoryModal()" class="px-5 py-2.5 rounded-xl text-gray-700 hover:bg-gray-100 font-semibold">إلغاء</button>
                <button type="submit" class="px-6 py-2.5 rounded-xl bg-gradient-to-r from-emerald-600 to-teal-600 text-white font-bold">حفظ</button>
            </div>
        </form>
    </div>
</div>

<style>
    /* Flip chevron for RTL so it points inward (right-to-left) */
    .rtl-flip { transform: scaleX(-1); }
    /* Sortable visual feedback */
    .sortable-ghost { opacity: 0.4; transform: scale(0.98); }
    .sortable-chosen { box-shadow: 0 10px 30px -5px rgba(5,150,105,0.3); }
    .sortable-drag { cursor: grabbing; }
</style>

<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.2/Sortable.min.js"></script>
<script>
const CSRF = <?= json_encode(csrfToken()) ?>;

const CAT_LABEL = <?= json_encode($labelCat) ?>;
const ICON_DEFAULT = <?= json_encode($iconDefault, JSON_UNESCAPED_UNICODE) ?>;

// Neutral SVG shown when user picks "بدون أيقونة". Must match renderCategoryIcon()
// server-side output so preview = actual rendered state.
const NO_ICON_SVG = '<svg class="w-10 h-10 text-emerald-500/70" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A2 2 0 013 12V7a4 4 0 014-4z"/></svg>';

function syncIconPreview(emoji) {
    const preview = document.getElementById('iconPreview');
    const label = document.getElementById('iconPreviewLabel');
    const hint = document.getElementById('iconPreviewHint');
    if (!preview) return;
    // "No-icon" state
    if (emoji === 'none' || emoji === '') {
        preview.innerHTML = NO_ICON_SVG;
        if (label) label.textContent = 'بدون أيقونة';
        if (hint) hint.textContent = 'سيظهر شعار عام لهذه الفئة';
        return;
    }
    if (label) label.textContent = 'الأيقونة المختارة';
    if (hint) hint.textContent = 'اختر من الأيقونات أعلاه أو «بدون أيقونة»';
    // Mirror the picker choice into the preview box by cloning the matching option's image.
    const picker = document.getElementById('iconPicker');
    const match = picker && picker.querySelector('[data-icon="' + emoji + '"]');
    if (match) {
        preview.innerHTML = match.innerHTML;
    } else {
        preview.innerHTML = '<span class="w-10 h-10 inline-flex items-center justify-center text-3xl leading-none">' + emoji + '</span>';
    }
}

function selectIcon(btn) {
    const emoji = btn.dataset.icon;
    document.getElementById('modalIcon').value = emoji;
    // Clear any previously selected option (both emoji grid + "no icon" pill)
    document.querySelectorAll('.icon-option, .icon-option-none').forEach(b => b.classList.remove('border-emerald-500', 'bg-emerald-50', 'text-emerald-700'));
    // Highlight current selection
    if (btn.classList.contains('icon-option-none')) {
        btn.classList.add('border-emerald-500', 'bg-emerald-50', 'text-emerald-700');
    } else {
        btn.classList.add('border-emerald-500', 'bg-emerald-50');
    }
    syncIconPreview(emoji);
}

// Kept for backward compatibility (if called from other places).
function highlightIcon(btn) { selectIcon(btn); }

function openCategoryModal() {
    document.getElementById('modalTitle').textContent = CAT_LABEL + ' جديد';
    document.getElementById('modalAction').value = 'create';
    document.getElementById('modalId').value = '';
    document.getElementById('modalName').value = '';
    document.getElementById('modalIcon').value = ICON_DEFAULT;
    document.querySelectorAll('.icon-option, .icon-option-none').forEach(b => b.classList.remove('border-emerald-500', 'bg-emerald-50', 'text-emerald-700'));
    syncIconPreview(ICON_DEFAULT);
    document.getElementById('categoryModal').classList.remove('hidden');
    document.getElementById('categoryModal').classList.add('flex');
    setTimeout(() => document.getElementById('modalName').focus(), 50);
}
function closeCategoryModal() {
    document.getElementById('categoryModal').classList.add('hidden');
    document.getElementById('categoryModal').classList.remove('flex');
}
function editCategory(data) {
    document.getElementById('modalTitle').textContent = 'تعديل ' + CAT_LABEL;
    document.getElementById('modalAction').value = 'update';
    document.getElementById('modalId').value = data.id;
    document.getElementById('modalName').value = data.name;
    // Empty-string icon means the category was saved with "no icon" — preserve that intent
    const isNone = !data.icon;
    const current = isNone ? 'none' : data.icon;
    document.getElementById('modalIcon').value = isNone ? '' : current;
    document.querySelectorAll('.icon-option, .icon-option-none').forEach(b => {
        b.classList.remove('border-emerald-500', 'bg-emerald-50', 'text-emerald-700');
        if (b.dataset.icon === current) {
            if (b.classList.contains('icon-option-none')) {
                b.classList.add('border-emerald-500', 'bg-emerald-50', 'text-emerald-700');
            } else {
                b.classList.add('border-emerald-500', 'bg-emerald-50');
            }
        }
    });
    syncIconPreview(current);
    document.getElementById('categoryModal').classList.remove('hidden');
    document.getElementById('categoryModal').classList.add('flex');
}

// Drag-and-drop reordering
const grid = document.getElementById('categoriesGrid');
if (grid && window.Sortable) {
    Sortable.create(grid, {
        handle: '.drag-handle',
        animation: 180,
        delay: 100,
        delayOnTouchOnly: true,
        ghostClass: 'sortable-ghost',
        chosenClass: 'sortable-chosen',
        dragClass: 'sortable-drag',
        onEnd: saveOrder
    });
}

function saveOrder() {
    const ids = Array.from(grid.querySelectorAll('.cat-card')).map(el => el.dataset.id);
    const fd = new FormData();
    fd.append('action', 'reorder');
    fd.append('csrf', CSRF);
    ids.forEach(id => fd.append('ids[]', id));
    fetch(window.location.pathname, { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(r => r.json())
        .then(j => {
            if (!j.ok) console.warn('reorder failed', j);
        })
        .catch(err => console.error('reorder error', err));
}

// Close modal on Escape
document.addEventListener('keydown', e => {
    if (e.key === 'Escape' && !document.getElementById('categoryModal').classList.contains('hidden')) {
        closeCategoryModal();
    }
});
</script>

<?php require __DIR__ . '/../includes/footer_admin.php'; ?>
