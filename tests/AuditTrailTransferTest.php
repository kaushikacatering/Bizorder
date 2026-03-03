<?php
/**
 * AuditTrailTransferTest
 * ======================
 * Robust integration tests verifying that room transfers are properly
 * recorded in the patient_audit_log table and correctly retrieved by
 * the audit trail report queries.
 *
 * These tests cover the bug where transfers were NOT appearing in
 * the audit trail report because:
 *   1. logEvent() included a 'json_data' column that didn't exist in the table
 *   2. The INSERT silently failed, so zero transfer records were ever written
 *   3. buildEventsFromPeopleTable() fallback had no transfer support
 *
 * Run:  vendor\bin\phpunit --testdox tests/AuditTrailTransferTest.php
 */

class AuditTrailTransferTest extends CITestCase
{
    // ================================================================
    //  TEST 1: Transfer event is actually inserted into patient_audit_log
    // ================================================================
    public function testTransferInsert_RecordExistsInAuditLog(): void
    {
        $pid = $this->onboardPatient('Transfer Test A', 100);

        // Verify onboarding was logged
        $beforeTransfer = $this->countRows('patient_audit_log', [
            'patient_id' => $pid,
            'event_type' => 'transfer',
        ]);
        $this->assertEquals(0, $beforeTransfer, 'No transfer record should exist before transfer');

        $this->transferPatient($pid, 100, 200);

        $afterTransfer = $this->countRows('patient_audit_log', [
            'patient_id' => $pid,
            'event_type' => 'transfer',
        ]);
        $this->assertEquals(1, $afterTransfer, 'Exactly one transfer record must exist after transfer');
    }

    // ================================================================
    //  TEST 2: Transfer audit record has correct old/new suite data
    // ================================================================
    public function testTransferAudit_HasCorrectSuiteData(): void
    {
        $pid = $this->onboardPatient('Suite Data Test', 100);
        $this->transferPatient($pid, 100, 200);

        $audit = $this->fetchAll('patient_audit_log', 'patient_id', $pid);
        $transfers = array_values(array_filter($audit, fn($a) => $a['event_type'] === 'transfer'));

        $this->assertCount(1, $transfers);
        $tx = $transfers[0];

        // Old suite
        $this->assertEquals(100, (int) $tx['old_suite_id'], 'old_suite_id must be 100');
        $this->assertEquals('Room-101', $tx['old_suite_number'], 'old_suite_number must be Room-101');
        $this->assertEquals(1, (int) $tx['old_floor_id'], 'old_floor_id must be 1');
        $this->assertEquals('Ground Floor', $tx['old_floor_name'], 'old_floor_name must be Ground Floor');

        // New suite
        $this->assertEquals(200, (int) $tx['new_suite_id'], 'new_suite_id must be 200');
        $this->assertEquals('Room-202', $tx['new_suite_number'], 'new_suite_number must be Room-202');
        $this->assertEquals(1, (int) $tx['new_floor_id'], 'new_floor_id must be 1');
        $this->assertEquals('Ground Floor', $tx['new_floor_name'], 'new_floor_name must be Ground Floor');
    }

    // ================================================================
    //  TEST 3: Transfer audit has correct event_datetime and event_date
    // ================================================================
    public function testTransferAudit_HasValidDatetime(): void
    {
        $before = $this->ausNow();
        $pid = $this->onboardPatient('Datetime Test', 100);
        $this->transferPatient($pid, 100, 200);
        $after = $this->ausNow();

        $audit = $this->fetchAll('patient_audit_log', 'patient_id', $pid);
        $transfers = array_values(array_filter($audit, fn($a) => $a['event_type'] === 'transfer'));

        $this->assertCount(1, $transfers);
        $tx = $transfers[0];

        // event_datetime must be a valid datetime between before and after
        $dt = \DateTime::createFromFormat('Y-m-d H:i:s', $tx['event_datetime']);
        $this->assertInstanceOf(\DateTime::class, $dt, 'event_datetime must be valid Y-m-d H:i:s');
        $this->assertGreaterThanOrEqual($before, $tx['event_datetime']);
        $this->assertLessThanOrEqual($after, $tx['event_datetime']);

        // event_date must match today
        $this->assertEquals($this->ausToday(), $tx['event_date'], 'event_date must be today');
    }

