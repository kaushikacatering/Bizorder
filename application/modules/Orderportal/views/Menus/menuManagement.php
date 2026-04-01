<style>
.variation-row td { vertical-align: middle; }
.variation-row.editing input,
.variation-row.editing select { font-size: 0.875rem; }
.variation-actions button { margin: 0 2px; }
.cb-dropdown-container { position: relative; display: inline-block; width: 100%; }
.cb-dropdown-panel {
    position: absolute; z-index: 1050; top: 100%; left: 0; min-width: 240px;
    max-height: 220px; overflow-y: auto;
    background: #fff; border: 1px solid #e5e7eb; border-radius: 0.5rem;
    box-shadow: 0 4px 16px rgba(0,0,0,0.08); display: none; margin-top: 4px;
}
.cb-dropdown-panel.open { display: block; }
.cb-dropdown-panel label { display: flex; align-items: center; padding: 6px 12px; cursor: pointer; font-size: 0.82rem; color: #374151; transition: none; }
.cb-dropdown-panel label:hover { background: transparent; }
.cb-dropdown-search { padding: 8px 10px; border-bottom: 1px solid #e5e7eb; position: sticky; top: 0; background: #fff; z-index: 1; }
.cb-dropdown-search input { width: 100%; padding: 6px 10px; border: 1px solid #d1d5db; border-radius: 0.375rem; font-size: 0.82rem; outline: none; background: #f9fafb; }
.cb-dropdown-search input:focus { border-color: #6366f1; box-shadow: 0 0 0 2px rgba(99,102,241,0.15); background: #fff; }
.cb-dropdown-btn-text { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; display: block; font-size: 0.82rem; color: #374151; }
/* Fix dropdown clipping */
#variationsTable { overflow: visible !important; }
.table-responsive { overflow: visible !important; }
#variationsCard .card-body { overflow: visible !important; }
</style>

<div class="main-content">
  <div class="page-content">
    <div class="container-fluid">

      <!-- Page Header -->
      <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
        <div>
          <h5 class="card-title mb-0 text-black"><?php echo htmlspecialchars($title); ?></h5>
          <p class="text-black small mb-0">Manage menu items and their dietary variations</p>
        </div>
        <div class="d-flex gap-2 align-items-center">
          
          <a class="btn btn-danger btn-md" href="<?php echo site_url('Orderportal/Configfoodmenu/menu_management_list'); ?>">
            <i class="ri-arrow-left-line me-1"></i> Back to Variations List
          </a>
          <div id="saveAllTopWrap" style="display:none;">
            <button class="btn text-white btn-md" style="background-color:#10a88f;" onclick="saveAll()"><i class="ri-save-line me-1"></i> Save </button>
          </div>

        </div>
      </div>

      <!-- Menu Item Selector + Option Name + Description + Variations -->
      <div class="card mb-4" id="mainCard">
        <div class="card-body">
          <div class="row g-3 align-items-end mb-3">
            <div class="col-md-3">
              <label class="form-label fw-semibold">Select Menu Item</label>
              <select id="menuItemSelect" class="form-select px-4 py-2.5 rounded-lg border border-gray-300 focus:ring-2 focus:ring-primary-500 focus:border-primary-500 bg-gray-50 text-sm">
                <option value="">-- Select Menu Item --</option>
                <?php foreach ($menuItems as $i => $mi): ?>
                  <option value="<?php echo (int)$mi['id']; ?>" <?php echo ((int)$mi['id'] === (int)($preselect_menu_id ?? 0)) ? 'selected' : ''; ?>><?php echo htmlspecialchars($mi['name']); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-3" id="menuOptionNameWrap" style="display:none;">
              <label class="form-label fw-semibold">Menu Option Name</label>
              <input type="text" id="menuOptionName" class="form-control" placeholder="Enter menu option name" maxlength="255">
            </div>
            <div class="col-md-6" id="menuOptionDescWrap" style="display:none;">
              <label class="form-label fw-semibold">Description</label>
              <input type="text" id="menuOptionDesc" class="form-control" placeholder="Enter description">
            </div>
          </div>

          <!-- Variations Section (shown after menu item selected) -->
          <div id="variationsCard" style="display:none;">
            <div class="d-flex align-items-center justify-content-between mb-2">
              <h6 class="mb-0" id="variationsHeading">Variations</h6>
              <button class="btn btn-sm text-white" style="background-color:#4285f4;" onclick="addVariationRow()" title="Add New Variation"><i class="ri-add-line me-1"></i>Add Variation</button>
            </div>
            <div class="table-responsive">
              <table class="table table-sm table-hover mb-0" id="variationsTable">
                <thead class="table-dark text-white">
                  <tr>
                    <th style="width:18%">Variations</th>
                    <th style="width:30%">Ingredients / Description</th>
                    <th style="width:10%">Nutritional Values</th>
                    <th style="width:20%">Allergens</th>
                    <th style="width:22%" class="text-center">Actions</th>
                  </tr>
                </thead>
                <tbody id="variationsBody">
                  <tr class="no-variations-row">
                    <td colspan="5" class="text-center text-muted py-3">Loading...</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>
        </div>
        <!-- Save All Button (Bottom) -->
        <div class="card-footer text-end" id="saveAllBottomWrap" style="display:none;">
          <button class="btn text-white" style="background-color:#10a88f;" onclick="saveAll()"><i class="ri-save-line me-1"></i> Save All</button>
        </div>
      </div>

    </div>
  </div>
</div>

<script>
const BASE_URL = '<?php echo base_url(); ?>';
const ALL_CUISINES = <?php echo json_encode($cuisines); ?>;
const ALL_ALLERGENS = <?php echo json_encode($allergies); ?>;
const AJAX_HEADERS = {'X-Requested-With': 'XMLHttpRequest'};

// Mode: 'add' or 'edit'
const PAGE_MODE = '<?php echo ($mode ?? 'add') === 'edit' ? 'edit' : 'add'; ?>';
const EDIT_OPTION_NAME = <?php echo json_encode($edit_option_name ?? ''); ?>;
const PRESELECT_MENU_ID = <?php echo (int)($preselect_menu_id ?? 0); ?>;

let selectedMenuId = null;

// ─── Menu Item dropdown change ──────────────────────────────────
document.getElementById('menuItemSelect').addEventListener('change', function() {
    selectedMenuId = this.value ? parseInt(this.value) : null;
    if (selectedMenuId) {
        const menuName = this.options[this.selectedIndex].text;
        document.getElementById('variationsHeading').textContent = 'Variations for: ' + menuName;
        document.getElementById('variationsCard').style.display = '';
        document.getElementById('menuOptionNameWrap').style.display = '';
        document.getElementById('menuOptionDescWrap').style.display = '';
        document.getElementById('saveAllTopWrap').style.display = '';
        document.getElementById('saveAllBottomWrap').style.display = '';

        if (PAGE_MODE === 'edit' && EDIT_OPTION_NAME) {
            // Edit mode: load existing variations for this specific option
            loadVariations(selectedMenuId, EDIT_OPTION_NAME);
        } else {
            // Add mode: show empty form, do NOT fetch existing
            document.getElementById('menuOptionName').value = '';
            document.getElementById('menuOptionDesc').value = '';
            var tbody = document.getElementById('variationsBody');
            tbody.innerHTML = '<tr class="no-variations-row"><td colspan="5" class="text-center text-muted py-3">No variations yet. Click <b>Add Variation</b> above to add one.</td></tr>';
        }
    } else {
        document.getElementById('variationsCard').style.display = 'none';
        document.getElementById('menuOptionNameWrap').style.display = 'none';
        document.getElementById('menuOptionDescWrap').style.display = 'none';
        document.getElementById('saveAllTopWrap').style.display = 'none';
        document.getElementById('saveAllBottomWrap').style.display = 'none';
    }
});

// ─── Load variations via AJAX ───────────────────────────────────
function loadVariations(menuDetailId, optionName) {
    const tbody = document.getElementById('variationsBody');
    tbody.innerHTML = '<tr><td colspan="5" class="text-center py-3"><i class="ri-loader-4-line ri-spin me-1"></i> Loading...</td></tr>';

    const formData = new FormData();
    formData.append('menu_detail_id', menuDetailId);
    if (optionName) {
        formData.append('menu_option_name', optionName);
    }

    fetch(BASE_URL + 'Orderportal/Configfoodmenu/get_variations', { method: 'POST', headers: AJAX_HEADERS, body: formData })
    .then(r => r.json())
    .then(data => {
        tbody.innerHTML = '';
        if (data.success && data.variations && data.variations.length) {
            data.variations.forEach(v => {
                tbody.appendChild(buildStaticRow(v));
            });
            // Populate top fields from first variation
            const first = data.variations[0];
            document.getElementById('menuOptionName').value = first.menu_option_name || '';
            document.getElementById('menuOptionDesc').value = first.description || '';
        } else {
            tbody.innerHTML = '<tr class="no-variations-row"><td colspan="5" class="text-center text-muted py-3">No variations yet. Click <b>Add Variation</b> above to add one.</td></tr>';
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
    tr.dataset.menuId = selectedMenuId;
    tr.dataset.optionName = v.menu_option_name || '';

    const cuisineIds = safeJsonParse(v.cuisine_type_ids);
    const allergenIds = safeJsonParse(v.allergenValues);

    tr.innerHTML =
        '<td class="v-cuisine" data-cuisine-ids=\'' + escapeAttr(v.cuisine_type_ids || '[]') + '\'>' + idsToNames(cuisineIds, ALL_CUISINES) + '</td>' +
        '<td class="v-desc">' + escapeHtml(v.description || '') + '</td>' +
        '<td class="v-nutrition">' + escapeHtml(v.nutritional_values || '') + '</td>' +
        '<td class="v-allergens" data-allergen-ids=\'' + escapeAttr(v.allergenValues || '[]') + '\'>' + idsToNames(allergenIds, ALL_ALLERGENS) + '</td>' +
        '<td class="text-center variation-actions">' +
            '<button class="btn btn-sm btn-outline-primary" onclick="editVariation(this)" title="Edit"><i class="ri-pencil-line"></i></button> ' +
            '<button class="btn btn-sm btn-outline-danger" onclick="deleteVariation(' + v.id + ', this)" title="Delete"><i class="ri-delete-bin-line"></i></button> ' +
            '<button class="btn btn-sm btn-outline-success" onclick="addVariationRow()" title="Add New Variation"><i class="ri-add-line"></i></button>' +
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
    btn.className = 'w-100 text-start px-3 py-2 border border-gray-300 rounded-lg bg-gray-50 text-sm';
    btn.innerHTML = '<span class="cb-dropdown-btn-text">' + escapeHtml(getSelectedNames(items, selectedIds)) + '</span>';
    wrapper.appendChild(btn);

    const panel = document.createElement('div');
    panel.className = 'cb-dropdown-panel';

    // Search input
    const searchDiv = document.createElement('div');
    searchDiv.className = 'cb-dropdown-search';
    searchDiv.innerHTML = '<input type="text" placeholder="Search..." class="cb-search-input">';
    panel.appendChild(searchDiv);

    const searchInput = searchDiv.querySelector('.cb-search-input');
    searchInput.addEventListener('input', function() {
        const term = this.value.toLowerCase();
        panel.querySelectorAll('label').forEach(lbl => {
            const name = lbl.querySelector('span').textContent.toLowerCase();
            lbl.style.display = name.includes(term) ? '' : 'none';
        });
    });
    searchInput.addEventListener('click', function(e) { e.stopPropagation(); });

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
        if (panel.classList.contains('open')) { searchInput.value = ''; searchInput.dispatchEvent(new Event('input')); searchInput.focus(); }
    });

    panel.addEventListener('change', function() {
        const checkedIds = Array.from(panel.querySelectorAll('.cb-item:checked')).map(cb => cb.value);
        btn.querySelector('.cb-dropdown-btn-text').textContent = getSelectedNames(items, checkedIds);
    });

    return wrapper;
}

function getSelectedNames(items, selectedIds) {
    if (!selectedIds || !selectedIds.length) return 'Select...';
    const names = [];
    selectedIds.forEach(id => {
        const item = items.find(x => String(x.id) === String(id));
        if (item) names.push(item.name);
    });
    return names.length ? names.join(', ') : 'Select...';
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
    if (!selectedMenuId) return;
    const tbody = document.getElementById('variationsBody');
    const noRow = tbody.querySelector('.no-variations-row');
    if (noRow) noRow.remove();

    const tr = document.createElement('tr');
    tr.className = 'variation-row editing';
    tr.dataset.menuId = selectedMenuId;
    tr.dataset.id = '';

    tr.innerHTML =
        '<td class="v-cuisine-cell"></td>' +
        '<td><textarea class="form-control form-control-sm v-desc-input" rows="2" style="resize:vertical;min-height:38px;" placeholder="Ingredients / Description"></textarea></td>' +
        '<td><input type="text" class="form-control form-control-sm v-nutrition-input" placeholder="Nutritional values"></td>' +
        '<td class="v-allergens-cell"></td>' +
        '<td class="text-center variation-actions">' +
            '<button class="btn btn-sm btn-success" onclick="saveVariationRow(this)" title="Save"><i class="ri-check-line"></i></button> ' +
            '<button class="btn btn-sm btn-outline-danger" onclick="cancelVariationRow(this)" title="Cancel"><i class="ri-close-line"></i></button>' +
        '</td>';

    tr.querySelector('.v-cuisine-cell').appendChild(buildCbDropdown(ALL_CUISINES, [], 'cuisine-widget'));
    tr.querySelector('.v-allergens-cell').appendChild(buildCbDropdown(ALL_ALLERGENS, [], 'allergen-widget'));
    tbody.appendChild(tr);
}

// ─── EDIT existing row (inline) ─────────────────────────────────
function editVariation(btn) {
    const row = btn.closest('tr');
    if (row.classList.contains('editing')) return;

    const name = row.dataset.optionName || '';
    const desc = row.querySelector('.v-desc').textContent.trim();
    const nutrition = row.querySelector('.v-nutrition').textContent.trim();
    const cuisineIds = safeJsonParse(row.querySelector('.v-cuisine').dataset.cuisineIds);
    const allergenIds = safeJsonParse(row.querySelector('.v-allergens').dataset.allergenIds);

    row._original = row.innerHTML;
    row.classList.add('editing');

    row.innerHTML =
        '<td class="v-cuisine-cell"></td>' +
        '<td><textarea class="form-control form-control-sm v-desc-input" rows="2" style="resize:vertical;min-height:38px;">' + escapeHtml(desc) + '</textarea></td>' +
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
        tbody.innerHTML = '<tr class="no-variations-row"><td colspan="5" class="text-center text-muted py-3">No variations yet. Click <b>Add Variation</b> above to add one.</td></tr>';
    }
}

// ─── SAVE variation row (AJAX) ──────────────────────────────────
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
    const menuDetailId = row.dataset.menuId || selectedMenuId;
    const allergenIds = getCheckedValues(allergenWidget);
    const optionName = document.getElementById('menuOptionName').value.trim();

    row.querySelectorAll('button').forEach(b => b.disabled = true);

    const formData = new FormData();
    formData.append('id', id);
    formData.append('menu_detail_id', menuDetailId);
    formData.append('menu_option_name', optionName);
    cuisineIds.forEach(cid => formData.append('cuisine_type_ids[]', cid));
    formData.append('description', descInput ? descInput.value.trim() : '');
    formData.append('nutritional_values', nutritionInput ? nutritionInput.value.trim() : '');
    allergenIds.forEach(aid => formData.append('allergenValues[]', aid));

    fetch(BASE_URL + 'Orderportal/Configfoodmenu/save_variation', { method: 'POST', headers: AJAX_HEADERS, body: formData })
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

// ─── SAVE ALL rows at once ──────────────────────────────────────
function saveAll() {
    if (!selectedMenuId) {
        showToast('Please select a Menu Item.', 'warning');
        return;
    }

    const topName = document.getElementById('menuOptionName').value.trim();
    const topDesc = document.getElementById('menuOptionDesc').value.trim();

    const rows = document.querySelectorAll('#variationsBody .variation-row');
    if (!rows.length) {
        showToast('No variations to save. Add at least one row.', 'warning');
        return;
    }

    const variations = [];
    let hasError = false;

    rows.forEach(row => {
        if (row.classList.contains('editing')) {
            const cuisineWidget = row.querySelector('.cuisine-widget');
            const allergenWidget = row.querySelector('.allergen-widget');
            const descInput = row.querySelector('.v-desc-input');
            const nutritionInput = row.querySelector('.v-nutrition-input');
            const cuisineIds = getCheckedValues(cuisineWidget);

            if (!cuisineIds.length) { hasError = true; }

            variations.push({
                id: row.dataset.id || '',
                menu_option_name: topName,
                cuisine_type_ids: cuisineIds,
                description: descInput ? descInput.value.trim() : '',
                nutritional_values: nutritionInput ? nutritionInput.value.trim() : '',
                allergenValues: getCheckedValues(allergenWidget)
            });
        } else {
            // Static row
            variations.push({
                id: row.dataset.id || '',
                menu_option_name: topName,
                cuisine_type_ids: safeJsonParse(row.querySelector('.v-cuisine')?.dataset.cuisineIds),
                description: row.querySelector('.v-desc')?.textContent.trim() || '',
                nutritional_values: row.querySelector('.v-nutrition')?.textContent.trim() || '',
                allergenValues: safeJsonParse(row.querySelector('.v-allergens')?.dataset.allergenIds)
            });
        }
    });

    if (hasError) {
        showToast('Each variation must have at least one cuisine type selected.', 'warning');
        return;
    }

    const formData = new FormData();
    formData.append('menu_detail_id', selectedMenuId);
    formData.append('menu_option_name', topName);
    formData.append('top_description', topDesc);
    formData.append('variations', JSON.stringify(variations));

    document.querySelectorAll('button[onclick="saveAll()"]').forEach(b => b.disabled = true);

    fetch(BASE_URL + 'Orderportal/Configfoodmenu/save_all_menu_options', { method: 'POST', headers: AJAX_HEADERS, body: formData })
    .then(r => r.json())
    .then(data => {
        document.querySelectorAll('button[onclick="saveAll()"]').forEach(b => b.disabled = false);
        if (data.success) {
            showToast(data.message || 'All variations saved!', 'success');
            if (PAGE_MODE === 'edit' && EDIT_OPTION_NAME) {
                loadVariations(selectedMenuId, document.getElementById('menuOptionName').value.trim());
            } else {
                loadVariations(selectedMenuId, document.getElementById('menuOptionName').value.trim());
            }
        } else {
            showToast(data.message || 'Failed to save.', 'danger');
        }
    })
    .catch(() => {
        document.querySelectorAll('button[onclick="saveAll()"]').forEach(b => b.disabled = false);
        showToast('Network error.', 'danger');
    });
}

// ─── DELETE variation ───────────────────────────────────────────
function deleteVariation(id, btn) {
    if (!confirm('Are you sure you want to delete this variation?')) return;

    const row = btn.closest('tr');
    const tbody = row.closest('tbody');

    const formData = new FormData();
    formData.append('id', id);
    formData.append('menu_detail_id', selectedMenuId);

    fetch(BASE_URL + 'Orderportal/Configfoodmenu/delete_variation', { method: 'POST', headers: AJAX_HEADERS, body: formData })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            row.remove();
            if (!tbody.querySelector('.variation-row')) {
                tbody.innerHTML = '<tr class="no-variations-row"><td colspan="5" class="text-center text-muted py-3">No variations yet. Click <b>Add Variation</b> above to add one.</td></tr>';
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
    toast.className = 'alert alert-' + type + ' alert-dismissible fade show';
    toast.style.cssText = 'min-width:280px;margin-bottom:8px;';
    toast.innerHTML = msg + '<button type="button" class="btn-close" data-bs-dismiss="alert"></button>';
    container.appendChild(toast);
    setTimeout(() => { toast.classList.remove('show'); setTimeout(() => toast.remove(), 300); }, 3000);
}

// ─── Auto-select menu item on page load (edit mode only) ────────
document.addEventListener('DOMContentLoaded', function() {
    const sel = document.getElementById('menuItemSelect');
    if (PAGE_MODE === 'edit' && PRESELECT_MENU_ID && sel.value) {
        sel.value = PRESELECT_MENU_ID;
        sel.dispatchEvent(new Event('change'));
    }
});
</script>
