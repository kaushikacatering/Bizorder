<style>
    /* Event type badges */
    .badge-onboarding {
        background-color: #198754 !important;
        color: #ffffff !important;
    }
    .badge-discharge {
        background-color: #dc3545 !important;
        color: #ffffff !important;
    }
    .badge-transfer {
        background-color: #0d6efd !important;
        color: #ffffff !important;
    }
    
    /* Summary cards */
    .summary-card {
        border-left: 4px solid;
        transition: transform 0.2s;
    }
    .summary-card:hover {
        transform: translateY(-2px);
    }
    .summary-card.onboarding {
        border-left-color: #198754;
    }
    .summary-card.discharge {
        border-left-color: #dc3545;
    }
    .summary-card.transfer {
        border-left-color: #0d6efd;
    }
    .summary-card.meals {
        border-left-color: #ffc107;
    }
    
    /* Time display */
    .event-time {
        font-family: monospace;
        font-size: 0.9em;
        color: #6c757d;
    }
    
    /* Override pagination purple/indigo color to gray */
    .dataTables_wrapper .dataTables_paginate .paginate_button.current,
    .dataTables_wrapper .dataTables_paginate .paginate_button.current:hover,
    .dataTables_wrapper .dataTables_paginate .paginate_button.current:focus,
    .dataTables_wrapper .dataTables_paginate .paginate_button.current:active,
    .page-item.active .page-link {
        background-color: #6c757d !important;
        border-color: #6c757d !important;
        color: #ffffff !important;
    }
    
    /* Table styling */
    .table td, .table th {
        color: #212529 !important;
        vertical-align: middle;
    }
    
    /* Print button */
    @media print {
        .no-print { display: none !important; }
        .card { border: none !important; box-shadow: none !important; }
    }
</style>