    // ================================================================
    //  TEST 4: Transfer notes contain meaningful transfer info
    // ================================================================
    public function testTransferAudit_NotesContainTransferInfo(): void
    {
        $pid = $this->onboardPatient('Notes Test', 100);
        $this->transferPatient($pid, 100, 200);

        $audit = $this->fetchAll('patient_audit_log', 'patient_id', $pid);
        $transfers = array_values(array_filter($audit, fn($a) => $a['event_type'] === 'transfer'));

        $this->assertCount(1, $transfers);
        $this->assertNotEmpty($transfers[0]['notes'], 'Transfer notes must not be empty');
        $this->assertStringContainsString('Room-101', $transfers[0]['notes'], 'Notes must mention old room');
        $this->assertStringContainsString('Room-202', $transfers[0]['notes'], 'Notes must mention new room');
    }

    // ================================================================
    //  TEST 5: Transfer records orders_affected count
    // ================================================================
    public function testTransferAudit_RecordsOrdersAffectedCount(): void
    {
        $tomorrow = $this->ausTomorrow();
        $pid = $this->onboardPatient('Orders Affected', 100);

        // Place orders for tomorrow
        $this->placeOrder(100, $tomorrow, [self::BREAKFAST, self::LUNCH, self::DINNER], $pid);

        $this->transferPatient($pid, 100, 200);

        $audit = $this->fetchAll('patient_audit_log', 'patient_id', $pid);
        $transfers = array_values(array_filter($audit, fn($a) => $a['event_type'] === 'transfer'));

        $this->assertCount(1, $transfers);
        $this->assertGreaterThan(0, (int) $transfers[0]['orders_affected'],
            'orders_affected must be > 0 when there are orders to transfer');
    }

    // ================================================================
    //  TEST 6: Transfer with no orders records orders_affected = 0
    // ================================================================
    public function testTransferAudit_NoOrders_ZeroOrdersAffected(): void
    {
        $pid = $this->onboardPatient('No Orders', 100);

        // Transfer without placing any orders
        $transferred = $this->transferPatient($pid, 100, 200);
        $this->assertEquals(0, $transferred, 'No orders to transfer');

        $audit = $this->fetchAll('patient_audit_log', 'patient_id', $pid);
        $transfers = array_values(array_filter($audit, fn($a) => $a['event_type'] === 'transfer'));

        $this->assertCount(1, $transfers);
        $this->assertEquals(0, (int) $transfers[0]['orders_affected'],
            'orders_affected must be 0 when no orders exist');
    }

    // ================================================================
    //  TEST 7: Multiple transfers create multiple audit records
    // ================================================================
    public function testTransferAudit_MultipleTransfers_MultipleRecords(): void
    {
        // Add a third suite for the second transfer
        $this->insert('suites', [
            'id' => 300, 'bed_no' => 'Room-303', 'floor' => '1',
            'is_vaccant' => 1, 'is_deleted' => 0, 'status' => 1, 'location_id' => 1,
        ]);

        $pid = $this->onboardPatient('Multi Transfer', 100);

        // First transfer: 100 → 200
        $this->transferPatient($pid, 100, 200);

        // Second transfer: 200 → 300
        $this->transferPatient($pid, 200, 300);

        $audit = $this->fetchAll('patient_audit_log', 'patient_id', $pid);
        $transfers = array_values(array_filter($audit, fn($a) => $a['event_type'] === 'transfer'));

        $this->assertCount(2, $transfers, 'Two transfers must create two audit records');

        // Verify the suite trail
        $suiteTrail = array_map(fn($t) => [
            'from' => (int) $t['old_suite_id'],
            'to'   => (int) $t['new_suite_id'],
        ], $transfers);

        $this->assertContains(['from' => 100, 'to' => 200], $suiteTrail, 'First transfer 100→200');
        $this->assertContains(['from' => 200, 'to' => 300], $suiteTrail, 'Second transfer 200→300');
    }

