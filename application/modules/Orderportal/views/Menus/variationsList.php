<?php
// Helper: convert JSON IDs to comma-separated names
function variationIdsToNames($jsonIds, $list) {
    if (empty($jsonIds)) return '';
    $ids = json_decode($jsonIds, true);
    if (!is_array($ids)) return '';
    $names = [];
    foreach ($list as $item) {
        if (in_array($item['id'], $ids)) {
            $names[] = $item['name'];
        }
    }
    return implode(', ', $names);
}
?>
<div class="main-content">
    <div class="page-content">
        <div class="container-fluid">
            <div>
                <?php if ($this->session->flashdata('sucess_msg')): ?>
                    <div class='hideMe'>
                        <p class="alert alert-success"><?php echo $this->session->flashdata('sucess_msg'); ?></p>
                    </div>
                <?php endif; ?>
                <?php if ($this->session->flashdata('error_msg')): ?>
                    <div class='hideMe'>
                        <p class="alert alert-danger"><?php echo $this->session->flashdata('error_msg'); ?></p>
                    </div>
                <?php endif; ?>
            </div>
            <div class="row">
                <div class="col-lg-12">
                    <div class="page-content-inner">
                        <div class="card" id="variationsList">
                            <div class="card-header border-bottom-dashed">
                                <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
                                    <div class="me-3">
                                        <h5 class="card-title mb-0 text-black"><?php echo htmlspecialchars($title); ?></h5>
                                        <p class="text-dark small mb-0">Manage menu item variations</p>
                                    </div>

                                    <div class="w-100 w-md-50 w-lg-25">
                                        <input type="text" id="table-search" class="form-control py-2.5 rounded-lg border border-gray-300 focus:ring-2 focus:ring-primary-500 focus:border-primary-500" placeholder="Search by menu, cuisine, or allergies...">
                                    </div>

                                    <div class="ms-auto">
                                        <a class="btn btn-success" href="<?php echo site_url('Orderportal/Configfoodmenu/menu_management'); ?>">
                                            <i class="ri-add-line align-bottom me-1"></i> Add / Manage Variations
                                        </a>
                                    </div>
                                </div>
                            </div>

                            <div class="card-body">
                                <div class="table-responsive table-card">
                                    <table class="table align-middle" id="variationsTable">
                                        <thead class="table-dark text-white">
                                            <tr>
                                                <th>Menu Item</th>
                                                <th>Variation Name (Cuisine Types)</th>
                                                <th>Ingredients / Description</th>
                                                <th>Nutritional Values</th>
                                                <th>Allergens</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (!empty($variations)): ?>
                                                <?php foreach ($variations as $v): ?>
                                                    <tr>
                                                        <td><?php echo htmlspecialchars($v['menu_name'] ?? 'N/A'); ?></td>
                                                        <td><?php
                                                            $cuisineNames = variationIdsToNames($v['cuisine_type_ids'] ?? '', $cuisines);
                                                            echo !empty($cuisineNames) ? htmlspecialchars($cuisineNames) : '<span class="text-muted">None</span>';
                                                        ?></td>
                                                        <td><?php echo htmlspecialchars($v['description'] ?? ''); ?></td>
                                                        <td><?php echo !empty($v['nutritional_values']) ? '<span class="badge bg-success">' . htmlspecialchars($v['nutritional_values']) . '</span>' : ''; ?></td>
                                                        <td><?php
                                                            $allergenNames = variationIdsToNames($v['allergenValues'] ?? '', $allergies);
                                                            echo !empty($allergenNames) ? htmlspecialchars($allergenNames) : '';
                                                        ?></td>
                                                        <td>
                                                            <div class="d-flex gap-2">
                                                                <a href="<?php echo site_url('Orderportal/Configfoodmenu/menu_management'); ?>" class="btn btn btn-secondary btn-sm">
                                                                    <i class="ri-edit-2-line align-middle me-1"></i>Edit
                                                                </a>
                                                                <button class="btn btn btn-danger btn-sm" onclick="deleteVariationFromList(<?php echo (int)$v['id']; ?>, this)" >
                                                                    <i class="ri-delete-bin-line align-middle me-1"></i>Delete
                                                                </button>
                                                            </div>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <tr>
                                                    <td colspan="6" class="text-center">No variations found. <a href="<?php echo site_url('Orderportal/Configfoodmenu/menu_management'); ?>">Add variations</a></td>
                                                </tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Search functionality
document.addEventListener('DOMContentLoaded', function () {
    const searchInput = document.getElementById('table-search');
    const table = document.getElementById('variationsTable');
    const rows = table.querySelectorAll('tbody tr');

    searchInput.addEventListener('input', function () {
        const filter = searchInput.value.toLowerCase().trim();

        rows.forEach(row => {
            const cells = row.querySelectorAll('td');
            if (cells.length < 5) return; // skip "no results" row
            const menuName = (cells[0].textContent || '').toLowerCase();
            const cuisine = (cells[1].textContent || '').toLowerCase();
            const allergens = (cells[4].textContent || '').toLowerCase();

            if (menuName.includes(filter) || cuisine.includes(filter) || allergens.includes(filter)) {
                row.style.display = '';
            } else {
                row.style.display = 'none';
            }
        });
    });
});

// Delete variation via AJAX
function deleteVariationFromList(id, btn) {
    if (!confirm('Are you sure you want to delete this variation?')) return;

    const row = btn.closest('tr');
    const formData = new FormData();
    formData.append('id', id);

    fetch('<?php echo base_url(); ?>Orderportal/Configfoodmenu/delete_variation', {
        method: 'POST',
        headers: {'X-Requested-With': 'XMLHttpRequest'},
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            row.remove();
            // Check if table is now empty
            const tbody = document.querySelector('#variationsTable tbody');
            if (!tbody.querySelector('tr')) {
                tbody.innerHTML = '<tr><td colspan="6" class="text-center">No variations found. <a href="<?php echo site_url("Orderportal/Configfoodmenu/menu_management"); ?>">Add variations</a></td></tr>';
            }
        } else {
            alert(data.message || 'Failed to delete.');
        }
    })
    .catch(() => alert('Network error.'));
}
</script>
