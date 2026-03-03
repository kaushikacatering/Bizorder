<?php
/**
 * DischargeAndTransferTest
 * ========================
 * Integration tests verifying all 12 discharge & room-transfer scenarios
 * against the real MySQL database schema (cloned into bizorder_test).
 *
 * Each test runs inside a DB transaction that is rolled back automatically.
 *
 * Run:  vendor\bin\phpunit --testdox
 *
 * Scenario mapping (from user request):
 * ──────────────────────────────────────
 *  #1  Place order for tomorrow → discharge today → production form must NOT show the order
 *  #2  Same setup → view details & packaging must NOT show the customer order
 *  #3  Same setup → cancelled orders report must include this bed
 *  #4  Onboard new patient to same suite → place order → it SHOULD count
 *  #5  No orders placed → discharge → clean, no errors, suite vacated, audit logged
 *  #6  Order today+tomorrow → discharge 10 AM → lunch+dinner today cancelled, ALL tomorrow
 *  #7  Order today+tomorrow → discharge 2 PM  → dinner today cancelled, ALL tomorrow
 *  #8  Room transfer report records event + timestamp
 *  #9  Transfer auto-records date/time
 * #10  Room number updates immediately in patient record
 * #11  Kitchen staff notified of new room number
 * #12  All future meals go to the correct (new) room
 */

class DischargeAndTransferTest extends CITestCase
{
    // ================================================================
    //  #1 – Order for tomorrow, discharge today → NOT in production form
    // ================================================================
    public function testDischarge_OrderForTomorrow_NotInProductionForm(): void
    {
        $tomorrow = $this->ausTomorrow();
        $pid = $this->onboardPatient('Alice Smith', 100);
        $this->placeOrder(100, $tomorrow, [self::BREAKFAST, self::LUNCH, self::DINNER], $pid);

        // Before discharge the order should appear
        $before = $this->fetchProductionForm($tomorrow);
        $this->assertNotEmpty($before, 'Order must appear BEFORE discharge');

        $this->dischargePatient($pid);

        // After discharge the suite must not appear
        $after = $this->fetchProductionForm($tomorrow);
        $bedIds = [];
        foreach ($after as $row) {
            $bedIds = array_merge($bedIds, explode(',', $row['bed_ids']));
        }
        $this->assertNotContains('100', $bedIds,
            'Suite 100 must NOT appear in production form after discharge');
    }

    // ================================================================
    //  #2 – After discharge, view details & packaging exclude the order
    // ================================================================
    public function testDischarge_OrderForTomorrow_NotInViewDetailsOrPackaging(): void
    {
        $tomorrow = $this->ausTomorrow();
        $pid = $this->onboardPatient('Bob Jones', 100);
        $this->placeOrder(100, $tomorrow, [self::BREAKFAST, self::LUNCH, self::DINNER], $pid);

        $this->dischargePatient($pid);

        $rows = $this->fetchPatientwiseOrders($tomorrow, 100);
        $this->assertEmpty($rows,
            'Suite 100 must NOT appear in view-details / packaging after discharge');
    }

    // ================================================================
    //  #3 – Cancelled orders report includes the discharged bed
    // ================================================================
    public function testDischarge_CancelledReport_IncludesBed(): void
    {
        $tomorrow = $this->ausTomorrow();
        $today    = $this->ausToday();

        $pid = $this->onboardPatient('Carol White', 100);
        $this->placeOrder(100, $tomorrow, [self::BREAKFAST, self::LUNCH, self::DINNER], $pid);

        $this->dischargePatient($pid);

        $cancelled = $this->fetchCancelledItems($today, $tomorrow);
        $this->assertNotEmpty($cancelled, 'Cancelled report must contain items');

        $cancelledBedIds = array_map('strval', array_unique(array_column($cancelled, 'bed_id')));
        $this->assertContains('100', $cancelledBedIds,
            'Cancelled report must include Suite 100');

        // Verify snapshots
        foreach ($cancelled as $item) {
            $this->assertEquals(1, (int) $item['is_cancelled']);
            $this->assertNotEmpty($item['patient_name_snapshot'],
                'Cancelled item must have patient name snapshot');
        }
    }

