<style>
.variation-row td { vertical-align: middle; }
.variation-row.editing input,
.variation-row.editing select { font-size: 0.875rem; }
.variation-actions button { margin: 0 2px; }
.cb-dropdown-container { position: relative; display: inline-block; width: 100%; }
.cb-dropdown-panel {
    position: absolute; z-index: 100; top: 100%; left: 0; right: 0;
    max-height: 200px; overflow-y: auto;
    background: #fff; border: 1px solid #d1d5db; border-radius: 0.5rem;
    box-shadow: 0 4px 12px rgba(0,0,0,0.12); display: none;
}
.cb-dropdown-panel.open { display: block; }
.cb-dropdown-panel label { display: flex; align-items: center; padding: 4px 10px; cursor: pointer; font-size: 0.85rem; }
.cb-dropdown-panel label:hover { background: #f3f4f6; }
</style>

<div class="main-content">
  <div class="page-content">
    <div class="container-fluid">

      <!-- Page Header -->
      <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
        <div>
          <h5 class="card-title mb-0 text-black"><?php echo htmlspecialchars($title); ?></h5>
          <p class="text-muted small mb-0">Manage menu items and their dietary variations</p>
        </div>
        <div>
          <a class="btn btn-outline-secondary btn-sm" href="<?php echo site_url('Orderportal/Configfoodmenu/menus'); ?>">
            <i class="ri-arrow-left-line me-1"></i> Back to Menus
          </a>
        </div>
      </div>

      <!-- Menu Item Selector -->
      <div class="card mb-4">
        <div class="card-body">
          <div class="row align-items-end">
            <div class="col-md-5">
              <label class="form-label fw-semibold">Select Menu Item</label>
              <select id="menuItemSelect" class="form-select">
                <option value="">-- Choose a Menu Item --</option>
                <?php foreach ($menuItems as $mi): ?>
                  <option value="<?php echo (int)$mi['id']; ?>"><?php echo htmlspecialchars($mi['name']); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-3 mt-2 mt-md-0">
              <button type="button" id="btnAddVariation" class="btn btn-success" disabled>
                <i class="ri-add-line me-1"></i> Add Variation
              </button>
            </div>
          </div>
        </div>
      </div>

      <!-- Variations Table -->
      <div id="variationsCard" class="card" style="display:none;">
        <div class="card-header bg-light d-flex align-items-center justify-content-between">
          <h6 class="mb-0" id="variationsHeading">Variations</h6>
        </div>
        <div class="card-body p-0">
          <div class="table-responsive">
            <table class="table table-sm table-hover mb-0" id="variationsTable">
              <thead class="table-light">
                <tr>
                  <th style="width:22%">Variation Name</th>
                  <th style="width:22%">Ingredients / Description</th>
                  <th style="width:18%">Nutritional Values</th>
                  <th style="width:22%">Allergens</th>
                  <th style="width:16%" class="text-center">Actions</th>
                </tr>
              </thead>
              <tbody id="variationsBody">
                <tr class="no-variations-row">
                  <td colspan="5" class="text-center text-muted py-3">Select a menu item above.</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>

    </div>
  </div>
</div>

<script>
const BASE_URL = '<?php echo base_url(); ?>';
const ALL_CUISINES = <?php echo json_encode($cuisines); ?>;
const ALL_ALLERGENS = <?php echo json_encode($allergies); ?>;

let selectedMenuId = null;

// ─── Menu Item dropdown change ──────────────────────────────────
document.getElementById('menuItemSelect').addEventListener('change', function() {
    selectedMenuId = this.value ? parseInt(this.value) : null;
    document.getElementById('btnAddVariation').disabled = !selectedMenuId;

    if (selectedMenuId) {
        const menuName = this.options[this.selectedIndex].text;
        document.getElementById('variationsHeading').textContent = 'Variations for: ' + menuName;
        document.getElementById('variationsCard').style.display = '';
        loadVariations(selectedMenuId);
    } else {
        document.getElementById('variationsCard').style.display = 'none';
    }
});

// ─── Add Variation button ───────────────────────────────────────
document.getElementById('btnAddVariation').addEventListener('click', function() {
    if (!selectedMenuId) return;
    addVariationRow();
});

// ─── Load variations via AJAX ───────────────────────────────────
function loadVariations(menuDetailId) {
    const tbody = document.getElementById('variationsBody');
    tbody.innerHTML = '<tr><td colspan="5" class="text-center py-3"><i class="ri-loader-4-line ri-spin me-1"></i> Loading...</td></tr>';

    const formData = new FormData();
    formData.append('menu_detail_id', menuDetailId);

    fetch(BASE_URL + 'Orderportal/Configfoodmenu/get_variations', { method: 'POST', body: formData })
    .then(r => r.json())
    .then(data => {
        tbody.innerHTML = '';
        if (data.success && data.variations && data.variations.length) {
            data.variations.forEach(v => {
                tbody.appendChild(buildStaticRow(v));
            });
        } else {
            tbody.innerHTML = '<tr class="no-variations-row"><td colspan="5" class="text-center text-muted py-3">No variations yet. Click "+ Add Variation" to create one.</td></tr>';
        }
    })
    .catch(() => {
        tbody.innerHTML = '<tr><td colspan="5" class="text-center text-danger py-3">Failed to load variations.</td></tr>';
    });
}

// ─── Build a static (read-only) row from variation data ─────────
function buildStaticRow(v) {
    const tr = document.createElement('tr');
    tr.className = 'variation-row';
    tr.dataset.id = v.id;
    tr.dataset.menuId = v.menu_detail_id;

    const cuisineIds = safeJsonParse(v.cuisine_type_ids);
    const allergenIds = safeJsonParse(v.allergenValues);

    tr.innerHTML =
        '<td class="v-cuisine" data-cuisine-ids=\'' + escapeAttr(v.cuisine_type_ids || '[]') + '\'>' + idsToNames(cuisineIds, ALL_CUISINES) + '</td>' +
        '<td class="v-desc">' + escapeHtml(v.description || '') + '</td>' +
        '<td class="v-nutrition">' + escapeHtml(v.nutritional_values || '') + '</td>' +
        '<td class="v-allergens" data-allergen-ids=\'' + escapeAttr(v.allergenValues || '[]') + '\'>' + idsToNames(allergenIds, ALL_ALLERGENS) + '</td>' +
        '<td class="text-center variation-actions">' +
            '<button class="btn btn-sm btn-outline-primary" onclick="editVariation(this)" title="Edit"><i class="ri-pencil-line"></i></button> ' +
            '<button class="btn btn-sm btn-outline-danger" onclick="deleteVariation(' + v.id + ', this)" title="Delete"><i class="ri-delete-bin-line"></i></button>' +
        '</td>';

    return tr;
}

// ─── Build a checkbox-dropdown widget ───────────────────────────
function buildCbDropdown(items, selectedIds, cssClass) {
    selectedIds = (selectedIds || []).map(String);

    const wrapper = document.createElement('div');
    wrapper.className = 'cb-dropdown-container ' + (cssClass || '');

    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'btn btn-sm btn-outline-secondary w-100 text-start text-truncate';
    btn.textContent = selectedIds.length ? selectedIds.length + ' selected' : 'Select...';
    wrapper.appendChild(btn);

    const panel = document.createElement('div');
    panel.className = 'cb-dropdown-panel';
    items.forEach(item => {
        const lbl = document.createElement('label');
        lbl.innerHTML = '<input type="checkbox" class="form-check-input me-2 cb-item" value="' + item.id + '"' +
            (selectedIds.includes(String(item.id)) ? ' checked' : '') + '> <span>' + escapeHtml(item.name) + '</span>';
        panel.appendChild(lbl);
    });
    wrapper.appendChild(panel);

    btn.addEventListener('click', function(e) {
        e.stopPropagation();
        document.querySelectorAll('.cb-dropdown-panel.open').forEach(p => { if (p !== panel) p.classList.remove('open'); });
        panel.classList.toggle('open');
    });

    panel.addEventListener('change', function() {
        const checked = panel.querySelectorAll('.cb-item:checked');
        btn.textContent = checked.length ? checked.length + ' selected' : 'Select...';
    });

    return wrapper;
}

// Close dropdowns when clicking outside
document.addEventListener('click', function(e) {
    if (!e.target.closest('.cb-dropdown-container')) {
        document.querySelectorAll('.cb-dropdown-panel.open').forEach(p => p.classList.remove('open'));
    }
});

function getCheckedValues(widget) {
    return Array.from(widget.querySelectorAll('.cb-item:checked')).map(cb => cb.value);
}

// ─── ADD new variation row ──────────────────────────────────────
function addVariationRow() {
    const tbody = document.getElementById('variationsBody');
    const noRow = tbody.querySelector('.no-variations-row');
    if (noRow) noRow.remove();

    const tr = document.createElement('tr');
    tr.className = 'variation-row editing';
    tr.dataset.menuId = selectedMenuId;
    tr.dataset.id = '';

    tr.innerHTML =
        '<td class="v-cuisine-cell"></td>' +
        '<td><input type="text" class="form-control form-control-sm v-desc-input" maxlength="100" placeholder="Ingredients / Description"></td>' +
        '<td><input type="text" class="form-control form-control-sm v-nutrition-input" placeholder="Nutritional values"></td>' +
        '<td class="v-allergens-cell"></td>' +
        '<td class="text-center variation-actions">' +
            '<button class="btn btn-sm btn-success" onclick="saveVariationRow(this)" title="Save"><i class="ri-check-line"></i></button> ' +
            '<button class="btn btn-sm btn-outline-secondary" onclick="cancelVariationRow(this)" title="Cancel"><i class="ri-close-line"></i></button>' +
        '</td>';

    tr.querySelector('.v-cuisine-cell').appendChild(buildCbDropdown(ALL_CUISINES, [], 'cuisine-widget'));
    tr.querySelector('.v-allergens-cell').appendChild(buildCbDropdown(ALL_ALLERGENS, [], 'allergen-widget'));
    tbody.appendChild(tr);
}

// ─── EDIT existing row (inline) ─────────────────────────────────
function editVariation(btn) {
    const row = btn.closest('tr');
    if (row.classList.contains('editing')) return;

    const desc = row.querySelector('.v-desc').textContent.trim();
    const nutrition = row.querySelector('.v-nutrition').textContent.trim();
    const cuisineIds = safeJsonParse(row.querySelector('.v-cuisine').dataset.cuisineIds);
    const allergenIds = safeJsonParse(row.querySelector('.v-allergens').dataset.allergenIds);

    // Store original HTML for cancel
    row._original = row.innerHTML;

    row.classList.add('editing');

    row.innerHTML =
        '<td class="v-cuisine-cell"></td>' +
        '<td><input type="text" class="form-control form-control-sm v-desc-input" maxlength="100" value="' + escapeAttr(desc) + '"></td>' +
        '<td><input type="text" class="form-control form-control-sm v-nutrition-input" value="' + escapeAttr(nutrition) + '"></td>' +
        '<td class="v-allergens-cell"></td>' +
        '<td class="text-center variation-actions">' +
            '<button class="btn btn-sm btn-success" onclick="saveVariationRow(this)" title="Save"><i class="ri-check-line"></i></button> ' +
            '<button class="btn btn-sm btn-outline-secondary" onclick="cancelEditVariation(this)" title="Cancel"><i class="ri-close-line"></i></button>' +
        '</td>';

    row.querySelector('.v-cuisine-cell').appendChild(buildCbDropdown(ALL_CUISINES, cuisineIds, 'cuisine-widget'));
    row.querySelector('.v-allergens-cell').appendChild(buildCbDropdown(ALL_ALLERGENS, allergenIds, 'allergen-widget'));
}

// ─── CANCEL edit ────────────────────────────────────────────────
function cancelEditVariation(btn) {
    const row = btn.closest('tr');
    if (!row._original) return;
    row.innerHTML = row._original;
    row.classList.remove('editing');
    delete row._original;
}

// ─── CANCEL new row ─────────────────────────────────────────────
function cancelVariationRow(btn) {
    const row = btn.closest('tr');
    const tbody = row.closest('tbody');
    row.remove();
    if (!tbody.querySelector('.variation-row')) {
        tbody.innerHTML = '<tr class="no-variations-row"><td colspan="5" class="text-center text-muted py-3">No variations yet. Click "+ Add Variation" to create one.</td></tr>';
    }
}

// ─── SAVE variation (AJAX) ──────────────────────────────────────
function saveVariationRow(btn) {
    const row = btn.closest('tr');
    const cuisineWidget = row.querySelector('.cuisine-widget');
    const allergenWidget = row.querySelector('.allergen-widget');
    const descInput = row.querySelector('.v-desc-input');
    const nutritionInput = row.querySelector('.v-nutrition-input');

    const cuisineIds = getCheckedValues(cuisineWidget);
    if (!cuisineIds.length) {
        showToast('Please select at least one cuisine type.', 'warning');
        return;
    }

    const id = row.dataset.id || '';
    const menuDetailId = row.dataset.menuId;
    const allergenIds = getCheckedValues(allergenWidget);

    row.querySelectorAll('button').forEach(b => b.disabled = true);

    const formData = new FormData();
    formData.append('id', id);
    formData.append('menu_detail_id', menuDetailId);
    cuisineIds.forEach(cid => formData.append('cuisine_type_ids[]', cid));
    formData.append('description', descInput ? descInput.value.trim() : '');
    formData.append('nutritional_values', nutritionInput ? nutritionInput.value.trim() : '');
    allergenIds.forEach(aid => formData.append('allergenValues[]', aid));

    fetch(BASE_URL + 'Orderportal/Configfoodmenu/save_variation', { method: 'POST', body: formData })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            const newRow = buildStaticRow(data.variation);
            row.replaceWith(newRow);
            showToast('Variation saved successfully!', 'success');
        } else {
            showToast(data.message || 'Failed to save variation.', 'danger');
            row.querySelectorAll('button').forEach(b => b.disabled = false);
        }
    })
    .catch(() => {
        showToast('Network error. Please try again.', 'danger');
        row.querySelectorAll('button').forEach(b => b.disabled = false);
    });
}

