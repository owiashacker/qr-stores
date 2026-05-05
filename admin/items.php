<?php
require_once __DIR__ . '/../includes/functions.php';
requireLogin();
$rid = $_SESSION['store_id'];
// $r (current restaurant + plan caps) is needed for BOTH POST (limit/capability checks)
// AND GET (thumbnail resolution at line ~249 + rendering the MAX gallery + pricing) —
// load it once up top so "Undefined variable $r" doesn't fire on plain page loads.
$r = currentStore($pdo);
requireActivePlan($r);
// Sector-aware labels: "الأصناف" (restaurant) / "المنتجات" (clothing) / "الموديلات" (cars) / etc.
$pageTitle = bizLabel($r, 'plural');
$labelItem = bizLabel($r, 'singular');       // e.g. "صنف" / "منتج" / "موديل"
$labelItems = bizLabel($r, 'plural');        // plural e.g. "الأصناف"
$labelCat = bizLabel($r, 'category');        // e.g. "القسم" / "الفئة"
$labelCats = bizLabel($r, 'categories');     // e.g. "الأقسام" / "الفئات"

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrfCheck()) {
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id = (int) ($_POST['id'] ?? 0);
        // Enforce limit only for new items
        if (!$id && !isWithinLimit($pdo, $r, 'items')) {
            flash('error', 'وصلت للحد الأقصى من ' . $labelItems . ' في باقتك (' . $r['max_items'] . '). قم بالترقية لإضافة المزيد.');
            redirect(BASE_URL . '/admin/items.php');
        }
        $category_id = (int) ($_POST['category_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $price = (float) ($_POST['price'] ?? 0);
        $old_price = !empty($_POST['old_price']) && canDo($r, 'use_discount') ? (float) $_POST['old_price'] : null;
        $is_available = isset($_POST['is_available']) ? 1 : 0;
        $is_featured = isset($_POST['is_featured']) && canDo($r, 'feature_items') ? 1 : 0;

        // Per-sector specs (JSON). Validates/sanitizes against the store's schema —
        // unknown keys are stripped, select values restricted to allowed options.
        $schema = bizFieldsSchema($r);
        $specsArr = sanitizeSpecsPost($schema, $_POST['specs'] ?? []);
        $specsJson = $specsArr ? json_encode($specsArr, JSON_UNESCAPED_UNICODE) : null;

        // Verify category belongs to this restaurant
        $check = $pdo->prepare('SELECT id FROM categories WHERE id=? AND store_id=?');
        $check->execute([$category_id, $rid]);
        if (!$check->fetch()) {
            flash('error', $labelCat . ' غير صالح');
            redirect(BASE_URL . '/admin/items.php');
        }

        $image = null;
        if ($id) {
            $old = $pdo->prepare('SELECT image FROM items WHERE id=? AND store_id=?');
            $old->execute([$id, $rid]);
            $image = $old->fetchColumn();
        }

        if (!empty($_FILES['image']['name'])) {
            $newImage = uploadImage('image', __DIR__ . '/../assets/uploads/items');
            if ($newImage) {
                if ($id && $image) deleteUpload('items', $image);
                $image = $newImage;
            }
        }

        if ($id) {
            $stmt = $pdo->prepare('UPDATE items SET category_id=?, name=?, description=?, price=?, old_price=?, image=?, is_available=?, is_featured=?, specs=? WHERE id=? AND store_id=?');
            $stmt->execute([$category_id, $name, $description, $price, $old_price, $image, $is_available, $is_featured, $specsJson, $id, $rid]);
            $itemId = $id;
            flash('success', 'تم تحديث ' . $labelItem);
        } else {
            $maxSort = (int) $pdo->query("SELECT COALESCE(MAX(sort_order),0) FROM items WHERE store_id = $rid")->fetchColumn();
            $stmt = $pdo->prepare('INSERT INTO items (store_id, category_id, name, description, price, old_price, image, is_available, is_featured, specs, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([$rid, $category_id, $name, $description, $price, $old_price, $image, $is_available, $is_featured, $specsJson, $maxSort + 1]);
            $itemId = (int) $pdo->lastInsertId();
            flash('success', 'تم إضافة ' . $labelItem);
        }

        // -------- Multi-media upload (MAX plan only) --------
        // We accept images + short videos, hard cap 5 MB, strict MIME + header sniff.
        // If plan doesn't allow, silently ignore any media[] uploads.
        if (canDo($r, 'multiple_media') && !empty($_FILES['media']['name'][0])) {
            $mediaDir = __DIR__ . '/../assets/uploads/media';
            $files = $_FILES['media'];
            $fileCount = is_array($files['name']) ? count($files['name']) : 0;

            // Find the current max sort_order for this item's media
            $sortStmt = $pdo->prepare('SELECT COALESCE(MAX(sort_order), 0) FROM item_media WHERE item_id = ?');
            $sortStmt->execute([$itemId]);
            $sort = (int) $sortStmt->fetchColumn();

            // Check if item already has a cover image — if not, the first uploaded image auto-becomes cover
            $coverStmt = $pdo->prepare("SELECT COUNT(*) FROM item_media WHERE item_id = ? AND is_cover = 1");
            $coverStmt->execute([$itemId]);
            $hasCover = ((int) $coverStmt->fetchColumn()) > 0;

            $okCount = 0;
            $errors = [];
            $insertMedia = $pdo->prepare('INSERT INTO item_media (item_id, store_id, media_type, file_path, mime_type, file_size, sort_order, is_cover) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');

            for ($i = 0; $i < $fileCount; $i++) {
                if (empty($files['name'][$i])) continue;
                $fileArr = [
                    'name'     => $files['name'][$i],
                    'type'     => $files['type'][$i] ?? '',
                    'tmp_name' => $files['tmp_name'][$i],
                    'error'    => $files['error'][$i] ?? UPLOAD_ERR_NO_FILE,
                    'size'     => $files['size'][$i] ?? 0,
                ];
                $result = secure_upload_media_file($fileArr, $mediaDir, 5 * 1024 * 1024);
                if ($result === null) continue;
                if (isset($result['error'])) {
                    $errors[] = safe_html($files['name'][$i]) . ': ' . $result['error'];
                    continue;
                }
                $sort++;
                // Auto-cover: the first image uploaded to an item with no cover becomes the cover
                $markCover = 0;
                if (!$hasCover && $result['type'] === 'image') {
                    $markCover = 1;
                    $hasCover = true; // only one file per batch auto-becomes cover
                }
                $insertMedia->execute([
                    $itemId,
                    $rid,
                    $result['type'],
                    $result['filename'],
                    $result['mime'],
                    $result['size'],
                    $sort,
                    $markCover,
                ]);
                $okCount++;
            }

            if ($okCount > 0) flash('success', 'تم حفظ ' . $labelItem . ' + رفع ' . $okCount . ' ملف وسائط');
            if ($errors) flash('error', 'تعذّر رفع بعض الملفات: ' . implode(' — ', array_slice($errors, 0, 3)));
        }

        redirect(BASE_URL . '/admin/items.php?edit=' . $itemId);
    }

    if ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        // Fetch primary image + all media file paths before DB delete (cascade removes rows, but files need manual cleanup)
        $old = $pdo->prepare('SELECT image FROM items WHERE id=? AND store_id=?');
        $old->execute([$id, $rid]);
        $img = $old->fetchColumn();

        $mediaStmt = $pdo->prepare('SELECT file_path FROM item_media WHERE item_id = ? AND store_id = ?');
        $mediaStmt->execute([$id, $rid]);
        $mediaFiles = $mediaStmt->fetchAll(PDO::FETCH_COLUMN);

        if ($img) deleteUpload('items', $img);
        foreach ($mediaFiles as $f) deleteUpload('media', $f);

        $stmt = $pdo->prepare('DELETE FROM items WHERE id=? AND store_id=?');
        $stmt->execute([$id, $rid]);
        flash('success', 'تم حذف ' . $labelItem);
        redirect(BASE_URL . '/admin/items.php');
    }

    if ($action === 'delete_media') {
        $mediaId = (int) ($_POST['media_id'] ?? 0);
        $itemId = (int) ($_POST['item_id'] ?? 0);
        $isAjax = !empty($_POST['ajax']) || (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest');
        // Verify ownership — match store_id in the WHERE
        $stmt = $pdo->prepare('SELECT file_path, item_id, is_cover FROM item_media WHERE id = ? AND store_id = ?');
        $stmt->execute([$mediaId, $rid]);
        $mediaRow = $stmt->fetch();
        $ok = false;
        if ($mediaRow) {
            deleteUpload('media', $mediaRow['file_path']);
            $pdo->prepare('DELETE FROM item_media WHERE id = ? AND store_id = ?')->execute([$mediaId, $rid]);
            // If we deleted the cover, promote the next image (lowest sort_order) to be the new cover
            if ((int) $mediaRow['is_cover'] === 1) {
                $promo = $pdo->prepare("SELECT id FROM item_media WHERE item_id = ? AND store_id = ? AND media_type = 'image' ORDER BY sort_order, id LIMIT 1");
                $promo->execute([$mediaRow['item_id'], $rid]);
                $newCoverId = $promo->fetchColumn();
                if ($newCoverId) {
                    $pdo->prepare('UPDATE item_media SET is_cover = 1 WHERE id = ? AND store_id = ?')->execute([$newCoverId, $rid]);
                }
            }
            $ok = true;
        }
        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => $ok]);
            exit;
        }
        if ($ok) flash('success', 'تم حذف الملف');
        redirect(BASE_URL . '/admin/items.php' . ($itemId ? '?edit=' . $itemId : ''));
    }

    if ($action === 'set_cover') {
        $mediaId = (int) ($_POST['media_id'] ?? 0);
        $isAjax = !empty($_POST['ajax']) || (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest');
        // Verify this media belongs to the current restaurant AND is an image (videos can't be covers)
        $stmt = $pdo->prepare("SELECT item_id FROM item_media WHERE id = ? AND store_id = ? AND media_type = 'image'");
        $stmt->execute([$mediaId, $rid]);
        $targetItemId = $stmt->fetchColumn();
        $ok = false;
        if ($targetItemId) {
            // Atomic swap: clear old cover, set new — scoped to this item only
            $pdo->beginTransaction();
            try {
                $pdo->prepare('UPDATE item_media SET is_cover = 0 WHERE item_id = ? AND store_id = ?')->execute([$targetItemId, $rid]);
                $pdo->prepare('UPDATE item_media SET is_cover = 1 WHERE id = ? AND store_id = ?')->execute([$mediaId, $rid]);
                $pdo->commit();
                $ok = true;
            } catch (Throwable $e) {
                $pdo->rollBack();
            }
        }
        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => $ok]);
            exit;
        }
        if ($ok) flash('success', 'تم تعيين صورة الغلاف');
        redirect(BASE_URL . '/admin/items.php?edit=' . (int) ($targetItemId ?: ($_POST['item_id'] ?? 0)));
    }

    if ($action === 'toggle') {
        $id = (int) ($_POST['id'] ?? 0);
        $field = in_array($_POST['field'] ?? '', ['is_available', 'is_featured']) ? $_POST['field'] : 'is_available';
        $stmt = $pdo->prepare("UPDATE items SET $field = 1 - $field WHERE id=? AND store_id=?");
        $stmt->execute([$id, $rid]);
        redirect(BASE_URL . '/admin/items.php' . (!empty($_POST['cat']) ? '?cat=' . (int)$_POST['cat'] : ''));
    }
}