    // ================================================================
    //  #4 – New patient in same suite, place order → order counts
    // ================================================================
    public function testNewPatientInSameSuite_OrderAppears(): void
    {
        $tomorrow = $this->ausTomorrow();

        // Onboard → order → discharge original patient
        $pid1 = $this->onboardPatient('Dave Brown', 100);
        $this->placeOrder(100, $tomorrow, [self::BREAKFAST, self::LUNCH, self::DINNER], $pid1);
        $this->dischargePatient($pid1);

        // Onboard NEW patient → place order
        $pid2 = $this->onboardPatient('Eve Green', 100);
        $this->placeOrder(100, $tomorrow, [self::BREAKFAST, self::LUNCH, self::DINNER], $pid2);

        $production = $this->fetchProductionForm($tomorrow);
        $this->assertNotEmpty($production, 'New patient order must appear');

        $bedIds = [];
        foreach ($production as $row) {
            $bedIds = array_merge($bedIds, explode(',', $row['bed_ids']));
        }
        $this->assertContains('100', $bedIds,
            'Suite 100 must appear for the new patient');

        $details = $this->fetchPatientwiseOrders($tomorrow, 100);
        $this->assertNotEmpty($details, 'View details must show new patient order');
    }

    // ================================================================
    //  #5 – No orders, discharge → clean, no errors
    // ================================================================
    public function testDischarge_NoOrders_CleanDischarge(): void
    {
        $pid = $this->onboardPatient('Frank Black', 100);

        $cancelled = $this->dischargePatient($pid);
        $this->assertEquals(0, $cancelled, 'No items to cancel');

        $patient = $this->fetchRow('people', 'id', $pid);
        $this->assertEquals(self::STATUS_DISCHARGED, (int) $patient['status']);
        $this->assertNotEmpty($patient['time_discharged']);

        $suite = $this->fetchRow('suites', 'id', 100);
        $this->assertEquals(1, (int) $suite['is_vaccant'], 'Suite must be vacant');

        $audit = $this->fetchAll('patient_audit_log', 'patient_id', $pid);
        $discharges = array_filter($audit, fn($a) => $a['event_type'] === 'discharge');
        $this->assertNotEmpty($discharges, 'Audit trail must log the discharge');
    }

    // ================================================================
    //  #6 – Discharge at 10 AM: lunch+dinner today cancelled, ALL tomorrow
    // ================================================================
    public function testDischarge_At10AM_CancelsLunchDinnerToday_AllTomorrow(): void
    {
        $today    = $this->ausToday();
        $tomorrow = $this->ausTomorrow();

        $pid = $this->onboardPatient('Grace Hill', 100);
        $this->placeOrder(100, $today, [self::BREAKFAST, self::LUNCH, self::DINNER], $pid);
        $this->placeOrder(100, $tomorrow, [self::BREAKFAST, self::LUNCH, self::DINNER], $pid);

        $cancelled = $this->dischargePatient($pid, '10:00');

        // 2 today (lunch+dinner) + 3 tomorrow = 5
        $this->assertEquals(5, $cancelled, 'Should cancel 5 items');

        // Verify today
        $todayItems = $this->query(
            "SELECT opo.category_id, opo.is_cancelled
             FROM orders_to_patient_options opo
             INNER JOIN orders o ON o.order_id = opo.order_id
             WHERE o.date = ? AND opo.bed_id = ?",
            [$today, 100]
        );
        foreach ($todayItems as $item) {
            $cat = (int) $item['category_id'];
            if ($cat === self::BREAKFAST) {
                $this->assertEquals(0, (int) $item['is_cancelled'], 'Breakfast must be ACTIVE');
            } else {
                $this->assertEquals(1, (int) $item['is_cancelled'], "Category {$cat} must be CANCELLED");
            }
        }

        // Verify tomorrow — all cancelled
        $tomorrowItems = $this->query(
            "SELECT opo.is_cancelled FROM orders_to_patient_options opo
             INNER JOIN orders o ON o.order_id = opo.order_id
             WHERE o.date = ? AND opo.bed_id = ?",
            [$tomorrow, 100]
        );
        foreach ($tomorrowItems as $item) {
            $this->assertEquals(1, (int) $item['is_cancelled'], 'Tomorrow items must all be cancelled');
        }
    }