<div class="main-content">
    <div class="page-content">
        <div class="container-fluid">
            
            <!-- Page Title -->
            <div class="row">
                <div class="col-12">
                    <div class="page-title-box d-sm-flex align-items-center justify-content-between">
                        <h4 class="mb-sm-0">Patient Audit Trail</h4>
                        <div class="page-title-right no-print">
                            <ol class="breadcrumb m-0">
                                <li class="breadcrumb-item"><a href="<?php echo base_url('Orderportal/Reports'); ?>">Reports</a></li>
                                <li class="breadcrumb-item active">Audit Trail</li>
                            </ol>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Summary Cards -->
            <div class="row mb-4">
                <div class="col-lg-3 col-sm-6">
                    <div class="card summary-card onboarding">
                        <div class="card-body">
                            <div class="d-flex align-items-center">
                                <div class="flex-shrink-0">
                                    <div class="avatar-sm">
                                        <span class="avatar-title bg-success-subtle text-success rounded-2">
                                            <i class="ri-user-add-line fs-20"></i>
                                        </span>
                                    </div>
                                </div>
                                <div class="flex-grow-1 ms-3">
                                    <p class="text-uppercase fw-medium text-muted mb-1">Onboarding</p>
                                    <h4 class="mb-0"><?php echo $summary['total_onboarding'] ?? 0; ?></h4>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-lg-3 col-sm-6">
                    <div class="card summary-card discharge">
                        <div class="card-body">
                            <div class="d-flex align-items-center">
                                <div class="flex-shrink-0">
                                    <div class="avatar-sm">
                                        <span class="avatar-title bg-danger-subtle text-danger rounded-2">
                                            <i class="ri-user-unfollow-line fs-20"></i>
                                        </span>
                                    </div>
                                </div>
                                <div class="flex-grow-1 ms-3">
                                    <p class="text-uppercase fw-medium text-muted mb-1">Discharges</p>
                                    <h4 class="mb-0"><?php echo $summary['total_discharges'] ?? 0; ?></h4>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-lg-3 col-sm-6">
                    <div class="card summary-card transfer">
                        <div class="card-body">
                            <div class="d-flex align-items-center">
                                <div class="flex-shrink-0">
                                    <div class="avatar-sm">
                                        <span class="avatar-title bg-primary-subtle text-primary rounded-2">
                                            <i class="ri-arrow-left-right-line fs-20"></i>
                                        </span>
                                    </div>
                                </div>
                                <div class="flex-grow-1 ms-3">
                                    <p class="text-uppercase fw-medium text-muted mb-1">Transfers</p>
                                    <h4 class="mb-0"><?php echo $summary['total_transfers'] ?? 0; ?></h4>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-lg-3 col-sm-6">
                    <div class="card summary-card meals">
                        <div class="card-body">
                            <div class="d-flex align-items-center">
                                <div class="flex-shrink-0">
                                    <div class="avatar-sm">
                                        <span class="avatar-title bg-warning-subtle text-warning rounded-2">
                                            <i class="ri-restaurant-line fs-20"></i>
                                        </span>
                                    </div>
                                </div>
                                <div class="flex-grow-1 ms-3">
                                    <p class="text-uppercase fw-medium text-muted mb-1">Meals Cancelled</p>
                                    <h4 class="mb-0"><?php echo $summary['total_meals_cancelled'] ?? 0; ?></h4>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Filter Section -->
            <div class="row no-print">
                <div class="col-lg-12">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="card-title mb-0">Filter Audit Events</h5>
                        </div>
                        <div class="card-body">
                            <form action="<?php echo base_url('Orderportal/Reports/patientAuditTrail'); ?>" method="POST">
                                <div class="row">
                                    <!-- From Date -->
                                    <div class="col-md-3 col-sm-6 mb-3">
                                        <label class="form-label">From Date</label>
                                        <input type="date" class="form-control" name="from_date" 
                                               value="<?php echo $from_date; ?>" required>
                                    </div>
                                    
                                    <!-- To Date -->
                                    <div class="col-md-3 col-sm-6 mb-3">
                                        <label class="form-label">To Date</label>
                                        <input type="date" class="form-control" name="to_date" 
                                               value="<?php echo $to_date; ?>" required>
                                    </div>
                                    
                                    <!-- Event Type -->
                                    <div class="col-md-3 col-sm-6 mb-3">
                                        <label class="form-label">Event Type</label>
                                        <select class="form-select" name="event_type">
                                            <option value="all" <?php echo ($selected_event_type == 'all') ? 'selected' : ''; ?>>All Events</option>
                                            <option value="onboarding" <?php echo ($selected_event_type == 'onboarding') ? 'selected' : ''; ?>>Onboarding Only</option>
                                            <option value="discharge" <?php echo ($selected_event_type == 'discharge') ? 'selected' : ''; ?>>Discharges Only</option>
                                            <option value="transfer" <?php echo ($selected_event_type == 'transfer') ? 'selected' : ''; ?>>Transfers Only</option>
                                        </select>
                                    </div>
                                    
                                    <!-- Buttons -->
                                    <div class="col-md-3 col-sm-6 mb-3 d-flex align-items-end">
                                        <button type="submit" class="btn btn-primary me-2">
                                            <i class="ri-filter-3-line"></i> Filter
                                        </button>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Print Button -->
            <div class="row mb-3 no-print">
                <div class="col-12">
                    <a href="<?php echo base_url('Orderportal/Reports/printPatientAuditTrail?from_date=' . $from_date . '&to_date=' . $to_date . '&event_type=' . $selected_event_type); ?>" 
                       target="_blank" class="btn btn-secondary">
                        <i class="ri-printer-line"></i> Print Report
                    </a>
                </div>
            </div>
            
            <!-- Audit Events Table -->
            <div class="row">
                <div class="col-lg-12">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="card-title mb-0">
                                Audit Events 
                                <span class="text-muted">
                                    (<?php echo date('d M Y', strtotime($from_date)); ?> - <?php echo date('d M Y', strtotime($to_date)); ?>)
                                </span>
                            </h5>
                        </div>
                        <div class="card-body">
                            <?php if (empty($audit_events)): ?>
                                <div class="alert alert-info">
                                    <i class="ri-information-line me-2"></i>
                                    No audit events found for the selected date range and filters.
                                </div>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table id="auditTrailTable" class="table table-bordered table-hover dt-responsive nowrap" style="width:100%">
                                        <thead class="table-light">
                                            <tr>
                                                <th>Date</th>
                                                <th>Time</th>
                                                <th>Event</th>
                                                <th>Patient</th>
                                                <th>Room</th>
                                                <th>Floor</th>
                                                <th>Transfer Details</th>
                                                <th>Meals Affected</th>
                                                <th>Notes</th>
                                                <th>Recorded By</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($audit_events as $event): ?>
                                                <tr>
                                                    <td data-order="<?php echo $event['event_date']; ?>">
                                                        <?php echo date('d M Y', strtotime($event['event_date'])); ?>
                                                    </td>
                                                    <td class="event-time">
                                                        <?php echo date('h:i:s A', strtotime($event['event_time'])); ?>
                                                    </td>
                                                    <td>
                                                        <?php 
                                                        $badge_class = '';
                                                        $icon = '';
                                                        switch($event['event_type']) {
                                                            case 'onboarding':
                                                                $badge_class = 'badge-onboarding';
                                                                $icon = 'ri-user-add-line';
                                                                break;
                                                            case 'discharge':
                                                                $badge_class = 'badge-discharge';
                                                                $icon = 'ri-user-unfollow-line';
                                                                break;
                                                            case 'transfer':
                                                                $badge_class = 'badge-transfer';
                                                                $icon = 'ri-arrow-left-right-line';
                                                                break;
                                                        }
                                                        ?>
                                                        <span class="badge <?php echo $badge_class; ?>">
                                                            <i class="<?php echo $icon; ?> me-1"></i>
                                                            <?php echo ucfirst($event['event_type']); ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <strong><?php echo htmlspecialchars($event['patient_name']); ?></strong>
                                                    </td>
                                                    <td>
                                                        <?php echo htmlspecialchars($event['suite_name']); ?>
                                                    </td>
                                                    <td>
                                                        <?php echo htmlspecialchars($event['floor_name']); ?>
                                                    </td>
                                                    <td>
                                                        <?php if ($event['event_type'] == 'transfer'): ?>
                                                            <span class="text-danger">
                                                                <?php echo htmlspecialchars($event['old_suite_name'] ?: 'N/A'); ?>
                                                            </span>
                                                            <i class="ri-arrow-right-line mx-1"></i>
                                                            <span class="text-success">
                                                                <?php echo htmlspecialchars($event['new_suite_name'] ?: 'N/A'); ?>
                                                            </span>
                                                        <?php else: ?>
                                                            <span class="text-muted">N/A</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <?php if ($event['event_type'] == 'discharge' && $event['meals_cancelled'] > 0): ?>
                                                            <span class="badge bg-warning text-dark">
                                                                <?php echo $event['meals_cancelled']; ?> cancelled
                                                            </span>
                                                        <?php elseif ($event['event_type'] == 'transfer' && $event['orders_transferred'] > 0): ?>
                                                            <span class="badge bg-info">
                                                                <?php echo $event['orders_transferred']; ?> transferred
                                                            </span>
                                                        <?php else: ?>
                                                            <span class="text-muted">-</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <?php echo !empty($event['notes']) ? htmlspecialchars($event['notes']) : '<span class="text-muted">-</span>'; ?>
                                                    </td>
                                                    <td>
                                                        <?php echo htmlspecialchars($event['created_by'] ?? 'System'); ?>
                                                    </td>
                                                    <td>
                                                        <?php if (!empty($event['json_data'])): ?>
                                                            <button class="btn btn-sm btn-outline-secondary" onclick="viewJsonData(<?php echo htmlspecialchars(json_encode($event['json_data'])); ?>)" title="View JSON Log">
                                                                <i class="ri-code-s-slash-line"></i>
                                                            </button>
                                                        <?php else: ?>
                                                            <span class="text-muted">-</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            
        </div>
    </div>
