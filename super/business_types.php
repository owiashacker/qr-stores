<?php
require_once __DIR__ . '/../includes/functions.php';
requireAdminLogin();
$pageTitle = 'أنواع المتاجر';

// POST handler — update labels / order_verb / fields_schema / active flag.
// `code` is immutable (used as stable key in app logic); all other fields editable.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrfCheck()) {
    $action = $_POST['action'] ?? '';

    if ($action === 'update') {
        $id = (int) ($_POST['id'] ?? 0);
        $name_ar = trim($_POST['name_ar'] ?? '');
        $icon = trim($_POST['icon'] ?? '🏪');
        $label_singular = trim($_POST['label_singular'] ?? '');
        $label_plural = trim($_POST['label_plural'] ?? '');
        $label_category = trim($_POST['label_category'] ?? '');
        $order_verb = trim($_POST['order_verb'] ?? 'أطلب');
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        $sort_order = (int) ($_POST['sort_order'] ?? 0);

        // Parse fields_schema from the repeatable form rows.
        // Each field row sends: key[], label[], type[], options[] (comma-separated), placeholder[].
        $keys = $_POST['f_key'] ?? [];
        $labels = $_POST['f_label'] ?? [];
        $types = $_POST['f_type'] ?? [];
        $optionsArr = $_POST['f_options'] ?? [];
        $placeholders = $_POST['f_placeholder'] ?? [];

        $fields = [];
        $count = count($keys);
        for ($i = 0; $i < $count; $i++) {
            $key = trim($keys[$i] ?? '');
            if ($key === '') continue;
            // Normalize key: lowercase ASCII + underscores only. Prevents JSON injection / HTML collisions.
            $key = preg_replace('/[^a-z0-9_]/', '', strtolower($key));
            if ($key === '') continue;

            $type = in_array($types[$i] ?? '', ['text', 'textarea', 'number', 'select', 'multiselect', 'boolean'], true)
                ? $types[$i] : 'text';

            $field = [
                'key' => $key,
                'label' => trim($labels[$i] ?? $key),
                'type' => $type,
            ];

            // Options: required for select/multiselect. CSV input — split, trim, drop empties.
            if (in_array($type, ['select', 'multiselect'], true)) {
                $raw = (string) ($optionsArr[$i] ?? '');
                $opts = array_values(array_filter(array_map('trim', explode(',', $raw)), fn($v) => $v !== ''));
                if ($opts) $field['options'] = $opts;
            }

            $ph = trim($placeholders[$i] ?? '');
            if ($ph !== '') $field['placeholder'] = $ph;

            $fields[] = $field;
        }

        $schema = ['fields' => $fields];
        $schemaJson = json_encode($schema, JSON_UNESCAPED_UNICODE);

        $stmt = $pdo->prepare('UPDATE business_types
            SET name_ar = ?, icon = ?, label_singular = ?, label_plural = ?,
                label_category = ?, order_verb = ?, fields_schema = ?,
                is_active = ?, sort_order = ?
            WHERE id = ?');
        $stmt->execute([
            $name_ar, $icon, $label_singular, $label_plural,
            $label_category, $order_verb, $schemaJson,
            $is_active, $sort_order, $id,
        ]);
        flash('success', 'تم تحديث النوع');
        redirect(BASE_URL . '/super/business_types.php?edit=' . $id);
    }
}