    // ================================================================
    //  #7 – Discharge at 2 PM: dinner today cancelled, ALL tomorrow
    // ================================================================
    public function testDischarge_At2PM_CancelsDinnerToday_AllTomorrow(): void
    {
        $today    = $this->ausToday();
        $tomorrow = $this->ausTomorrow();

        $pid = $this->onboardPatient('Henry Ford', 100);
        $this->placeOrder(100, $today, [self::BREAKFAST, self::LUNCH, self::DINNER], $pid);
        $this->placeOrder(100, $tomorrow, [self::BREAKFAST, self::LUNCH, self::DINNER], $pid);

        // 13:59 → hour=13, < 14, so dinner cancelled
        $cancelled = $this->dischargePatient($pid, '13:59');

        // 1 today (dinner) + 3 tomorrow = 4
        $this->assertEquals(4, $cancelled, 'Should cancel 4 items');

        $todayItems = $this->query(
            "SELECT opo.category_id, opo.is_cancelled
             FROM orders_to_patient_options opo
             INNER JOIN orders o ON o.order_id = opo.order_id
             WHERE o.date = ? AND opo.bed_id = ?",
            [$today, 100]
        );
        foreach ($todayItems as $item) {
            $cat = (int) $item['category_id'];
            if ($cat === self::DINNER) {
                $this->assertEquals(1, (int) $item['is_cancelled'], 'Dinner must be CANCELLED');
            } else {
                $this->assertEquals(0, (int) $item['is_cancelled'], "Category {$cat} must be ACTIVE");
            }
        }

        $tomorrowItems = $this->query(
            "SELECT opo.is_cancelled FROM orders_to_patient_options opo
             INNER JOIN orders o ON o.order_id = opo.order_id
             WHERE o.date = ? AND opo.bed_id = ?",
            [$tomorrow, 100]
        );
        foreach ($tomorrowItems as $item) {
            $this->assertEquals(1, (int) $item['is_cancelled'], 'Tomorrow items must all be cancelled');
        }
    }

    // ================================================================
    //  #7b – Discharge at/after 2 PM: NO same-day cancel, all tomorrow
    // ================================================================
    public function testDischarge_After2PM_NoSameDayCancel_AllTomorrowCancelled(): void
    {
        $today    = $this->ausToday();
        $tomorrow = $this->ausTomorrow();

        $pid = $this->onboardPatient('Iris Moon', 100);
        $this->placeOrder(100, $today, [self::BREAKFAST, self::LUNCH, self::DINNER], $pid);
        $this->placeOrder(100, $tomorrow, [self::BREAKFAST, self::LUNCH, self::DINNER], $pid);

        // 14:00 → hour=14, >= 14, no same-day cancel
        $cancelled = $this->dischargePatient($pid, '14:00');

        $this->assertEquals(3, $cancelled, 'Only 3 tomorrow items cancelled');

        $todayItems = $this->query(
            "SELECT opo.is_cancelled FROM orders_to_patient_options opo
             INNER JOIN orders o ON o.order_id = opo.order_id
             WHERE o.date = ? AND opo.bed_id = ?",
            [$today, 100]
        );
        foreach ($todayItems as $item) {
            $this->assertEquals(0, (int) $item['is_cancelled'], 'No today items cancelled at 2 PM+');
        }
    }

    // ================================================================
    //  #8 – Room transfer report records event + timestamp
    // ================================================================
    public function testTransfer_AuditTrail_RecordsEventAndTimestamp(): void
    {
        $pid = $this->onboardPatient('Jack Ryan', 100);
        $this->placeOrder(100, $this->ausTomorrow(), [self::LUNCH, self::DINNER], $pid);

        $this->transferPatient($pid, 100, 200);

        $audit = $this->fetchAll('patient_audit_log', 'patient_id', $pid);
        $transfers = array_values(array_filter($audit, fn($a) => $a['event_type'] === 'transfer'));

        $this->assertCount(1, $transfers, 'Exactly one transfer event');
        $tx = $transfers[0];

        $this->assertEquals(100, (int) $tx['old_suite_id']);
        $this->assertEquals('Room-101', $tx['old_suite_number']);
        $this->assertEquals(200, (int) $tx['new_suite_id']);
        $this->assertEquals('Room-202', $tx['new_suite_number']);
        $this->assertNotEmpty($tx['event_datetime']);
        $this->assertNotEmpty($tx['event_date']);

        $dt = \DateTime::createFromFormat('Y-m-d H:i:s', $tx['event_datetime']);
        $this->assertInstanceOf(\DateTime::class, $dt, 'event_datetime must be valid');
    }