// Filters
$filterCat = isset($_GET['cat']) ? (int) $_GET['cat'] : 0;
$search = trim($_GET['q'] ?? '');

$categories = $pdo->prepare('SELECT * FROM categories WHERE store_id = ? ORDER BY sort_order, id');
$categories->execute([$rid]);
$categories = $categories->fetchAll();

if (!$categories) {
    require __DIR__ . '/../includes/header_admin.php';
    echo '<div class="bg-amber-50 border border-amber-200 text-amber-800 p-6 rounded-2xl">';
    echo '<h3 class="font-bold mb-2">⚠️ أنشئ ' . e($labelCat) . ' أولاً</h3>';
    echo '<p class="text-sm mb-4">لا يمكنك إضافة ' . e($labelItems) . ' قبل إنشاء ' . e($labelCat) . ' على الأقل.</p>';
    echo '<a href="categories.php" class="inline-block px-4 py-2 bg-amber-600 text-white rounded-lg font-semibold">إنشاء ' . e($labelCat) . '</a>';
    echo '</div>';
    require __DIR__ . '/../includes/footer_admin.php';
    exit;
}

$sql = 'SELECT i.*, c.name AS category_name FROM items i JOIN categories c ON i.category_id = c.id WHERE i.store_id = ?';
$params = [$rid];
if ($filterCat) {
    $sql .= ' AND i.category_id = ?';
    $params[] = $filterCat;
}
if ($search) {
    $sql .= ' AND (i.name LIKE ? OR i.description LIKE ?)';
    $params[] = "%$search%";
    $params[] = "%$search%";
}
$sql .= ' ORDER BY i.sort_order, i.id DESC';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$items = $stmt->fetchAll();