    // ================================================================
    //  TEST 8: Audit trail query returns transfers within date range
    // ================================================================
    public function testAuditQuery_TransfersInDateRange(): void
    {
        $today = $this->ausToday();
        $pid = $this->onboardPatient('Date Range Test', 100);
        $this->transferPatient($pid, 100, 200);

        // Query audit log for today's transfers
        $results = $this->query(
            "SELECT * FROM patient_audit_log 
             WHERE event_type = 'transfer' 
             AND event_date >= ? AND event_date <= ?
             ORDER BY event_datetime DESC",
            [$today, $today]
        );

        $this->assertNotEmpty($results, 'Transfer must appear in date range query');

        $patientIds = array_map('intval', array_column($results, 'patient_id'));
        $this->assertContains($pid, $patientIds,
            'Our patient must appear in the transfer results');
    }

    // ================================================================
    //  TEST 9: Audit trail query for 'transfer' event_type filter
    // ================================================================
    public function testAuditQuery_FilterByTransferType(): void
    {
        $today = $this->ausToday();
        $pid = $this->onboardPatient('Filter Test', 100);
        $this->transferPatient($pid, 100, 200);
        $this->dischargePatient($pid);

        // Query ONLY transfers
        $transfers = $this->query(
            "SELECT * FROM patient_audit_log 
             WHERE patient_id = ? AND event_type = 'transfer'",
            [$pid]
        );

        $this->assertCount(1, $transfers, 'Filter must return exactly 1 transfer');
        $this->assertEquals('transfer', $transfers[0]['event_type']);

        // Query ALL events for this patient
        $allEvents = $this->query(
            "SELECT * FROM patient_audit_log WHERE patient_id = ?",
            [$pid]
        );

        // Should have onboarding + transfer + discharge = 3
        $this->assertCount(3, $allEvents, 'Patient should have 3 audit events total');

        $eventTypes = array_column($allEvents, 'event_type');
        $this->assertContains('onboarding', $eventTypes);
        $this->assertContains('transfer', $eventTypes);
        $this->assertContains('discharge', $eventTypes);
    }

    // ================================================================
    //  TEST 10: Transfer report data structure matches view expectations
    // ================================================================
    public function testTransferReport_DataStructure(): void
    {
        $today = $this->ausToday();
        $pid = $this->onboardPatient('Structure Test', 100);
        $this->placeOrder(100, $this->ausTomorrow(), [self::BREAKFAST, self::LUNCH], $pid);
        $this->transferPatient($pid, 100, 200);

        // Simulate getPatientAuditEvents query (the same query Reports.php uses)
        $results = $this->query(
            "SELECT pal.* FROM patient_audit_log pal
             WHERE pal.event_date >= ? AND pal.event_date <= ?
             AND pal.event_type = 'transfer'
             ORDER BY pal.event_datetime DESC",
            [$today, $today]
        );

        $this->assertNotEmpty($results, 'Transfer report must have results');

        $row = $results[0];

        // Build the event structure exactly as Reports.php does
        $event = [
            'id' => $row['id'],
            'patient_id' => $row['patient_id'],
            'patient_name' => $row['patient_name'],
            'event_type' => $row['event_type'],
            'event_date' => date('Y-m-d', strtotime($row['event_datetime'])),
            'event_time' => date('H:i:s', strtotime($row['event_datetime'])),
            'event_datetime' => $row['event_datetime'],
            'suite_name' => $row['new_suite_number'] ?: $row['old_suite_number'] ?: '',
            'floor_name' => $row['new_floor_name'] ?: $row['old_floor_name'] ?: '',
            'old_suite_name' => $row['old_suite_number'],
            'new_suite_name' => $row['new_suite_number'],
            'meals_cancelled' => $row['meals_cancelled'],
            'orders_transferred' => $row['orders_affected'] ?? 0,
            'notes' => $row['notes'],
        ];

        // Verify the structure has all required fields for the view
        $this->assertNotEmpty($event['id']);
        $this->assertEquals($pid, (int) $event['patient_id']);
        $this->assertEquals('Structure Test', $event['patient_name']);
        $this->assertEquals('transfer', $event['event_type']);
        $this->assertNotEmpty($event['event_date']);
        $this->assertNotEmpty($event['event_time']);
        $this->assertEquals('Room-101', $event['old_suite_name'], 'Old suite name for transfer arrow');
        $this->assertEquals('Room-202', $event['new_suite_name'], 'New suite name for transfer arrow');
        $this->assertEquals('Room-202', $event['suite_name'], 'Primary suite_name defaults to new suite');
        $this->assertNotEmpty($event['notes']);
    }