    // ================================================================
    //  #9 – Transfer auto-records date+time
    // ================================================================
    public function testTransfer_AutoRecordsDateTimeOfEntry(): void
    {
        $pid = $this->onboardPatient('Karen Xu', 100);
        $before = $this->ausNow();

        $this->transferPatient($pid, 100, 200);

        $after = $this->ausNow();

        $audit = $this->fetchAll('patient_audit_log', 'patient_id', $pid);
        $transfers = array_values(array_filter($audit, fn($a) => $a['event_type'] === 'transfer'));
        $this->assertCount(1, $transfers);

        $transferTime = $transfers[0]['event_datetime'];
        $this->assertGreaterThanOrEqual($before, $transferTime);
        $this->assertLessThanOrEqual($after, $transferTime);
    }

    // ================================================================
    //  #10 – Room number updates immediately
    // ================================================================
    public function testTransfer_PatientRoomUpdatedImmediately(): void
    {
        $pid = $this->onboardPatient('Leo Messi', 100);

        $patientBefore = $this->fetchRow('people', 'id', $pid);
        $this->assertEquals('100', $patientBefore['suite_number']);

        $this->transferPatient($pid, 100, 200);

        $patientAfter = $this->fetchRow('people', 'id', $pid);
        $this->assertEquals('200', $patientAfter['suite_number'],
            'Patient suite_number must update to 200 immediately');

        $oldSuite = $this->fetchRow('suites', 'id', 100);
        $newSuite = $this->fetchRow('suites', 'id', 200);
        $this->assertEquals(1, (int) $oldSuite['is_vaccant'], 'Old suite must be vacant');
        $this->assertEquals(0, (int) $newSuite['is_vaccant'], 'New suite must be occupied');
    }

    // ================================================================
    //  #11 – Kitchen staff notified of new room number
    // ================================================================
    public function testTransfer_KitchenNotified(): void
    {
        $pid = $this->onboardPatient('Maria Lopez', 100);
        $this->placeOrder(100, $this->ausTomorrow(), [self::BREAKFAST], $pid);

        $this->transferPatient($pid, 100, 200);

        $notifications = $this->query(
            "SELECT * FROM Global_notification
             WHERE notification_type = 'room_transfer'
             ORDER BY id DESC LIMIT 1"
        );
        $this->assertNotEmpty($notifications, 'A room_transfer notification must exist');

        $notif = $notifications[0];
        $this->assertStringContainsString('Room-101', $notif['descr'],
            'Notification must mention old room');
        $this->assertStringContainsString('Room-202', $notif['descr'],
            'Notification must mention new room');
        $this->assertStringContainsString('Maria Lopez', $notif['descr'],
            'Notification must mention patient name');
        $this->assertEquals(0, (int) $notif['status'], 'Notification must be unread (status=0)');
    }

    // ================================================================
    //  #12 – All future meals go to the new room
    // ================================================================
    public function testTransfer_FutureMealsGoToNewRoom(): void
    {
        $tomorrow = $this->ausTomorrow();
        $dayAfter = (new \DateTime('+2 days', new \DateTimeZone('Australia/Sydney')))->format('Y-m-d');

        $pid = $this->onboardPatient('Nina Patel', 100);
        $orderId1 = $this->placeOrder(100, $tomorrow, [self::BREAKFAST, self::LUNCH, self::DINNER], $pid);
        $orderId2 = $this->placeOrder(100, $dayAfter, [self::BREAKFAST, self::LUNCH, self::DINNER], $pid);

        $transferred = $this->transferPatient($pid, 100, 200);
        $this->assertEquals(2, $transferred, 'Both orders must transfer');

        // orders table
        $order1 = $this->fetchRow('orders', 'order_id', $orderId1);
        $order2 = $this->fetchRow('orders', 'order_id', $orderId2);
        $this->assertEquals(200, (int) $order1['bed_id']);
        $this->assertEquals(200, (int) $order2['bed_id']);

        // orders_to_patient_options
        $opoItems = $this->query(
            "SELECT bed_id FROM orders_to_patient_options WHERE order_id IN (?, ?)",
            [$orderId1, $orderId2]
        );
        foreach ($opoItems as $item) {
            $this->assertEquals(200, (int) $item['bed_id'], 'OPO bed_id must be 200');
        }

        // suite_order_details
        $sodItems = $this->query(
            "SELECT suite_id FROM suite_order_details WHERE floor_order_id IN (?, ?)",
            [$orderId1, $orderId2]
        );
        foreach ($sodItems as $item) {
            $this->assertEquals(200, (int) $item['suite_id'], 'SOD suite_id must be 200');
        }

        // Production form must show 200, not 100
        $production = $this->fetchProductionForm($tomorrow);
        $allBeds = [];
        foreach ($production as $row) {
            $allBeds = array_merge($allBeds, explode(',', $row['bed_ids']));
        }
        $this->assertContains('200', $allBeds, 'Production form must show new suite 200');
        $this->assertNotContains('100', $allBeds, 'Production form must NOT show old suite 100');
    }