$types = $pdo->query('SELECT bt.*, (SELECT COUNT(*) FROM stores WHERE business_type_id = bt.id) AS stores_count
    FROM business_types bt ORDER BY sort_order, id')->fetchAll();

$editId = (int) ($_GET['edit'] ?? 0);
$editType = null;
foreach ($types as $t) if ($t['id'] == $editId) $editType = $t;

require __DIR__ . '/../includes/header_super.php';
?>

<?php if ($editType):
    $schema = json_decode($editType['fields_schema'] ?? 'null', true);
    $fields = (is_array($schema) && !empty($schema['fields'])) ? $schema['fields'] : [];
?>
<div class="card rounded-2xl p-6 md:p-8">
    <div class="flex items-center justify-between mb-6">
        <div>
            <h2 class="text-xl font-bold text-white">
                <?= e($editType['icon']) ?> تعديل نوع:
                <span class="text-emerald-400"><?= e($editType['name_ar']) ?></span>
                <span class="text-xs text-gray-500">(<?= e($editType['code']) ?>)</span>
            </h2>
            <p class="text-xs text-gray-500 mt-1">كود النوع ثابت ولا يمكن تغييره. كل المسميات والحقول تحته قابلة للتعديل.</p>
        </div>
        <a href="business_types.php" class="p-2 rounded-lg hover:bg-white/5 text-gray-400">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
        </a>
    </div>

    <form method="POST" class="space-y-6">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="update">
        <input type="hidden" name="id" value="<?= $editType['id'] ?>">

        <!-- Basic info -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
                <label class="block text-sm font-semibold text-gray-300 mb-2">اسم النوع بالعربية</label>
                <input type="text" name="name_ar" value="<?= e($editType['name_ar']) ?>" required class="w-full px-4 py-3 rounded-xl border-2 bg-white/5 border-white/10 text-white focus:border-emerald-500 transition">
            </div>
            <div>
                <label class="block text-sm font-semibold text-gray-300 mb-2">الأيقونة (إيموجي)</label>
                <input type="text" name="icon" value="<?= e($editType['icon']) ?>" maxlength="4" class="w-full px-4 py-3 rounded-xl border-2 bg-white/5 border-white/10 text-white text-2xl text-center focus:border-emerald-500 transition">
            </div>

            <div>
                <label class="block text-sm font-semibold text-gray-300 mb-2">مسمى المنتج (مفرد)</label>
                <input type="text" name="label_singular" value="<?= e($editType['label_singular']) ?>" required class="w-full px-4 py-3 rounded-xl border-2 bg-white/5 border-white/10 text-white focus:border-emerald-500 transition" placeholder="مثال: صنف / منتج / جهاز">
                <p class="text-xs text-gray-500 mt-1">يُستخدم في الأزرار والنماذج: "صنف جديد"</p>
            </div>
            <div>
                <label class="block text-sm font-semibold text-gray-300 mb-2">مسمى المنتجات (جمع)</label>
                <input type="text" name="label_plural" value="<?= e($editType['label_plural']) ?>" required class="w-full px-4 py-3 rounded-xl border-2 bg-white/5 border-white/10 text-white focus:border-emerald-500 transition" placeholder="مثال: الأصناف / المنتجات / الأجهزة">
            </div>

            <div>
                <label class="block text-sm font-semibold text-gray-300 mb-2">مسمى التصنيف</label>
                <select name="label_category" class="w-full px-4 py-3 rounded-xl border-2 bg-white/5 border-white/10 text-white focus:border-emerald-500 transition">
                    <?php foreach (['القسم', 'الفئة', 'النوع', 'المجموعة'] as $opt): ?>
                        <option value="<?= e($opt) ?>" <?= $editType['label_category'] === $opt ? 'selected' : '' ?>><?= e($opt) ?></option>
                    <?php endforeach; ?>
                </select>
                <p class="text-xs text-gray-500 mt-1">يظهر في الشريط الجانبي والتصفية</p>
            </div>
            <div>
                <label class="block text-sm font-semibold text-gray-300 mb-2">فعل الطلب</label>
                <select name="order_verb" class="w-full px-4 py-3 rounded-xl border-2 bg-white/5 border-white/10 text-white focus:border-emerald-500 transition">
                    <?php foreach (['أطلب', 'استفسر', 'اشتر', 'احجز'] as $opt): ?>
                        <option value="<?= e($opt) ?>" <?= $editType['order_verb'] === $opt ? 'selected' : '' ?>><?= e($opt) ?></option>
                    <?php endforeach; ?>
                </select>
                <p class="text-xs text-gray-500 mt-1">"أطلب" للطعام، "استفسر" للسيارات، إلخ</p>
            </div>

            <div>
                <label class="block text-sm font-semibold text-gray-300 mb-2">ترتيب العرض</label>
                <input type="number" name="sort_order" value="<?= e($editType['sort_order']) ?>" class="w-full px-4 py-3 rounded-xl border-2 bg-white/5 border-white/10 text-white focus:border-emerald-500 transition">
            </div>
            <div class="flex items-center">
                <label class="flex items-center gap-3 cursor-pointer mt-7">
                    <input type="checkbox" name="is_active" <?= $editType['is_active'] ? 'checked' : '' ?> class="w-5 h-5 rounded text-emerald-600 focus:ring-emerald-500">
                    <span class="font-semibold text-gray-300">نشط (يظهر في قائمة التسجيل)</span>
                </label>
            </div>
        </div>

        <!-- Fields schema editor -->
        <div class="border-t border-white/10 pt-6">
            <div class="flex items-center justify-between mb-4">
                <div>
                    <h3 class="font-bold text-white">الحقول الخاصة بهذا النوع</h3>
                    <p class="text-xs text-gray-500 mt-1">تظهر للتاجر عند إضافة منتج، وللزبون في صفحة المنتج.</p>
                </div>
                <button type="button" onclick="addFieldRow()" class="inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white font-semibold text-sm transition">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/></svg>
                    حقل جديد
                </button>
            </div>

            <div id="fieldsContainer" class="space-y-3">
                <?php if (empty($fields)): ?>
                    <div id="emptyFieldsMsg" class="text-sm text-gray-500 p-6 rounded-xl bg-white/5 border border-dashed border-white/10 text-center">
                        لا توجد حقول مخصصة لهذا النوع. انقر "حقل جديد" لإضافة واحد.
                    </div>
                <?php else: ?>
                    <?php foreach ($fields as $i => $f): ?>
                        <?= renderBtFieldRow($i, $f) ?>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Submit -->
        <div class="flex gap-3 pt-4 border-t border-white/10">
            <button type="submit" class="px-8 py-3 rounded-xl bg-gradient-to-r from-emerald-600 to-teal-600 text-white font-bold shadow-lg hover:shadow-xl transition">حفظ التغييرات</button>
            <a href="business_types.php" class="px-6 py-3 rounded-xl text-gray-300 hover:bg-white/5 font-semibold flex items-center">إلغاء</a>
        </div>
    </form>
</div>

<template id="fieldRowTpl">
    <?= renderBtFieldRow('__INDEX__', ['key' => '', 'label' => '', 'type' => 'text', 'options' => [], 'placeholder' => '']) ?>
</template>

<script>
let fieldRowIndex = <?= count($fields) ?>;

function addFieldRow() {
    const empty = document.getElementById('emptyFieldsMsg');
    if (empty) empty.remove();
    const tpl = document.getElementById('fieldRowTpl').innerHTML.replace(/__INDEX__/g, fieldRowIndex);
    fieldRowIndex++;
    const wrap = document.createElement('div');
    wrap.innerHTML = tpl;
    document.getElementById('fieldsContainer').appendChild(wrap.firstElementChild);
    toggleOptionsVisibility(document.getElementById('fieldsContainer').lastElementChild.querySelector('select[name="f_type[]"]'));
}

function removeFieldRow(btn) {
    const row = btn.closest('.field-row');
    row.remove();
    const container = document.getElementById('fieldsContainer');
    if (!container.querySelector('.field-row')) {
        container.innerHTML = '<div id="emptyFieldsMsg" class="text-sm text-gray-500 p-6 rounded-xl bg-white/5 border border-dashed border-white/10 text-center">لا توجد حقول مخصصة لهذا النوع. انقر "حقل جديد" لإضافة واحد.</div>';
    }
}

// Show "options" input only for select/multiselect types
function toggleOptionsVisibility(select) {
    const row = select.closest('.field-row');
    const optsWrap = row.querySelector('.options-wrap');
    const needs = ['select', 'multiselect'].includes(select.value);
    if (optsWrap) optsWrap.style.display = needs ? '' : 'none';
}

// Initial hookup
document.querySelectorAll('select[name="f_type[]"]').forEach(toggleOptionsVisibility);
</script>

<?php else: ?>

<!-- LIST VIEW -->
<div class="flex items-center justify-between mb-6">
    <div>
        <h2 class="text-xl font-bold text-white">أنواع المتاجر</h2>
        <p class="text-sm text-gray-500 mt-1"><?= count($types) ?> نوع — تحكّم بالمسميات والحقول الخاصة بكل قطاع</p>
    </div>
</div>

<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
    <?php foreach ($types as $t):
        $schema = json_decode($t['fields_schema'] ?? 'null', true);
        $fieldsCount = (is_array($schema) && !empty($schema['fields'])) ? count($schema['fields']) : 0;
    ?>
        <div class="card rounded-2xl p-5 hover:border-emerald-500/30 transition group relative <?= $t['is_active'] ? '' : 'opacity-60' ?>">
            <?php if (!$t['is_active']): ?>
                <span class="absolute top-3 left-3 px-2 py-0.5 rounded-full bg-gray-600 text-white text-[10px] font-bold">معطّل</span>
            <?php endif; ?>
            <div class="flex items-start gap-3 mb-3">
                <div class="text-4xl"><?= e($t['icon']) ?></div>
                <div class="flex-1 min-w-0">
                    <h3 class="font-bold text-white"><?= e($t['name_ar']) ?></h3>
                    <p class="text-xs text-gray-500 font-mono"><?= e($t['code']) ?></p>
                </div>
            </div>
            <div class="grid grid-cols-2 gap-2 text-xs mb-4">
                <div class="bg-white/5 rounded-lg p-2">
                    <div class="text-gray-500">المنتج</div>
                    <div class="text-gray-300 font-semibold"><?= e($t['label_singular']) ?></div>
                </div>
                <div class="bg-white/5 rounded-lg p-2">
                    <div class="text-gray-500">التصنيف</div>
                    <div class="text-gray-300 font-semibold"><?= e($t['label_category']) ?></div>
                </div>
                <div class="bg-white/5 rounded-lg p-2">
                    <div class="text-gray-500">الحقول</div>
                    <div class="text-emerald-400 font-semibold"><?= $fieldsCount ?> حقل</div>
                </div>
                <div class="bg-white/5 rounded-lg p-2">
                    <div class="text-gray-500">المتاجر</div>
                    <div class="text-gray-300 font-semibold"><?= (int) $t['stores_count'] ?></div>
                </div>
            </div>
            <a href="business_types.php?edit=<?= $t['id'] ?>" class="block w-full text-center px-4 py-2.5 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white font-semibold text-sm transition">
                تعديل
            </a>
        </div>
    <?php endforeach; ?>
</div>

<?php endif; ?>

<?php
/**
 * Render one editable field row. Called from PHP loop and from the JS template.
 * `$i` is either an integer index or the string `__INDEX__` (replaced by JS).
 */
function renderBtFieldRow($i, array $f): string
{
    $key = htmlspecialchars($f['key'] ?? '', ENT_QUOTES, 'UTF-8');
    $label = htmlspecialchars($f['label'] ?? '', ENT_QUOTES, 'UTF-8');
    $type = $f['type'] ?? 'text';
    $placeholder = htmlspecialchars($f['placeholder'] ?? '', ENT_QUOTES, 'UTF-8');
    $options = is_array($f['options'] ?? null) ? implode(', ', $f['options']) : '';
    $options = htmlspecialchars($options, ENT_QUOTES, 'UTF-8');
    $showOpts = in_array($type, ['select', 'multiselect'], true) ? '' : 'style="display:none"';

    $typeOptions = '';
    foreach ([
        'text' => 'نص قصير',
        'textarea' => 'نص طويل',
        'number' => 'رقم',
        'select' => 'اختيار من قائمة',
        'multiselect' => 'اختيار متعدد',
        'boolean' => 'نعم / لا',
    ] as $v => $l) {
        $sel = $type === $v ? ' selected' : '';
        $typeOptions .= '<option value="' . $v . '"' . $sel . '>' . $l . '</option>';
    }

    return <<<HTML
<div class="field-row p-4 rounded-xl bg-white/5 border border-white/10 space-y-3">
    <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
        <div>
            <label class="block text-xs font-semibold text-gray-400 mb-1">المفتاح (بالإنجليزية)</label>
            <input type="text" name="f_key[]" value="{$key}" placeholder="مثال: size, color, year" class="w-full px-3 py-2 rounded-lg bg-white/5 border border-white/10 text-white text-sm focus:border-emerald-500 font-mono" dir="ltr">
        </div>
        <div>
            <label class="block text-xs font-semibold text-gray-400 mb-1">التسمية (بالعربية)</label>
            <input type="text" name="f_label[]" value="{$label}" placeholder="مثال: المقاس" class="w-full px-3 py-2 rounded-lg bg-white/5 border border-white/10 text-white text-sm focus:border-emerald-500">
        </div>
        <div>
            <label class="block text-xs font-semibold text-gray-400 mb-1">النوع</label>
            <select name="f_type[]" onchange="toggleOptionsVisibility(this)" class="w-full px-3 py-2 rounded-lg bg-white/5 border border-white/10 text-white text-sm focus:border-emerald-500">
                {$typeOptions}
            </select>
        </div>
    </div>
    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
        <div class="options-wrap" {$showOpts}>
            <label class="block text-xs font-semibold text-gray-400 mb-1">الخيارات (افصل بفواصل)</label>
            <input type="text" name="f_options[]" value="{$options}" placeholder="مثال: S, M, L, XL" class="w-full px-3 py-2 rounded-lg bg-white/5 border border-white/10 text-white text-sm focus:border-emerald-500">
        </div>
        <div>
            <label class="block text-xs font-semibold text-gray-400 mb-1">نص توضيحي (Placeholder)</label>
            <input type="text" name="f_placeholder[]" value="{$placeholder}" placeholder="يظهر داخل حقل الإدخال" class="w-full px-3 py-2 rounded-lg bg-white/5 border border-white/10 text-white text-sm focus:border-emerald-500">
        </div>
    </div>
    <div class="flex justify-end">
        <button type="button" onclick="removeFieldRow(this)" class="text-xs text-red-400 hover:text-red-300 font-semibold">× حذف هذا الحقل</button>
    </div>
</div>
HTML;
}
?>

<?php require __DIR__ . '/../includes/footer_super.php'; ?>
