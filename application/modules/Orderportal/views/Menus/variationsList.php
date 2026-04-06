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
                                        <input type="text" id="table-search" class="form-control py-2.5 rounded-lg border border-gray-300 focus:ring-2 focus:ring-primary-500 focus:border-primary-500" placeholder="Search by menu, option name, or description...">
                                    </div>

                                    <div class="ms-auto">
                                        <a class="btn text-white" style="background-color:#4285f4;" href="<?php echo site_url('Orderportal/Configfoodmenu/menu_management?mode=add'); ?>">
                                            <i class="ri-add-line align-bottom me-1"></i> Add Menu Option
                                        </a>
                                    </div>
                                </div>
                            </div>

                            <div class="card-body">
                                <div class="table-responsive table-card">
                                    <table class="table align-middle" id="variationsTable">
                                        <thead class="table-dark text-white">
                                            <tr>
                                                <th>Menu</th>
                                                <th>Menu Option Name</th>
                                                <th>Description</th>
                                                <th>Variations</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (!empty($variations)): ?>
                                                <?php foreach ($variations as $v): ?>
                                                    <tr>
                                                        <td>
                                                            <?php
                                                                $menuId = isset($v['menu_detail_id']) ? (int)$v['menu_detail_id'] : 0;
                                                                $menuName = isset($v['menu_name']) ? trim($v['menu_name']) : '';
                                                            ?>
                                                            <?php if ($menuId === 0 || $menuName === '' || $menuName === 'Unlinked'): ?>
                                                                <span class="badge bg-warning text-dark">Unlinked</span>
                                                            <?php else: ?>
                                                                <?php echo htmlspecialchars($menuName); ?>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td><?php echo htmlspecialchars($v['menu_option_name'] ?? ''); ?></td>
                                                        <td><?php echo htmlspecialchars($v['description'] ?? ''); ?></td>
                                                        <td><span class="badge bg-info"><?php echo (int)($v['variation_count'] ?? 0); ?></span></td>
                                                        <td>
                                                            <div class="d-flex gap-2">
                                                                <a href="<?php echo site_url('Orderportal/Configfoodmenu/menu_management?mode=edit&menu_id=' . (int)($v['menu_detail_id'] ?? 0) . '&option_name=' . urlencode($v['menu_option_name'] ?? '')); ?>" class="btn btn btn-secondary btn-sm">
                                                                    <i class="ri-edit-2-line align-middle me-1"></i>Edit
                                                                </a>
                                                                <button class="btn btn btn-danger btn-sm" onclick="deleteMenuOptionGroup(<?php echo (int)($v['menu_detail_id'] ?? 0); ?>, '<?php echo addslashes(htmlspecialchars($v['menu_option_name'] ?? '')); ?>', this)" >
                                                                    <i class="ri-delete-bin-line align-middle me-1"></i>Delete
                                                                </button>
                                                            </div>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <tr>
                                                    <td colspan="5" class="text-center">No menu options found. <a href="<?php echo site_url('Orderportal/Configfoodmenu/menu_management?mode=add'); ?>">Add options</a></td>
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
            if (cells.length < 3) return; // skip "no results" row
            const menuName = (cells[0].textContent || '').toLowerCase();
            const optionName = (cells[1].textContent || '').toLowerCase();
            const description = (cells[2].textContent || '').toLowerCase();

            if (menuName.includes(filter) || optionName.includes(filter) || description.includes(filter)) {
                row.style.display = '';
            } else {
                row.style.display = 'none';
            }
        });
    });
});

// Delete menu option group (all variations) via AJAX
function deleteMenuOptionGroup(menuDetailId, optionName, btn) {
    if (!confirm('Are you sure you want to delete this menu option and all its variations?')) return;

    const row = btn.closest('tr');
    const formData = new FormData();
    formData.append('menu_detail_id', menuDetailId);
    formData.append('option_name', optionName);

    fetch('<?php echo base_url(); ?>Orderportal/Configfoodmenu/delete_menu_option_group', {
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
                tbody.innerHTML = '<tr><td colspan="5" class="text-center">No menu options found. <a href="<?php echo site_url("Orderportal/Configfoodmenu/menu_management?mode=add"); ?>">Add options</a></td></tr>';
            }
        } else {
            alert(data.message || 'Failed to delete.');
        }
    })
    .catch(() => alert('Network error.'));
}
</script>