    // ================================================================
    //  BONUS – Audit trail notes populated
    // ================================================================
    public function testAuditTrail_DischargeHasNotes(): void
    {
        $pid = $this->onboardPatient('Oscar Wild', 100);
        $this->dischargePatient($pid);

        $audit = $this->fetchAll('patient_audit_log', 'patient_id', $pid);
        $this->assertGreaterThanOrEqual(2, count($audit), 'onboarding + discharge');

        $discharges = array_values(array_filter($audit, fn($a) => $a['event_type'] === 'discharge'));
        $this->assertNotEmpty($discharges);
        $this->assertNotEmpty($discharges[0]['notes']);
        $this->assertNotEmpty($discharges[0]['event_datetime']);
    }

    // ================================================================
    //  BONUS – Breakfast preserved in production after 10 AM discharge
    // ================================================================
    public function testDischarge_At10AM_BreakfastStillInProductionForm(): void
    {
        $today = $this->ausToday();
        $pid   = $this->onboardPatient('Pat Quinn', 100);
        $this->placeOrder(100, $today, [self::BREAKFAST, self::LUNCH, self::DINNER], $pid);

        $this->dischargePatient($pid, '10:00');

        $production = $this->fetchProductionForm($today);
        $breakfastRows = array_filter($production, fn($r) => (int)$r['category_id'] === self::BREAKFAST);
        $this->assertNotEmpty($breakfastRows, 'Breakfast must still appear at 10 AM discharge');
    }

    // ================================================================
    //  BONUS – Two patients in same bed on same day = 2 beds serviced
    // ================================================================
    public function testBedsServiced_TwoPatientsSameBedSameDay_CountsAs2(): void
    {
        $today = $this->ausToday();

        // Patient A onboarded → breakfast ordered
        $pidA = $this->onboardPatient('Patient A', 100);
        $this->placeOrder(100, $today, [self::BREAKFAST], $pidA);

        // Patient A discharged after noon (breakfast already served)
        $this->dischargePatient($pidA, '13:00');

        // Patient B onboarded in the SAME suite → dinner ordered
        $pidB = $this->onboardPatient('Patient B', 100);
        $this->placeOrder(100, $today, [self::DINNER], $pidB);

        // Beds serviced report should count this as 2
        $report = $this->fetchBedsServicedPerDay($today, $today);
        $this->assertNotEmpty($report, 'Report must have data for today');
        $this->assertEquals(2, (int) $report[0]['beds_count'],
            'Two different patients in the same bed must count as 2 beds serviced');
    }

    // ================================================================
    //  BONUS – Multiple future days all cancelled
    // ================================================================
    public function testDischarge_MultipleFutureDays_AllCancelled(): void
    {
        $today = $this->ausToday();
        $plus1 = (new \DateTime('+1 day', new \DateTimeZone('Australia/Sydney')))->format('Y-m-d');
        $plus2 = (new \DateTime('+2 days', new \DateTimeZone('Australia/Sydney')))->format('Y-m-d');
        $plus3 = (new \DateTime('+3 days', new \DateTimeZone('Australia/Sydney')))->format('Y-m-d');

        $pid = $this->onboardPatient('Quinn Red', 100);
        $this->placeOrder(100, $plus1, [self::BREAKFAST, self::LUNCH, self::DINNER], $pid);
        $this->placeOrder(100, $plus2, [self::BREAKFAST, self::LUNCH, self::DINNER], $pid);
        $this->placeOrder(100, $plus3, [self::BREAKFAST, self::LUNCH, self::DINNER], $pid);

        $cancelled = $this->dischargePatient($pid);

        $this->assertEquals(9, $cancelled, 'All 9 future order items must be cancelled');

        $allItems = $this->query(
            "SELECT opo.is_cancelled FROM orders_to_patient_options opo
             INNER JOIN orders o ON o.order_id = opo.order_id
             WHERE opo.bed_id = ? AND o.date > ?",
            [100, $today]
        );
        foreach ($allItems as $item) {
            $this->assertEquals(1, (int) $item['is_cancelled']);
        }
    }
}