// ─── DELETE variation ───────────────────────────────────────────
function deleteVariation(id, btn) {
    if (!confirm('Are you sure you want to delete this variation?')) return;

    const row = btn.closest('tr');
    const tbody = row.closest('tbody');

    const formData = new FormData();
    formData.append('id', id);

    fetch(BASE_URL + 'Orderportal/Configfoodmenu/delete_variation', { method: 'POST', body: formData })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            row.remove();
            if (!tbody.querySelector('.variation-row')) {
                tbody.innerHTML = '<tr class="no-variations-row"><td colspan="5" class="text-center text-muted py-3">No variations yet. Click "+ Add Variation" to create one.</td></tr>';
            }
            showToast('Variation deleted.', 'success');
        } else {
            showToast(data.message || 'Failed to delete.', 'danger');
        }
    })
    .catch(() => showToast('Network error.', 'danger'));
}

// ─── Helpers ────────────────────────────────────────────────────
function safeJsonParse(str) {
    try { const arr = JSON.parse(str); return Array.isArray(arr) ? arr : []; } catch(e) { return []; }
}

function idsToNames(ids, list) {
    if (!ids || !ids.length) return '<span class="text-muted">None</span>';
    const names = [];
    ids.forEach(id => {
        const item = list.find(x => String(x.id) === String(id));
        if (item) names.push(item.name);
    });
    return names.length ? escapeHtml(names.join(', ')) : '<span class="text-muted">None</span>';
}

function escapeHtml(str) {
    const div = document.createElement('div');
    div.appendChild(document.createTextNode(str || ''));
    return div.innerHTML;
}

function escapeAttr(str) {
    return (str || '').replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/'/g,'&#39;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

function showToast(msg, type) {
    const container = document.getElementById('toast-container') || (() => {
        const d = document.createElement('div');
        d.id = 'toast-container';
        d.style.cssText = 'position:fixed;top:20px;right:20px;z-index:9999;';
        document.body.appendChild(d);
        return d;
    })();
    const toast = document.createElement('div');
    toast.className = 'alert alert-' + type + ' alert-dismissible fade show shadow';
    toast.style.cssText = 'min-width:280px;margin-bottom:8px;';
    toast.innerHTML = msg + '<button type="button" class="btn-close" data-bs-dismiss="alert"></button>';
    container.appendChild(toast);
    setTimeout(() => { toast.classList.remove('show'); setTimeout(() => toast.remove(), 300); }, 3000);
}
</script>