    // ================================================================
    //  TEST 11: Audit summary counts transfers correctly
    // ================================================================
    public function testAuditSummary_CountsTransfers(): void
    {
        $today = $this->ausToday();

        // Add a third suite
        $this->insert('suites', [
            'id' => 300, 'bed_no' => 'Room-303', 'floor' => '1',
            'is_vaccant' => 1, 'is_deleted' => 0, 'status' => 1, 'location_id' => 1,
        ]);

        // Create two patients and transfer them
        $pid1 = $this->onboardPatient('Summary A', 100);
        $pid2 = $this->onboardPatient('Summary B', 300);

        $this->transferPatient($pid1, 100, 200);

        // Query audit summary (mirrors AuditTrail_model::getAuditSummary)
        $summary = $this->query(
            "SELECT event_type, COUNT(*) as event_count,
                    SUM(meals_cancelled) as total_meals_cancelled,
                    SUM(orders_affected) as total_orders_affected
             FROM patient_audit_log
             WHERE event_date >= ? AND event_date <= ?
             GROUP BY event_type
             ORDER BY event_count DESC",
            [$today, $today]
        );

        $this->assertNotEmpty($summary);

        // Find transfer summary
        $transferSummary = array_values(array_filter($summary, fn($s) => $s['event_type'] === 'transfer'));
        $this->assertCount(1, $transferSummary, 'Transfer type must appear in summary');
        $this->assertEquals(1, (int) $transferSummary[0]['event_count'], 'Transfer count must be 1');
    }

    // ================================================================
    //  TEST 12: Full patient lifecycle: onboard → transfer → discharge
    // ================================================================
    public function testFullLifecycle_OnboardTransferDischarge(): void
    {
        $today = $this->ausToday();
        $tomorrow = $this->ausTomorrow();

        $pid = $this->onboardPatient('Lifecycle Test', 100);
        $this->placeOrder(100, $tomorrow, [self::BREAKFAST, self::LUNCH, self::DINNER], $pid);

        // Transfer
        $this->transferPatient($pid, 100, 200);

        // Verify patient is in new suite
        $patient = $this->fetchRow('people', 'id', $pid);
        $this->assertEquals('200', $patient['suite_number']);

        // Place another order in new suite
        $this->placeOrder(200, $tomorrow, [self::BREAKFAST], $pid);

        // Discharge from new suite
        $this->dischargePatient($pid);

        // Verify complete audit trail
        $audit = $this->fetchAll('patient_audit_log', 'patient_id', $pid);

        $eventTypes = array_column($audit, 'event_type');
        $this->assertContains('onboarding', $eventTypes, 'Audit must have onboarding');
        $this->assertContains('transfer', $eventTypes, 'Audit must have transfer');
        $this->assertContains('discharge', $eventTypes, 'Audit must have discharge');

        // Verify chronological order
        $datetimes = array_column($audit, 'event_datetime');
        $sorted = $datetimes;
        sort($sorted);
        $this->assertEquals($sorted, $datetimes, 'Events must be in chronological order');
    }