// Resolve a thumbnail URL for each grid card:
// On MAX plan: prefer the user-chosen cover image from the gallery — this mirrors what the
// public menu.php shows, so the admin grid accurately reflects the customer's view.
// Falls back to legacy `image` field if no gallery image exists.
if ($items && canDo($r, 'multiple_media')) {
    $itemIds = array_column($items, 'id');
    $ph = implode(',', array_fill(0, count($itemIds), '?'));
    // is_cover DESC makes the user-chosen cover win; fallback to lowest sort_order
    $mediaStmt = $pdo->prepare("SELECT item_id, file_path FROM item_media WHERE store_id = ? AND media_type = 'image' AND item_id IN ($ph) ORDER BY item_id, is_cover DESC, sort_order, id");
    $mediaStmt->execute(array_merge([$rid], $itemIds));
    $firstImgByItem = [];
    foreach ($mediaStmt->fetchAll() as $m) {
        $firstImgByItem[(int) $m['item_id']] = $firstImgByItem[(int) $m['item_id']] ?? $m['file_path'];
    }
    foreach ($items as &$it) {
        if (!empty($firstImgByItem[(int) $it['id']])) {
            $it['thumb_url'] = BASE_URL . '/assets/uploads/media/' . $firstImgByItem[(int) $it['id']];
        } elseif (!empty($it['image'])) {
            $it['thumb_url'] = BASE_URL . '/assets/uploads/items/' . $it['image'];
        } else {
            $it['thumb_url'] = null;
        }
    }
    unset($it);
} else {
    foreach ($items as &$it) {
        $it['thumb_url'] = !empty($it['image']) ? BASE_URL . '/assets/uploads/items/' . $it['image'] : null;
    }
    unset($it);
}

$showForm = isset($_GET['new']) || isset($_GET['edit']);
$editItem = null;
$editItemMedia = [];
if (!empty($_GET['edit'])) {
    $stmt = $pdo->prepare('SELECT * FROM items WHERE id=? AND store_id=?');
    $stmt->execute([(int) $_GET['edit'], $rid]);
    $editItem = $stmt->fetch();
    if ($editItem) {
        // is_cover DESC so the current cover is the first tile — users see which one is the "menu face"
        $mediaStmt = $pdo->prepare('SELECT * FROM item_media WHERE item_id = ? AND store_id = ? ORDER BY is_cover DESC, sort_order, id');
        $mediaStmt->execute([$editItem['id'], $rid]);
        $editItemMedia = $mediaStmt->fetchAll();
    }
}

require __DIR__ . '/../includes/header_admin.php';
?>

