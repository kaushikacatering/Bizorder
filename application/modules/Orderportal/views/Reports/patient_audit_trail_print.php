<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Patient Audit Trail Report - <?php echo date('d M Y'); ?></title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: Arial, sans-serif;
            font-size: 11px;
            line-height: 1.4;
            color: #333;
            padding: 20px;
        }
        
        .header {
            text-align: center;
            margin-bottom: 20px;
            border-bottom: 2px solid #333;
            padding-bottom: 15px;
        }
        
        .header h1 {
            font-size: 18px;
            margin-bottom: 5px;
        }
        
        .header .subtitle {
            font-size: 12px;
            color: #666;
        }
        
        .header .date-range {
            font-size: 11px;
            margin-top: 5px;
            color: #444;
        }
        
        .summary {
            display: flex;
            justify-content: space-around;
            margin-bottom: 20px;
            padding: 15px;
            background-color: #f8f9fa;
            border: 1px solid #ddd;
        }
        
        .summary-item {
            text-align: center;
        }
        
        .summary-item .count {
            font-size: 20px;
            font-weight: bold;
            color: #333;
        }
        
        .summary-item .label {
            font-size: 10px;
            color: #666;
            text-transform: uppercase;
        }
        
        .summary-item.onboarding .count { color: #198754; }
        .summary-item.discharge .count { color: #dc3545; }
        .summary-item.transfer .count { color: #0d6efd; }
        .summary-item.meals .count { color: #ffc107; }
        
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
        }
        
        th, td {
            border: 1px solid #ddd;
            padding: 6px 8px;
            text-align: left;
        }
        
        th {
            background-color: #f2f2f2;
            font-weight: bold;
            font-size: 10px;
            text-transform: uppercase;
        }
        
        tr:nth-child(even) {
            background-color: #fafafa;
        }
        
        .event-onboarding { color: #198754; font-weight: bold; }
        .event-discharge { color: #dc3545; font-weight: bold; }
        .event-transfer { color: #0d6efd; font-weight: bold; }
        
        .transfer-arrow {
            font-size: 14px;
            color: #666;
        }
        
        .footer {
            margin-top: 20px;
            text-align: center;
            font-size: 9px;
            color: #999;
            border-top: 1px solid #ddd;
            padding-top: 10px;
        }
        
        @media print {
            body { padding: 10px; }
            .header { page-break-after: avoid; }
            table { page-break-inside: auto; }
            tr { page-break-inside: avoid; page-break-after: auto; }
            thead { display: table-header-group; }
        }
        
        .no-print { display: none; }
        @media screen {
            .no-print {
                display: block;
                position: fixed;
                top: 10px;
                right: 10px;
            }
            .no-print button {
                padding: 10px 20px;
                font-size: 14px;
                cursor: pointer;
                background: #0d6efd;
                color: white;
                border: none;
                border-radius: 5px;
            }
            .no-print button:hover {
                background: #0b5ed7;
            }
        }
    </style>
</head>
<body>
    <div class="no-print">
        <button onclick="window.print()">🖨️ Print Report</button>
    </div>
    
    <div class="header">
        <h1>Patient Audit Trail Report</h1>
        <div class="subtitle">Tracking Onboarding, Discharges, and Room Transfers</div>
        <div class="date-range">
            Date Range: <?php echo date('d M Y', strtotime($from_date)); ?> - <?php echo date('d M Y', strtotime($to_date)); ?>
            <?php if ($selected_event_type != 'all'): ?>
                | Filter: <?php echo ucfirst($selected_event_type); ?> Only
            <?php endif; ?>
        </div>
        <div class="date-range">Generated: <?php echo date('d M Y h:i:s A'); ?></div>
    </div>
    
    <div class="summary">
        <div class="summary-item onboarding">
            <div class="count"><?php echo $summary['total_onboarding'] ?? 0; ?></div>
            <div class="label">Onboarding</div>
        </div>
        <div class="summary-item discharge">
            <div class="count"><?php echo $summary['total_discharges'] ?? 0; ?></div>
            <div class="label">Discharges</div>
        </div>
        <div class="summary-item transfer">
            <div class="count"><?php echo $summary['total_transfers'] ?? 0; ?></div>
            <div class="label">Transfers</div>
        </div>
        <div class="summary-item meals">
            <div class="count"><?php echo $summary['total_meals_cancelled'] ?? 0; ?></div>
            <div class="label">Meals Cancelled</div>
        </div>
    </div>
    
    <?php if (empty($audit_events)): ?>
        <p style="text-align: center; padding: 20px; color: #666;">
            No audit events found for the selected date range and filters.
        </p>
    <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Time</th>
                    <th>Event</th>
                    <th>Patient Name</th>
                    <th>Room</th>
                    <th>Floor</th>
                    <th>Transfer Details</th>
                    <th>Meals Affected</th>
                    <th>Notes</th>
                    <th>Recorded By</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($audit_events as $event): ?>
                    <tr>
                        <td><?php echo date('d M Y', strtotime($event['event_date'])); ?></td>
                        <td><?php echo date('h:i:s A', strtotime($event['event_time'])); ?></td>
                        <td class="event-<?php echo $event['event_type']; ?>">
                            <?php echo ucfirst($event['event_type']); ?>
                        </td>
                        <td><?php echo htmlspecialchars($event['patient_name']); ?></td>
                        <td><?php echo htmlspecialchars($event['suite_name']); ?></td>
                        <td><?php echo htmlspecialchars($event['floor_name']); ?></td>
                        <td>
                            <?php if ($event['event_type'] == 'transfer'): ?>
                                <?php echo htmlspecialchars($event['old_suite_name'] ?: 'N/A'); ?>
                                <span class="transfer-arrow">→</span>
                                <?php echo htmlspecialchars($event['new_suite_name'] ?: 'N/A'); ?>
                            <?php else: ?>
                                -
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php 
                            if ($event['event_type'] == 'discharge' && $event['meals_cancelled'] > 0) {
                                echo $event['meals_cancelled'] . ' cancelled';
                            } elseif ($event['event_type'] == 'transfer' && $event['orders_transferred'] > 0) {
                                echo $event['orders_transferred'] . ' transferred';
                            } else {
                                echo '-';
                            }
                            ?>
                        </td>
                        <td><?php echo htmlspecialchars($event['notes'] ?? '-'); ?></td>
                        <td><?php echo htmlspecialchars($event['created_by'] ?? 'System'); ?></td>
                       
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
    
    <div class="footer">
        <p>Total Events: <?php echo count($audit_events); ?> | 
           </p>
    </div>
</body>
</html>