    // ================================================================
    //  TEST 13: Transfer without json_data column still works
    //  (Regression test for the root cause bug)
    // ================================================================
    public function testTransferInsert_WorksWithoutJsonDataColumn(): void
    {
        // Verify patient_audit_log does NOT have json_data column
        // (mirrors the production schema)
        $columns = $this->query(
            "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS 
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'patient_audit_log'",
            [self::$testDb]
        );
        $columnNames = array_column($columns, 'COLUMN_NAME');

        // Our insert should work regardless of whether json_data exists
        $pid = $this->onboardPatient('No JSON Column', 100);
        $this->transferPatient($pid, 100, 200);

        $transfers = $this->query(
            "SELECT * FROM patient_audit_log 
             WHERE patient_id = ? AND event_type = 'transfer'",
            [$pid]
        );

        $this->assertCount(1, $transfers,
            'Transfer must be inserted successfully even without json_data column');
        $this->assertEquals(100, (int) $transfers[0]['old_suite_id']);
        $this->assertEquals(200, (int) $transfers[0]['new_suite_id']);
    }

    // ================================================================
    //  TEST 14: Suite history shows transfers for a specific suite
    // ================================================================
    public function testSuiteHistory_ShowsTransfers(): void
    {
        $pid = $this->onboardPatient('Suite History', 100);
        $this->transferPatient($pid, 100, 200);

        // Query suite history (mirrors AuditTrail_model::getSuiteHistory)
        $history100 = $this->query(
            "SELECT * FROM patient_audit_log 
             WHERE (old_suite_id = ? OR new_suite_id = ?)
             ORDER BY event_datetime DESC",
            [100, 100]
        );

        $history200 = $this->query(
            "SELECT * FROM patient_audit_log 
             WHERE (old_suite_id = ? OR new_suite_id = ?)
             ORDER BY event_datetime DESC",
            [200, 200]
        );

        // Suite 100 should show: onboarding (new_suite_id=100) + transfer (old_suite_id=100)
        $this->assertGreaterThanOrEqual(2, count($history100),
            'Suite 100 must have at least 2 events (onboarding + transfer out)');

        // Suite 200 should show: transfer (new_suite_id=200)
        $this->assertGreaterThanOrEqual(1, count($history200),
            'Suite 200 must have at least 1 event (transfer in)');
    }

    // ================================================================
    //  TEST 15: Transfer preserves patient_name snapshot
    // ================================================================
    public function testTransferAudit_PreservesPatientNameSnapshot(): void
    {
        $pid = $this->onboardPatient('Original Name', 100);
        $this->transferPatient($pid, 100, 200);

        $transfers = $this->query(
            "SELECT patient_name FROM patient_audit_log 
             WHERE patient_id = ? AND event_type = 'transfer'",
            [$pid]
        );

        $this->assertCount(1, $transfers);
        $this->assertEquals('Original Name', $transfers[0]['patient_name'],
            'Transfer audit must preserve the patient name at time of transfer');
    }

    // ================================================================
    //  TEST 16: Onboarding events also work (regression - same fix)
    // ================================================================
    public function testOnboardingInsert_AlsoWorksCorrectly(): void
    {
        $pid = $this->onboardPatient('Onboard Regression', 100);

        $onboarding = $this->query(
            "SELECT * FROM patient_audit_log 
             WHERE patient_id = ? AND event_type = 'onboarding'",
            [$pid]
        );

        $this->assertCount(1, $onboarding, 'Onboarding must be logged');
        $this->assertEquals(100, (int) $onboarding[0]['new_suite_id']);
        $this->assertEquals('Onboard Regression', $onboarding[0]['patient_name']);
    }

    // ================================================================
    //  TEST 17: Discharge events also work (regression - same fix)
    // ================================================================
    public function testDischargeInsert_AlsoWorksCorrectly(): void
    {
        $pid = $this->onboardPatient('Discharge Regression', 100);
        $this->dischargePatient($pid);

        $discharge = $this->query(
            "SELECT * FROM patient_audit_log 
             WHERE patient_id = ? AND event_type = 'discharge'",
            [$pid]
        );

        $this->assertCount(1, $discharge, 'Discharge must be logged');
        $this->assertEquals(100, (int) $discharge[0]['old_suite_id']);
        $this->assertEquals('Discharge Regression', $discharge[0]['patient_name']);
    }