<?php if ($showForm): ?>
    <!-- Add/Edit Form -->
    <div class="bg-white rounded-2xl shadow-soft p-6 md:p-8">
        <div class="flex items-center justify-between mb-6">
            <h2 class="text-xl font-bold"><?= $editItem ? 'تعديل ' . e($labelItem) : 'إضافة ' . e($labelItem) . ' جديد' ?></h2>
            <a href="items.php" class="p-2 rounded-lg hover:bg-gray-100">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </a>
        </div>
        <form id="itemForm" method="POST" enctype="multipart/form-data" class="grid grid-cols-1 md:grid-cols-3 gap-6">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" value="<?= $editItem['id'] ?? '' ?>">

            <?php if (!canDo($r, 'multiple_media')): ?>
                <!-- Image (hidden on MAX — replaced by the multi-media gallery below) -->
                <div class="md:col-span-1">
                    <label class="block text-sm font-semibold text-gray-700 mb-2">صورة <?= e($labelItem) ?></label>
                    <label class="block aspect-square rounded-2xl border-2 border-dashed border-gray-200 hover:border-emerald-500 bg-gray-50 cursor-pointer overflow-hidden group relative transition">
                        <input type="file" name="image" accept="image/*" class="hidden" onchange="previewItem(this)">
                        <img id="itemPreview" src="<?= $editItem && $editItem['image'] ? BASE_URL . '/assets/uploads/items/' . e($editItem['image']) : '' ?>" class="w-full h-full object-cover <?= !($editItem && $editItem['image']) ? 'hidden' : '' ?>">
                        <div id="itemPreviewEmpty" class="w-full h-full flex flex-col items-center justify-center gap-2 text-gray-400 <?= $editItem && $editItem['image'] ? 'hidden' : '' ?>">
                            <svg class="w-12 h-12" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
                            </svg>
                            <span class="text-sm font-semibold">اضغط لرفع صورة</span>
                            <span class="text-xs">JPG, PNG, WEBP - حد أقصى 5MB</span>
                        </div>
                    </label>
                </div>
            <?php endif; ?>

            <!-- Fields -->
            <div class="<?= canDo($r, 'multiple_media') ? 'md:col-span-3' : 'md:col-span-2' ?> space-y-4">
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-2">اسم <?= e($labelItem) ?></label>
                    <input type="text" name="name" value="<?= e($editItem['name'] ?? '') ?>" required class="w-full px-4 py-3 rounded-xl border-2 border-gray-100 focus:border-emerald-500 transition">
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-2"><?= e($labelCat) ?></label>
                    <?php $preselectCat = (int) ($editItem['category_id'] ?? ($_GET['category'] ?? 0)); ?>
                    <select name="category_id" required class="w-full px-4 py-3 rounded-xl border-2 border-gray-100 focus:border-emerald-500 transition">
                        <option value="">— اختر —</option>
                        <?php foreach ($categories as $c): ?>
                            <option value="<?= $c['id'] ?>" <?= $preselectCat == $c['id'] ? 'selected' : '' ?>><?= e($c['icon']) ?> <?= e($c['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-2">الوصف</label>
                    <textarea name="description" rows="3" class="w-full px-4 py-3 rounded-xl border-2 border-gray-100 focus:border-emerald-500 transition"><?= e($editItem['description'] ?? '') ?></textarea>
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">السعر</label>
                        <input type="number" step="0.01" name="price" value="<?= e($editItem['price'] ?? '') ?>" required class="w-full px-4 py-3 rounded-xl border-2 border-gray-100 focus:border-emerald-500 transition">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">السعر القديم <span class="text-gray-400 font-normal">(للخصم)</span></label>
                        <input type="number" step="0.01" name="old_price" value="<?= e($editItem['old_price'] ?? '') ?>" class="w-full px-4 py-3 rounded-xl border-2 border-gray-100 focus:border-emerald-500 transition">
                    </div>
                </div>
                <div class="flex gap-6">
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input type="checkbox" name="is_available" <?= !$editItem || $editItem['is_available'] ? 'checked' : '' ?> class="w-5 h-5 rounded text-emerald-600 focus:ring-emerald-500">
                        <span class="font-semibold">متوفر</span>
                    </label>
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input type="checkbox" name="is_featured" <?= $editItem && $editItem['is_featured'] ? 'checked' : '' ?> class="w-5 h-5 rounded text-amber-500 focus:ring-amber-400">
                        <span class="font-semibold">⭐ <?= e($labelItem) ?> مميز</span>
                    </label>
                </div>
            </div>

            <!-- ===== Sector-specific fields (from business_types.fields_schema) ===== -->
            <?php
            $__schema = bizFieldsSchema($r);
            $__currentSpecs = $editItem ? itemSpecs($editItem) : [];
            if (!empty($__schema['fields'])):
            ?>
                <div class="md:col-span-3 border-t border-gray-100 pt-6">
                    <div class="mb-4">
                        <h3 class="font-bold text-gray-900 flex items-center gap-2">
                            <svg class="w-5 h-5 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"/>
                            </svg>
                            مواصفات <?= e($labelItem) ?>
                        </h3>
                        <p class="text-xs text-gray-500 mt-1">تفاصيل خاصة بنوع نشاطك — ستظهر للزبون في صفحة <?= e($labelItem) ?></p>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <?php foreach ($__schema['fields'] as $__f):
                            $__val = $__currentSpecs[$__f['key'] ?? ''] ?? null;
                            echo renderSpecField($__f, $__val);
                        endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- ===== Multi-Media Gallery (MAX plan only) ===== -->
            <?php if (canDo($r, 'multiple_media')): ?>
                <div class="md:col-span-3 border-t border-gray-100 pt-6">
                    <div class="flex items-center justify-between mb-4">
                        <div>
                            <h3 class="font-bold text-gray-900 flex items-center gap-2">
                                <span class="inline-flex items-center gap-1 px-2 py-0.5 bg-gradient-to-r from-amber-500 to-orange-500 text-white text-xs font-bold rounded-full">MAX</span>
                                معرض الصور والفيديوهات
                            </h3>
                            <?php
                            // Report effective per-request budget so user knows what they can fit per save
                            $postMaxRaw2 = ini_get('post_max_size');
                            $u2 = strtolower(substr($postMaxRaw2, -1));
                            $pmb = (int) $postMaxRaw2;
                            if ($u2 === 'g') $pmb *= 1024 * 1024 * 1024;
                            elseif ($u2 === 'm') $pmb *= 1024 * 1024;
                            elseif ($u2 === 'k') $pmb *= 1024;
                            $budgetMB = max(0, ($pmb - 262144) / 1048576);
                            ?>
                            <p class="text-xs text-gray-500 mt-1">أضف صوراً وفيديوهات متعددة لعرضها في صفحة <?= e($labelItem) ?> — حد أقصى <strong>5 ميغا</strong> لكل ملف، وإجمالي <strong><?= number_format($budgetMB, 1) ?> ميغا</strong> لكل عملية حفظ. الملفات الكبيرة؟ ارفعها على دفعات.</p>
                        </div>
                    </div>

                    <?php if ($editItem && $editItemMedia): ?>
                        <!-- Existing media gallery (delete/cover via AJAX to avoid nested <form> — browsers auto-close the outer form when they see a nested one, which would silently break the new media upload) -->
                        <div class="flex items-center justify-between mb-2">
                            <p class="text-xs text-gray-600">انقر على النجمة ⭐ لتعيين صورة الغلاف التي تظهر في القائمة العامة</p>
                        </div>
                        <div id="existingMediaGrid" class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-3 mb-4">
                            <?php foreach ($editItemMedia as $m): ?>
                                <?php $isCover = (int) $m['is_cover'] === 1;
                                $isImage = $m['media_type'] === 'image'; ?>
                                <div class="relative group rounded-xl overflow-hidden bg-gray-50 aspect-square <?= $isCover ? 'ring-2 ring-amber-400' : '' ?>" data-media-id="<?= (int) $m['id'] ?>" data-media-type="<?= e($m['media_type']) ?>">
                                    <?php if ($m['media_type'] === 'video'): ?>
                                        <video src="<?= BASE_URL ?>/assets/uploads/media/<?= e($m['file_path']) ?>" class="w-full h-full object-cover" muted preload="metadata"></video>
                                        <div class="absolute inset-0 flex items-center justify-center pointer-events-none">
                                            <div class="w-10 h-10 rounded-full bg-black/60 flex items-center justify-center">
                                                <svg class="w-5 h-5 text-white" fill="currentColor" viewBox="0 0 20 20">
                                                    <path d="M6.3 2.8A1 1 0 005 3.6v12.8a1 1 0 001.5.8l10.4-6.4a1 1 0 000-1.6L6.5 2.8z" />
                                                </svg>
                                            </div>
                                        </div>
                                        <span class="absolute top-1 right-1 px-2 py-0.5 rounded-full bg-black/70 text-white text-[10px] font-bold">فيديو</span>
                                    <?php else: ?>
                                        <img src="<?= BASE_URL ?>/assets/uploads/media/<?= e($m['file_path']) ?>" class="w-full h-full object-cover" alt="">
                                    <?php endif; ?>

                                    <!-- Cover badge (only shown on current cover image) -->
                                    <span class="cover-badge absolute bottom-1 right-1 px-2 py-0.5 rounded-full bg-gradient-to-r from-amber-500 to-orange-500 text-white text-[10px] font-bold flex items-center gap-1 shadow-lg <?= $isCover ? '' : 'hidden' ?>">
                                        <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20">
                                            <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.286 3.957a1 1 0 00.95.69h4.162c.969 0 1.371 1.24.588 1.81l-3.37 2.447a1 1 0 00-.364 1.118l1.287 3.957c.3.921-.755 1.683-1.54 1.118l-3.37-2.446a1 1 0 00-1.175 0l-3.37 2.446c-.784.566-1.838-.197-1.539-1.118l1.287-3.957a1 1 0 00-.364-1.118L2.05 9.384c-.783-.57-.38-1.81.588-1.81h4.162a1 1 0 00.95-.69l1.286-3.957z" />
                                        </svg>
                                        غلاف
                                    </span>

                                    <!-- Set as cover button (images only — videos can't be thumbnails on menu cards) -->
                                    <?php if ($isImage): ?>
                                        <button type="button" onclick="setCoverMedia(<?= (int) $m['id'] ?>, this)" class="cover-btn absolute top-1 right-1 w-7 h-7 rounded-full <?= $isCover ? 'bg-amber-500 hover:bg-amber-600' : 'bg-black/60 hover:bg-amber-500' ?> text-white flex items-center justify-center shadow-lg transition" title="<?= $isCover ? 'صورة الغلاف الحالية' : 'تعيين كغلاف' ?>" data-is-cover="<?= $isCover ? '1' : '0' ?>">
                                            <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20">
                                                <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.286 3.957a1 1 0 00.95.69h4.162c.969 0 1.371 1.24.588 1.81l-3.37 2.447a1 1 0 00-.364 1.118l1.287 3.957c.3.921-.755 1.683-1.54 1.118l-3.37-2.446a1 1 0 00-1.175 0l-3.37 2.446c-.784.566-1.838-.197-1.539-1.118l1.287-3.957a1 1 0 00-.364-1.118L2.05 9.384c-.783-.57-.38-1.81.588-1.81h4.162a1 1 0 00.95-.69l1.286-3.957z" />
                                            </svg>
                                        </button>
                                    <?php endif; ?>

                                    <button type="button" onclick="deleteMedia(<?= (int) $m['id'] ?>, <?= (int) $editItem['id'] ?>, this)" class="absolute top-1 left-1 w-7 h-7 rounded-full bg-red-500 hover:bg-red-600 text-white flex items-center justify-center shadow-lg transition" title="حذف">
                                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                                        </svg>
                                    </button>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <!-- Upload new media -->
                    <label class="block rounded-2xl border-2 border-dashed border-gray-200 hover:border-amber-500 bg-gradient-to-br from-amber-50/30 to-orange-50/30 cursor-pointer transition p-6 text-center">
                        <input type="file" name="media[]" id="mediaInput" accept="image/jpeg,image/png,image/webp,image/gif,video/mp4,video/webm,video/quicktime" multiple class="hidden" onchange="previewMedia(this)">
                        <div id="mediaDropPrompt" class="text-gray-500">
                            <svg class="w-10 h-10 mx-auto text-gray-400 mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
                            </svg>
                            <div class="font-semibold text-gray-700">اختر صوراً و/أو فيديوهات</div>
                            <div class="text-xs mt-1">JPG · PNG · WEBP · GIF · MP4 · WEBM · MOV — حتى 5 ميغا لكل ملف</div>
                        </div>
                        <div id="mediaPreviewGrid" class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-2 text-right hidden"></div>
                    </label>
                    <div id="mediaErrorBox" class="hidden mt-3 p-3 bg-red-50 border border-red-200 text-red-700 text-sm rounded-xl"></div>
                </div>
            <?php endif; ?>

            <!-- Submit row -->
            <div class="md:col-span-3 flex gap-3 pt-4 border-t border-gray-100">
                <button type="submit" class="px-8 py-3 rounded-xl bg-gradient-to-r from-emerald-600 to-teal-600 text-white font-bold shadow-lg shadow-emerald-500/30 hover:shadow-xl transition">حفظ</button>
                <a href="items.php" class="px-6 py-3 rounded-xl text-gray-700 hover:bg-gray-100 font-semibold flex items-center">إلغاء</a>
            </div>
        </form>
    </div>
    <script>
        function previewItem(input) {
            if (input.files && input.files[0]) {
                const reader = new FileReader();
                reader.onload = e => {
                    const img = document.getElementById('itemPreview');
                    img.src = e.target.result;
                    img.classList.remove('hidden');
                    document.getElementById('itemPreviewEmpty').classList.add('hidden');
                };
                reader.readAsDataURL(input.files[0]);
            }
        }

        <?php if (canDo($r, 'multiple_media')):
            // Compute effective POST size budget. PHP's post_max_size caps the TOTAL
            // request body; exceeding it truncates the upload silently with no error.
            // We expose it to JS so we can refuse the submit before wasting the user's upload.
            $postMaxRaw = ini_get('post_max_size');
            $unit = strtolower(substr($postMaxRaw, -1));
            $postMaxBytes = (int) $postMaxRaw;
            if ($unit === 'g') $postMaxBytes *= 1024 * 1024 * 1024;
            elseif ($unit === 'm') $postMaxBytes *= 1024 * 1024;
            elseif ($unit === 'k') $postMaxBytes *= 1024;
            // Reserve 256KB for the form fields + overhead
            $postMaxBudget = max(0, $postMaxBytes - 262144);
        ?>
            // -------- Delete / set-cover for existing media via AJAX --------
            // IMPORTANT: Must use AJAX (not a nested <form>) because nested forms are invalid HTML —
            // browsers auto-close the outer form at the first inner <form>, which would break the
            // multi-media upload on the outer itemForm (the new media[] input would become orphan).
            const CSRF_TOKEN = <?= json_encode(csrfToken()) ?>;

            async function postAction(payload) {
                const fd = new FormData();
                fd.append('csrf', CSRF_TOKEN);
                fd.append('ajax', '1');
                Object.entries(payload).forEach(([k, v]) => fd.append(k, v));
                const res = await fetch(window.location.pathname, {
                    method: 'POST',
                    body: fd,
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    credentials: 'same-origin'
                });
                return res.json();
            }

            async function deleteMedia(mediaId, itemId, btn) {
                if (!confirm('حذف هذا الملف؟')) return;
                btn.disabled = true;
                btn.style.opacity = '0.5';
                try {
                    const data = await postAction({
                        action: 'delete_media',
                        media_id: mediaId,
                        item_id: itemId
                    });
                    if (data.ok) {
                        const cell = btn.closest('[data-media-id]');
                        const wasCover = cell && cell.querySelector('.cover-btn[data-is-cover="1"]');
                        if (cell) cell.remove();
                        // If gallery is now empty, remove the grid entirely
                        const grid = document.getElementById('existingMediaGrid');
                        if (grid && !grid.querySelector('[data-media-id]')) grid.remove();
                        // If deleted tile was the cover, the server auto-promoted the next image —
                        // update the UI by querying for the first remaining image and marking it visually.
                        if (wasCover && grid) {
                            const firstImageCell = Array.from(grid.querySelectorAll('[data-media-id]'))
                                .find(el => el.getAttribute('data-media-type') === 'image');
                            if (firstImageCell) markAsCover(firstImageCell);
                        }
                    } else {
                        alert('تعذّر حذف الملف');
                        btn.disabled = false;
                        btn.style.opacity = '1';
                    }
                } catch (err) {
                    alert('خطأ في الاتصال');
                    btn.disabled = false;
                    btn.style.opacity = '1';
                }
            }

            function markAsCover(cell) {
                // Clear all existing cover visuals in the grid, then apply to the new cell.
                const grid = document.getElementById('existingMediaGrid');
                if (!grid) return;
                grid.querySelectorAll('[data-media-id]').forEach(el => {
                    el.classList.remove('ring-2', 'ring-amber-400');
                    const badge = el.querySelector('.cover-badge');
                    if (badge) badge.classList.add('hidden');
                    const btn = el.querySelector('.cover-btn');
                    if (btn) {
                        btn.classList.remove('bg-amber-500', 'hover:bg-amber-600');
                        btn.classList.add('bg-black/60', 'hover:bg-amber-500');
                        btn.setAttribute('data-is-cover', '0');
                        btn.setAttribute('title', 'تعيين كغلاف');
                    }
                });
                cell.classList.add('ring-2', 'ring-amber-400');
                const badge = cell.querySelector('.cover-badge');
                if (badge) badge.classList.remove('hidden');
                const btn = cell.querySelector('.cover-btn');
                if (btn) {
                    btn.classList.remove('bg-black/60', 'hover:bg-amber-500');
                    btn.classList.add('bg-amber-500', 'hover:bg-amber-600');
                    btn.setAttribute('data-is-cover', '1');
                    btn.setAttribute('title', 'صورة الغلاف الحالية');
                }
            }

            async function setCoverMedia(mediaId, btn) {
                const cell = btn.closest('[data-media-id]');
                if (!cell) return;
                // Idempotent: clicking the current cover does nothing (saves a roundtrip)
                if (btn.getAttribute('data-is-cover') === '1') return;
                btn.disabled = true;
                const originalOpacity = btn.style.opacity;
                btn.style.opacity = '0.5';
                try {
                    const data = await postAction({
                        action: 'set_cover',
                        media_id: mediaId
                    });
                    if (data.ok) {
                        markAsCover(cell);
                    } else {
                        alert('تعذّر تعيين الغلاف');
                    }
                } catch (err) {
                    alert('خطأ في الاتصال');
                } finally {
                    btn.disabled = false;
                    btn.style.opacity = originalOpacity || '1';
                }
            }

            // -------- Multi-media client-side validation + preview --------
            // Must match the server-side whitelist exactly (security.php::secure_upload_media_file).
            const MEDIA_MAX_BYTES = 5 * 1024 * 1024;
            const MEDIA_POST_BUDGET = <?= (int) $postMaxBudget ?>; // total POST body budget for this request
            const MEDIA_ALLOWED_MIMES = new Set([
                'image/jpeg', 'image/png', 'image/webp', 'image/gif',
                'video/mp4', 'video/webm', 'video/quicktime'
            ]);
            const MEDIA_ALLOWED_EXTS = new Set([
                'jpg', 'jpeg', 'png', 'webp', 'gif',
                'mp4', 'webm', 'mov'
            ]);
            const MEDIA_MAX_COUNT = 12; // sane cap so browsers don't OOM

            function showMediaError(msg) {
                const box = document.getElementById('mediaErrorBox');
                box.textContent = msg;
                box.classList.remove('hidden');
            }

            function clearMediaError() {
                document.getElementById('mediaErrorBox').classList.add('hidden');
            }

            function previewMedia(input) {
                clearMediaError();
                const grid = document.getElementById('mediaPreviewGrid');
                const prompt = document.getElementById('mediaDropPrompt');
                grid.innerHTML = '';

                if (!input.files || !input.files.length) {
                    prompt.classList.remove('hidden');
                    grid.classList.add('hidden');
                    return;
                }

                if (input.files.length > MEDIA_MAX_COUNT) {
                    showMediaError('لا يمكن رفع أكثر من ' + MEDIA_MAX_COUNT + ' ملف في نفس المرة.');
                    input.value = '';
                    prompt.classList.remove('hidden');
                    grid.classList.add('hidden');
                    return;
                }

                const errors = [];
                const previews = [];
                let totalSize = 0;

                // Include primary image in the budget if user also picked one
                const mainImageInput = document.querySelector('input[name="image"]');
                if (mainImageInput && mainImageInput.files && mainImageInput.files[0]) {
                    totalSize += mainImageInput.files[0].size;
                }

                Array.from(input.files).forEach((f, idx) => {
                    const ext = (f.name.split('.').pop() || '').toLowerCase();
                    const mime = f.type || '';
                    if (!MEDIA_ALLOWED_EXTS.has(ext) || !MEDIA_ALLOWED_MIMES.has(mime)) {
                        errors.push('"' + f.name + '" نوع غير مسموح');
                        return;
                    }
                    if (f.size > MEDIA_MAX_BYTES) {
                        errors.push('"' + f.name + '" أكبر من 5 ميغا');
                        return;
                    }
                    if (f.size <= 0) {
                        errors.push('"' + f.name + '" ملف فارغ');
                        return;
                    }
                    totalSize += f.size;
                    previews.push(f);
                });

                // Guard against exceeding the PHP post_max_size — silent truncation by PHP
                if (MEDIA_POST_BUDGET > 0 && totalSize > MEDIA_POST_BUDGET) {
                    const budgetMB = (MEDIA_POST_BUDGET / 1024 / 1024).toFixed(1);
                    const totalMB = (totalSize / 1024 / 1024).toFixed(1);
                    errors.push('الحجم الإجمالي ' + totalMB + ' م.ب يتجاوز الحد المسموح للرفع دفعة واحدة (' + budgetMB + ' م.ب). ارفع الملفات على دفعات أصغر.');
                }

                if (errors.length) {
                    showMediaError('ملفات مرفوضة: ' + errors.join(' · '));
                    // Do not clear input fully — browsers don't allow partial removal.
                    // Instead, if ANY file is rejected, clear everything so user re-picks.
                    input.value = '';
                    prompt.classList.remove('hidden');
                    grid.classList.add('hidden');
                    return;
                }

                // Render preview tiles
                previews.forEach(f => {
                    const cell = document.createElement('div');
                    cell.className = 'relative rounded-lg overflow-hidden bg-gray-100 aspect-square';
                    const isVideo = f.type.startsWith('video/');
                    const url = URL.createObjectURL(f);
                    if (isVideo) {
                        cell.innerHTML =
                            '<video src="' + url + '" class="w-full h-full object-cover" muted preload="metadata"></video>' +
                            '<span class="absolute top-1 right-1 px-1.5 py-0.5 rounded-full bg-black/70 text-white text-[9px] font-bold">فيديو</span>';
                    } else {
                        const img = document.createElement('img');
                        img.src = url;
                        img.className = 'w-full h-full object-cover';
                        cell.appendChild(img);
                    }
                    const sizeLabel = document.createElement('div');
                    sizeLabel.className = 'absolute bottom-0 right-0 left-0 bg-gradient-to-t from-black/70 to-transparent text-white text-[10px] px-2 py-1 font-semibold';
                    sizeLabel.textContent = (f.size / 1024 / 1024).toFixed(1) + ' MB';
                    cell.appendChild(sizeLabel);
                    grid.appendChild(cell);
                });

                prompt.classList.add('hidden');
                grid.classList.remove('hidden');
            }

            // Final safety net on submit
            document.getElementById('itemForm').addEventListener('submit', function(e) {
                const input = document.getElementById('mediaInput');
                if (!input || !input.files.length) return;
                let total = 0;
                const mainImg = document.querySelector('input[name="image"]');
                if (mainImg && mainImg.files && mainImg.files[0]) total += mainImg.files[0].size;
                for (const f of input.files) {
                    if (f.size > MEDIA_MAX_BYTES) {
                        e.preventDefault();
                        showMediaError('حجم "' + f.name + '" يتجاوز 5 ميغا');
                        return false;
                    }
                    total += f.size;
                }
                if (MEDIA_POST_BUDGET > 0 && total > MEDIA_POST_BUDGET) {
                    e.preventDefault();
                    const budgetMB = (MEDIA_POST_BUDGET / 1024 / 1024).toFixed(1);
                    showMediaError('الحجم الإجمالي يتجاوز ' + budgetMB + ' م.ب — قسّم الملفات على دفعات.');
                    return false;
                }
            });
        <?php endif; ?>
    </script>

<?php else: ?>

    <!-- List -->
    <?php
    $totalItems = countResource($pdo, $rid, 'items');
    $maxItems = (int) $r['max_items'];
    $itemsLimited = $maxItems !== -1;
    $canAddItem = !$itemsLimited || $totalItems < $maxItems;
    ?>
    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4 mb-6">
        <div>
            <h2 class="text-xl font-bold"><?= e($labelItems) ?></h2>
            <p class="text-sm text-gray-500 mt-1">
                <?= $itemsLimited ? "$totalItems / $maxItems " . e($labelItem) : "$totalItems " . e($labelItem) . " (غير محدود)" ?>
                <?php if ($itemsLimited && !$canAddItem): ?>
                    — <a href="upgrade.php" class="text-emerald-600 font-bold">ترقّ لإضافة المزيد</a>
                <?php endif; ?>
            </p>
        </div>
        <?php if ($canAddItem): ?>
            <a href="items.php?new=1" class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl bg-gradient-to-r from-emerald-600 to-teal-600 text-white font-bold shadow-lg shadow-emerald-500/30 hover:shadow-xl transition">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
                </svg>
                <?= e($labelItem) ?> جديد
            </a>
        <?php else: ?>
            <a href="upgrade.php" class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl bg-gradient-to-r from-amber-500 to-orange-500 text-white font-bold shadow-lg transition">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" />
                </svg>
                ترقية لإضافة المزيد
            </a>
        <?php endif; ?>
    </div>

    <!-- Filters -->
    <div class="bg-white rounded-2xl shadow-soft p-4 mb-6 flex flex-col md:flex-row gap-3">
        <form method="GET" class="flex-1 flex gap-2">
            <input type="hidden" name="cat" value="<?= $filterCat ?>">
            <div class="flex-1 relative">
                <svg class="absolute right-3 top-1/2 -translate-y-1/2 w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                </svg>
                <input type="text" name="q" value="<?= e($search) ?>" placeholder="ابحث عن <?= e($labelItem) ?>..." class="w-full pr-10 pl-4 py-2.5 rounded-xl border-2 border-gray-100 focus:border-emerald-500 transition">
            </div>
            <button class="px-5 py-2.5 rounded-xl bg-gray-900 text-white font-semibold">بحث</button>
        </form>
        <div class="flex gap-2 overflow-x-auto scrollbar-hide">
            <a href="?" class="px-4 py-2.5 rounded-xl <?= !$filterCat ? 'bg-emerald-600 text-white' : 'bg-gray-100 hover:bg-gray-200' ?> font-semibold whitespace-nowrap transition">الكل</a>
            <?php foreach ($categories as $c): ?>
                <a href="?cat=<?= $c['id'] ?>" class="px-4 py-2.5 rounded-xl <?= $filterCat == $c['id'] ? 'bg-emerald-600 text-white' : 'bg-gray-100 hover:bg-gray-200' ?> font-semibold whitespace-nowrap transition inline-flex items-center gap-1.5">
                    <?= renderCategoryIcon($c['icon'], $r['biz_code'] ?? 'restaurant', 'w-5 h-5', $c['name']) ?>
                    <span><?= e($c['name']) ?></span>
                </a>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Items Grid -->
    <?php if (!$items): ?>
        <div class="bg-white rounded-2xl shadow-soft p-12 text-center">
            <p class="text-gray-500">لا توجد <?= e($labelItems) ?>. <a href="items.php?new=1" class="text-emerald-600 font-bold">أضف أول <?= e($labelItem) ?></a></p>
        </div>
    <?php else: ?>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4">
            <?php foreach ($items as $item): ?>
                <div class="bg-white rounded-2xl shadow-soft overflow-hidden group hover:shadow-card transition">
                    <div class="aspect-square bg-gray-50 relative overflow-hidden">
                        <?php if (!empty($item['thumb_url'])): ?>
                            <img src="<?= e($item['thumb_url']) ?>" class="w-full h-full object-cover group-hover:scale-105 transition duration-500">
                        <?php else: ?>
                            <div class="w-full h-full flex items-center justify-center text-gray-300">
                                <svg class="w-16 h-16" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
                                </svg>
                            </div>
                        <?php endif; ?>

                        <div class="absolute top-2 right-2 flex flex-col gap-1">
                            <?php if (!$item['is_available']): ?><span class="bg-red-500 text-white text-xs px-2 py-1 rounded-full">غير متوفر</span><?php endif; ?>
                            <?php if ($item['is_featured']): ?><span class="bg-amber-500 text-white text-xs px-2 py-1 rounded-full">⭐ مميز</span><?php endif; ?>
                            <?php if ($item['old_price'] && $item['old_price'] > $item['price']): ?>
                                <span class="bg-rose-500 text-white text-xs px-2 py-1 rounded-full">خصم</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="p-4">
                        <div class="flex items-start justify-between gap-2 mb-1">
                            <h3 class="font-bold text-gray-900 truncate flex-1"><?= e($item['name']) ?></h3>
                        </div>
                        <p class="text-xs text-gray-400 mb-2"><?= e($item['category_name']) ?></p>
                        <?php if ($item['description']): ?>
                            <p class="text-sm text-gray-600 line-clamp-2 mb-3"><?= e($item['description']) ?></p>
                        <?php endif; ?>
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-emerald-600 font-bold text-lg"><?= formatPrice($item['price'], $r['currency']) ?></p>
                                <?php if ($item['old_price'] && $item['old_price'] > $item['price']): ?>
                                    <p class="text-xs text-gray-400 line-through"><?= formatPrice($item['old_price'], $r['currency']) ?></p>
                                <?php endif; ?>
                            </div>
                            <div class="flex gap-1">
                                <form method="POST" class="inline">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="toggle">
                                    <input type="hidden" name="field" value="is_available">
                                    <input type="hidden" name="id" value="<?= $item['id'] ?>">
                                    <?php if ($filterCat): ?><input type="hidden" name="cat" value="<?= $filterCat ?>"><?php endif; ?>
                                    <button type="submit" class="p-2 rounded-lg hover:bg-gray-100 transition" title="<?= $item['is_available'] ? 'إخفاء' : 'إظهار' ?>">
                                        <?php if ($item['is_available']): ?>
                                            <svg class="w-4 h-4 text-emerald-600" fill="currentColor" viewBox="0 0 20 20">
                                                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
                                            </svg>
                                        <?php else: ?>
                                            <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.542-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.542 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21" />
                                            </svg>
                                        <?php endif; ?>
                                    </button>
                                </form>
                                <a href="items.php?edit=<?= $item['id'] ?>" class="p-2 rounded-lg hover:bg-gray-100 transition" title="تعديل">
                                    <svg class="w-4 h-4 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                    </svg>
                                </a>
                                <form method="POST" class="inline" onsubmit="return confirm('حذف هذا <?= e($labelItem) ?>؟')">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= $item['id'] ?>">
                                    <button type="submit" class="p-2 rounded-lg hover:bg-red-50 transition" title="حذف">
                                        <svg class="w-4 h-4 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                        </svg>
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer_admin.php'; ?>