</div>

<!-- JSON Data Modal -->
<div class="modal fade" id="jsonDataModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="ri-code-s-slash-line me-1"></i> Audit Event - JSON Log</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <pre id="jsonDataContent" style="background: #f8f9fa; padding: 15px; border-radius: 5px; max-height: 500px; overflow-y: auto; font-size: 12px; white-space: pre-wrap; word-wrap: break-word;"></pre>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-sm btn-outline-primary" onclick="copyJsonData()">
                    <i class="ri-file-copy-line me-1"></i> Copy JSON
                </button>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    // Initialize DataTable
    if ($('#auditTrailTable').length && typeof $.fn.DataTable !== 'undefined') {
        $('#auditTrailTable').DataTable({
            pageLength: 25,
            order: [[0, 'desc'], [1, 'desc']], // Sort by date then time descending
            responsive: true,
            language: {
                search: "Search:",
                lengthMenu: "Show _MENU_ entries",
                info: "Showing _START_ to _END_ of _TOTAL_ events",
                infoEmpty: "No events available",
                emptyTable: "No audit events found"
            }
        });
    }
});

// View JSON data in modal
function viewJsonData(jsonStr) {
    try {
        var data = typeof jsonStr === 'string' ? JSON.parse(jsonStr) : jsonStr;
        document.getElementById('jsonDataContent').textContent = JSON.stringify(data, null, 2);
    } catch (e) {
        document.getElementById('jsonDataContent').textContent = jsonStr;
    }
    var modal = new bootstrap.Modal(document.getElementById('jsonDataModal'));
    modal.show();
}

// Copy JSON data to clipboard
function copyJsonData() {
    var content = document.getElementById('jsonDataContent').textContent;
    navigator.clipboard.writeText(content).then(function() {
        alert('JSON data copied to clipboard!');
    }).catch(function() {
        // Fallback
        var textArea = document.createElement('textarea');
        textArea.value = content;
        document.body.appendChild(textArea);
        textArea.select();
        document.execCommand('copy');
        document.body.removeChild(textArea);
        alert('JSON data copied to clipboard!');
    });
}
</script>