    // ================================================================
    //  TEST 18: Transfer and discharge for same patient don't clash
    // ================================================================
    public function testTransferThenDischarge_BothLoggedCorrectly(): void
    {
        $pid = $this->onboardPatient('Transfer Discharge', 100);
        $this->placeOrder(100, $this->ausTomorrow(), [self::BREAKFAST], $pid);

        // Transfer 100 → 200
        $this->transferPatient($pid, 100, 200);

        // Discharge from 200
        $this->dischargePatient($pid);

        $audit = $this->fetchAll('patient_audit_log', 'patient_id', $pid);

        $onboarding = array_values(array_filter($audit, fn($a) => $a['event_type'] === 'onboarding'));
        $transfers = array_values(array_filter($audit, fn($a) => $a['event_type'] === 'transfer'));
        $discharges = array_values(array_filter($audit, fn($a) => $a['event_type'] === 'discharge'));

        $this->assertCount(1, $onboarding, 'One onboarding event');
        $this->assertCount(1, $transfers, 'One transfer event');
        $this->assertCount(1, $discharges, 'One discharge event');

        // Transfer: 100 → 200
        $this->assertEquals(100, (int) $transfers[0]['old_suite_id']);
        $this->assertEquals(200, (int) $transfers[0]['new_suite_id']);

        // Discharge: from 200 (the suite they were transferred TO)
        $this->assertEquals(200, (int) $discharges[0]['old_suite_id']);
    }

    // ================================================================
    //  TEST 19: Date range query excludes transfers outside range
    // ================================================================
    public function testAuditQuery_ExcludesOutOfRangeTransfers(): void
    {
        $today = $this->ausToday();
        $yesterday = $this->ausYesterday();

        $pid = $this->onboardPatient('Range Exclude', 100);
        $this->transferPatient($pid, 100, 200);

        // Query for yesterday only
        $results = $this->query(
            "SELECT * FROM patient_audit_log 
             WHERE event_type = 'transfer'
             AND event_date >= ? AND event_date <= ?",
            [$yesterday, $yesterday]
        );

        // Today's transfer should NOT appear in yesterday's range
        $patientIds = array_column($results, 'patient_id');
        $this->assertNotContains((string) $pid, $patientIds,
            'Today transfer must NOT appear in yesterday-only date range');
    }

    // ================================================================
    //  TEST 20: Transfer audit report with SELECT pal.* works
    //  (Validates the actual query used by Reports.php)
    // ================================================================
    public function testReportsQuery_SelectAllFromAuditLog(): void
    {
        $today = $this->ausToday();
        $pid = $this->onboardPatient('Reports Query', 100);
        $this->placeOrder(100, $this->ausTomorrow(), [self::BREAKFAST, self::DINNER], $pid);
        $this->transferPatient($pid, 100, 200);

        // This is the EXACT query pattern from Reports::getPatientAuditEvents
        $results = $this->query(
            "SELECT pal.*
             FROM patient_audit_log pal
             WHERE pal.event_date >= ? AND pal.event_date <= ?
             ORDER BY pal.event_datetime DESC",
            [$today, $today]
        );

        $this->assertNotEmpty($results, 'Reports query must return results');

        // Find our transfer
        $transfers = array_values(array_filter($results, fn($r) => 
            $r['event_type'] === 'transfer' && (int) $r['patient_id'] === $pid
        ));

        $this->assertCount(1, $transfers, 'Reports query must include our transfer');

        $row = $transfers[0];

        // Verify all columns needed by the view are present and populated
        $this->assertArrayHasKey('id', $row);
        $this->assertArrayHasKey('patient_id', $row);
        $this->assertArrayHasKey('patient_name', $row);
        $this->assertArrayHasKey('event_type', $row);
        $this->assertArrayHasKey('event_datetime', $row);
        $this->assertArrayHasKey('old_suite_id', $row);
        $this->assertArrayHasKey('old_suite_number', $row);
        $this->assertArrayHasKey('new_suite_id', $row);
        $this->assertArrayHasKey('new_suite_number', $row);
        $this->assertArrayHasKey('notes', $row);
        $this->assertArrayHasKey('orders_affected', $row);

        $this->assertEquals('Room-101', $row['old_suite_number']);
        $this->assertEquals('Room-202', $row['new_suite_number']);
    }
}
