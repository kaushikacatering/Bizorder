<?php
/**
 * PHPUnit Bootstrap for Bizorder
 * ==============================
 * Provides the CITestCase base class with a direct MySQLi connection
 * to a transient test database cloned from the production schema.
 *
 * We do NOT boot the full CodeIgniter framework — tests exercise the
 * same SQL logic that the controllers use, but via direct DB queries,
 * guaranteeing zero coupling to CI's HTTP layer.
 *
 * Usage:  vendor/bin/phpunit  (reads phpunit.xml → this bootstrap)
 */

error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED & ~E_STRICT);
ini_set('display_errors', '1');

// Composer autoload (PHPUnit classes, etc.)
require_once __DIR__ . '/../vendor/autoload.php';

/**
 * Base test case that provides a real MySQL connection to the test database.
 * Each test method runs inside a transaction that is rolled back automatically,
 * so the database always returns to a clean state.
 *
 * Actual table schemas (from DESCRIBE on bizorder):
 * ─────────────────────────────────────────────────
 * suites:       id, bed_no, floor(varchar), notes, suite_pin, status(tinyint), is_deleted, location_id, date_added, is_vaccant
 * orders:       order_id(AI), date, dept_id, bed_id, status, location_id, added_by, updated_by, is_delivered, budget, limits, buttonType, workflow_status, is_floor_consolidated, floor_id, total_suites, participating_suites, delivered_date, created_at, updated_at
 * orders_to_patient_options: id(AI), order_id, bed_id, patient_id, menu_id, category_id, option_id, quantity, created_at(date), updated_at, status, suite_order_detail_id, is_cancelled, cancel_reason, cancelled_at, cancelled_by, patient_name_snapshot, suite_name_snapshot
 * people:       id(AI), name, floor_number, suite_number(varchar), allergies, dietary_preferences, special_instructions, photo_path, date_onboarded, time_onboarded, date_of_discharge, time_discharged, status, date_added(date), date_modified(date)
 * patient_audit_log: id(AI), patient_id, patient_name, event_type(enum), event_datetime, event_date, old_suite_id, old_suite_number, old_floor_id, old_floor_name, new_suite_id, new_suite_number, new_floor_id, new_floor_name, notes, orders_affected, meals_cancelled, created_by, created_by_name, ip_address, created_at
 * suite_order_details: id(AI), floor_order_id, suite_id, patient_id, suite_number, order_comment, added_by, added_at, modified_at, status(enum)
 * Global_notification: id(AI), system_id, title, descr, location_id, role_id, user_id, status, date, time, notification_type, is_deleted, deleted_at
 * foodmenuconfig: id(AI), name, is_deleted, created_date, updated_date, location_id, listtype, inputType, sort_order
 * menuDetails:   id(AI), category, cuisine, inputType, date_created, date_updated, name, ..., sort_order, ...
 * menu_options:  id(AI), menu_option_name, ..., sort_order, ..., menu_color, ...
 * orders_to_comments: id(AI), order_id, bed_id, order_data, order_comment
 */
class CITestCase extends \PHPUnit\Framework\TestCase
{
    /** @var \mysqli */
    protected static $link;

    /** @var string */
    protected static $testDb = 'bizorder_test';

    /** @var string */
    protected static $sourceDb = 'bizorder';

    // Category constants
    protected const BREAKFAST = 3;
    protected const LUNCH     = 5;
    protected const DINNER    = 7;

    // Patient status
    protected const STATUS_ACTIVE     = 1;
    protected const STATUS_DISCHARGED = 2;

    // ── SETUP / TEARDOWN ────────────────────────────────────────────

    public static function setUpBeforeClass(): void
    {
        self::$link = new \mysqli('localhost', 'root', '', '');
        if (self::$link->connect_error) {
            throw new \RuntimeException('MySQL connect failed: ' . self::$link->connect_error);
        }

        self::$link->query('DROP DATABASE IF EXISTS `' . self::$testDb . '`');
        self::$link->query('CREATE DATABASE `' . self::$testDb . '` CHARACTER SET utf8 COLLATE utf8_general_ci');

        // Clone every table schema from the source database
        $tables = self::$link->query("SHOW TABLES FROM `" . self::$sourceDb . "`");
        while ($row = $tables->fetch_row()) {
            $table = $row[0];
            $createResult = self::$link->query("SHOW CREATE TABLE `" . self::$sourceDb . "`.`{$table}`");
            if ($createResult) {
                $createRow = $createResult->fetch_assoc();
                $createSql = $createRow['Create Table'] ?? '';
                if ($createSql) {
                    $createSql = str_replace(
                        "CREATE TABLE `{$table}`",
                        "CREATE TABLE IF NOT EXISTS `" . self::$testDb . "`.`{$table}`",
                        $createSql
                    );
                    self::$link->query($createSql);
                }
            }
        }

        self::$link->select_db(self::$testDb);
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$link) {
            self::$link->query('DROP DATABASE IF EXISTS `' . self::$testDb . '`');
            self::$link->close();
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        self::$link->begin_transaction();
        $this->seedBaseData();
    }

    protected function tearDown(): void
    {
        self::$link->rollback();
        parent::tearDown();
    }

    // ── SEEDING ─────────────────────────────────────────────────────

    protected function seedBaseData(): void
    {
        // Floor
        $this->insert('foodmenuconfig', [
            'id' => 1, 'name' => 'Ground Floor', 'listtype' => 'floor',
            'sort_order' => 1, 'is_deleted' => 0, 'location_id' => 1,
        ]);
        // Categories
        $this->insert('foodmenuconfig', [
            'id' => self::BREAKFAST, 'name' => 'Breakfast', 'listtype' => 'category',
            'sort_order' => 1, 'is_deleted' => 0, 'location_id' => 1,
        ]);
        $this->insert('foodmenuconfig', [
            'id' => self::LUNCH, 'name' => 'Lunch', 'listtype' => 'category',
            'sort_order' => 2, 'is_deleted' => 0, 'location_id' => 1,
        ]);
        $this->insert('foodmenuconfig', [
            'id' => self::DINNER, 'name' => 'Dinner', 'listtype' => 'category',
            'sort_order' => 3, 'is_deleted' => 0, 'location_id' => 1,
        ]);
        // Menu items
        $this->insert('menuDetails', [
            'id' => 1, 'name' => 'Toast', 'sort_order' => 1,
            'category' => self::BREAKFAST, 'status' => 1, 'is_deleted' => 0, 'location_id' => 1,
        ]);
        $this->insert('menuDetails', [
            'id' => 2, 'name' => 'Soup', 'sort_order' => 1,
            'category' => self::LUNCH, 'status' => 1, 'is_deleted' => 0, 'location_id' => 1,
        ]);
        $this->insert('menuDetails', [
            'id' => 3, 'name' => 'Steak', 'sort_order' => 1,
            'category' => self::DINNER, 'status' => 1, 'is_deleted' => 0, 'location_id' => 1,
        ]);
        // Menu option
        $this->insert('menu_options', [
            'id' => 1, 'menu_option_name' => 'Regular',
            'menu_color' => '#000', 'status' => 1, 'is_deleted' => 0, 'location_id' => 1,
        ]);
        // Two suites (floor is varchar in real schema)
        $this->insert('suites', [
            'id' => 100, 'bed_no' => 'Room-101', 'floor' => '1',
            'is_vaccant' => 1, 'is_deleted' => 0, 'status' => 1, 'location_id' => 1,
        ]);
        $this->insert('suites', [
            'id' => 200, 'bed_no' => 'Room-202', 'floor' => '1',
            'is_vaccant' => 1, 'is_deleted' => 0, 'status' => 1, 'location_id' => 1,
        ]);
    }

    // ── Patient management ──────────────────────────────────────────

    protected function onboardPatient(string $name, int $suiteId, ?string $onboardDate = null): int
    {
        $now   = $this->ausNow();
        $today = $this->ausToday();
        $onboardDate = $onboardDate ?? $today;

        $pid = $this->insert('people', [
            'name'                => $name,
            'floor_number'        => 1,
            'suite_number'        => (string) $suiteId,  // varchar column
            'allergies'           => '[]',
            'dietary_preferences' => '["84"]',
            'status'              => self::STATUS_ACTIVE,
            'date_onboarded'      => $onboardDate,
            'time_onboarded'      => $now,
            'date_added'          => $today,
        ]);

        $this->update('suites', ['is_vaccant' => 0], 'id', $suiteId);

        $suiteInfo = $this->fetchRow('suites', 'id', $suiteId);
        $this->insert('patient_audit_log', [
            'patient_id'       => $pid,
            'patient_name'     => $name,
            'event_type'       => 'onboarding',
            'event_datetime'   => $now,
            'event_date'       => $today,
            'new_suite_id'     => $suiteId,
            'new_suite_number' => $suiteInfo['bed_no'] ?? "Suite $suiteId",
            'new_floor_id'     => 1,
            'new_floor_name'   => 'Ground Floor',
            'notes'            => 'New patient onboarded',
            'orders_affected'  => 0,
            'meals_cancelled'  => 0,
        ]);

        return $pid;
    }

    /**
     * Discharge a patient (mirrors Patient::updateStatus logic).
     * @return int  Number of order items cancelled
     */
    protected function dischargePatient(int $patientId, ?string $dischargeTime = null): int
    {
        $patient = $this->fetchRow('people', 'id', $patientId);
        $this->assertNotNull($patient, "Patient $patientId must exist");

        $today = $this->ausToday();
        $now   = $dischargeTime
            ? $today . ' ' . $dischargeTime . ':00'
            : $this->ausNow();

        $dt = new \DateTime($now, new \DateTimeZone('Australia/Sydney'));
        $currentHour = (int) $dt->format('H');

        $this->update('people', [
            'status'            => self::STATUS_DISCHARGED,
            'date_of_discharge' => $today,
            'time_discharged'   => $now,
            'date_modified'     => $today,
        ], 'id', $patientId);

        $suiteId = (int) $patient['suite_number'];
        $this->update('suites', ['is_vaccant' => 1], 'id', $suiteId);

        // Same-day cancel logic
        $cancelledCount = 0;
        if ($currentHour < 11) {
            $categoriesToday = [self::LUNCH, self::DINNER];
        } elseif ($currentHour < 14) {
            $categoriesToday = [self::DINNER];
        } else {
            $categoriesToday = [];
        }

        if (!empty($categoriesToday)) {
            $cancelledCount += $this->softCancelOrderItems(
                $suiteId, $today, $categoriesToday, $patient['name'], 'patient_discharged'
            );
        }

        // Future orders
        $futureOrders = $this->query(
            "SELECT DISTINCT o.order_id, o.date FROM orders o
             INNER JOIN orders_to_patient_options opo ON opo.order_id = o.order_id
             WHERE o.date > ? AND o.status != 0 AND opo.bed_id = ? AND opo.is_cancelled = 0",
            [$today, $suiteId]
        );
        foreach ($futureOrders as $fo) {
            $cancelledCount += $this->softCancelOrderItems(
                $suiteId, $fo['date'], null, $patient['name'], 'patient_discharged', (int) $fo['order_id']
            );
        }

        // Audit trail
        $suiteInfo = $this->fetchRow('suites', 'id', $suiteId);
        $this->insert('patient_audit_log', [
            'patient_id'       => $patientId,
            'patient_name'     => $patient['name'],
            'event_type'       => 'discharge',
            'event_datetime'   => $now,
            'event_date'       => $today,
            'old_suite_id'     => $suiteId,
            'old_suite_number' => $suiteInfo['bed_no'] ?? "Suite $suiteId",
            'old_floor_id'     => 1,
            'old_floor_name'   => 'Ground Floor',
            'meals_cancelled'  => $cancelledCount,
            'orders_affected'  => 0,
            'notes'            => 'Patient discharged via status update',
        ]);

        return $cancelledCount;
    }

    protected function softCancelOrderItems(
        int $suiteId, string $orderDate, ?array $categoryIds,
        string $patientName, string $cancelReason, ?int $orderId = null
    ): int {
        $where = "opo.bed_id = {$suiteId} AND opo.is_cancelled = 0";
        if ($orderId !== null) {
            $where .= " AND opo.order_id = {$orderId}";
        } else {
            $where .= " AND o.date = '{$orderDate}'";
        }
        if ($categoryIds !== null && !empty($categoryIds)) {
            $in = implode(',', $categoryIds);
            $where .= " AND opo.category_id IN ({$in})";
        }

        $items = $this->query(
            "SELECT opo.id FROM orders_to_patient_options opo
             INNER JOIN orders o ON o.order_id = opo.order_id
             WHERE {$where}"
        );
        if (empty($items)) return 0;

        $ids    = array_column($items, 'id');
        $idList = implode(',', $ids);
        $suiteInfo = $this->fetchRow('suites', 'id', $suiteId);
        $suiteName = $suiteInfo['bed_no'] ?? "Suite $suiteId";
        $now = $this->ausNow();
        $eName  = self::$link->real_escape_string($patientName);
        $eSuite = self::$link->real_escape_string($suiteName);

        self::$link->query(
            "UPDATE orders_to_patient_options SET
                is_cancelled = 1,
                cancel_reason = '{$cancelReason}',
                cancelled_at = '{$now}',
                patient_name_snapshot = '{$eName}',
                suite_name_snapshot = '{$eSuite}'
             WHERE id IN ({$idList})"
        );

        return count($ids);
    }

    // ── Order placement ─────────────────────────────────────────────

    protected function placeOrder(int $suiteId, string $date, array $categoryIds, ?int $patientId = null): int
    {
        // Reuse existing floor order if one exists (unique index: date+dept_id+is_floor_consolidated)
        $existing = $this->query(
            "SELECT order_id FROM orders WHERE date = ? AND dept_id = 1 AND is_floor_consolidated = 1 AND status != 0 LIMIT 1",
            [$date]
        );
        if (!empty($existing)) {
            $orderId = (int) $existing[0]['order_id'];
            // Update participating_suites
            $order = $this->fetchRow('orders', 'order_id', $orderId);
            $ps = json_decode($order['participating_suites'] ?? '[]', true) ?: [];
            if (!in_array($suiteId, $ps)) {
                $ps[] = $suiteId;
                $this->update('orders', [
                    'participating_suites' => json_encode($ps),
                    'total_suites' => count($ps),
                ], 'order_id', $orderId);
            }
        } else {
            $orderId = $this->insert('orders', [
                'date'                  => $date,
                'status'                => 1,
                'bed_id'                => $suiteId,
                'floor_id'              => 1,
                'dept_id'               => 1,
                'added_by'              => 1,
                'is_floor_consolidated' => 1,
                'total_suites'          => 1,
                'participating_suites'  => json_encode([$suiteId]),
                'workflow_status'       => 'sendorder',
                'buttonType'            => 'sendorder',
                'is_delivered'          => 0,
                'location_id'           => 1,
            ]);
        }

        $suiteInfo = $this->fetchRow('suites', 'id', $suiteId);
        $sodId = $this->insert('suite_order_details', [
            'floor_order_id' => $orderId,
            'suite_id'       => $suiteId,
            'suite_number'   => $suiteInfo['bed_no'] ?? "Suite $suiteId",
            'patient_id'     => $patientId,
            'order_comment'  => '',
            'added_by'       => 1,
            'status'         => 'active',
        ]);

        $catMenuMap = [
            self::BREAKFAST => 1,
            self::LUNCH     => 2,
            self::DINNER    => 3,
        ];

        foreach ($categoryIds as $catId) {
            $menuId = $catMenuMap[$catId] ?? 1;
            $this->insert('orders_to_patient_options', [
                'order_id'              => $orderId,
                'suite_order_detail_id' => $sodId,
                'bed_id'                => $suiteId,
                'patient_id'            => $patientId,
                'category_id'           => $catId,
                'menu_id'               => $menuId,
                'option_id'             => 1,
                'quantity'              => 1,
                'status'                => 0,
                'is_cancelled'          => 0,
            ]);
        }

        return $orderId;
    }

    /**
     * Transfer a patient (mirrors Hospitalconfig::transferClient).
     * @return int  Number of orders transferred
     */
    protected function transferPatient(int $patientId, int $oldSuiteId, int $newSuiteId): int
    {
        $patient = $this->fetchRow('people', 'id', $patientId);
        $this->assertNotNull($patient, "Patient $patientId must exist");

        $now   = $this->ausNow();
        $today = $this->ausToday();

        $this->update('people', [
            'suite_number'  => (string) $newSuiteId,
            'date_modified' => $today,
        ], 'id', $patientId);

        $this->update('suites', ['is_vaccant' => 1], 'id', $oldSuiteId);
        $this->update('suites', ['is_vaccant' => 0], 'id', $newSuiteId);

        $ordersToTransfer = $this->query(
            "SELECT order_id FROM orders
             WHERE bed_id = ? AND date >= ? AND status != 0 AND is_delivered != 1",
            [$oldSuiteId, $today]
        );

        $transferred = 0;
        foreach ($ordersToTransfer as $row) {
            $oid = (int) $row['order_id'];
            self::$link->query("UPDATE orders SET bed_id = {$newSuiteId} WHERE order_id = {$oid}");
            self::$link->query("UPDATE orders_to_patient_options SET bed_id = {$newSuiteId} WHERE order_id = {$oid} AND bed_id = {$oldSuiteId}");
            self::$link->query("UPDATE suite_order_details SET suite_id = {$newSuiteId} WHERE floor_order_id = {$oid} AND suite_id = {$oldSuiteId}");
            self::$link->query("UPDATE orders_to_comments SET bed_id = {$newSuiteId} WHERE order_id = {$oid} AND bed_id = {$oldSuiteId}");
            $transferred++;
        }

        $oldSuiteInfo = $this->fetchRow('suites', 'id', $oldSuiteId);
        $newSuiteInfo = $this->fetchRow('suites', 'id', $newSuiteId);

        // Global_notification (matches actual schema)
        $this->insert('Global_notification', [
            'title'             => 'Room Transfer',
            'descr'             => "{$patient['name']} transferred from {$oldSuiteInfo['bed_no']} to {$newSuiteInfo['bed_no']}. {$transferred} order(s) updated.",
            'notification_type' => 'room_transfer',
            'status'            => 0,
            'date'              => $today,
            'time'              => (new \DateTime($now))->format('H:i:s'),
            'is_deleted'        => 0,
            'location_id'       => 1,
        ]);

        // Audit trail
        $this->insert('patient_audit_log', [
            'patient_id'       => $patientId,
            'patient_name'     => $patient['name'],
            'event_type'       => 'transfer',
            'event_datetime'   => $now,
            'event_date'       => $today,
            'old_suite_id'     => $oldSuiteId,
            'old_suite_number' => $oldSuiteInfo['bed_no'] ?? "Suite $oldSuiteId",
            'old_floor_id'     => 1,
            'old_floor_name'   => 'Ground Floor',
            'new_suite_id'     => $newSuiteId,
            'new_suite_number' => $newSuiteInfo['bed_no'] ?? "Suite $newSuiteId",
            'new_floor_id'     => 1,
            'new_floor_name'   => 'Ground Floor',
            'orders_affected'  => $transferred,
            'meals_cancelled'  => 0,
            'notes'            => "Transferred from {$oldSuiteInfo['bed_no']} to {$newSuiteInfo['bed_no']}. {$transferred} meal order(s) updated to new room.",
        ]);

        return $transferred;
    }

    // ── Query helpers (mirror application queries) ──────────────────

    /** Production form (mirrors Order_model::fetchOrderForChef) */
    protected function fetchProductionForm(string $date): array
    {
        return $this->query(
            "SELECT 
                COALESCE(opo.category_id, 0) as category_id,
                COALESCE(fmc.name, 'Unknown') as category_name,
                md.id as menu_id,
                md.name as menu_item_name,
                opo.option_id,
                mo.menu_option_name,
                mo.cuisineValues,
                SUM(opo.quantity) as total_qty,
                COUNT(DISTINCT opo.bed_id) as bed_count,
                GROUP_CONCAT(DISTINCT opo.bed_id) as bed_ids
             FROM orders o
             INNER JOIN orders_to_patient_options opo ON o.order_id = opo.order_id
             INNER JOIN menuDetails md ON md.id = opo.menu_id
             INNER JOIN menu_options mo ON mo.id = opo.option_id
             LEFT  JOIN foodmenuconfig fmc ON fmc.id = opo.category_id
             WHERE DATE(o.date) = ?
               AND o.buttonType = 'sendorder'
               AND (opo.is_cancelled = 0 OR opo.is_cancelled IS NULL)
               AND opo.menu_id IS NOT NULL
             GROUP BY opo.category_id, md.id, opo.option_id
             HAVING total_qty > 0
             ORDER BY fmc.sort_order, md.sort_order",
            [$date]
        );
    }

    /** View details / packaging query */
    protected function fetchPatientwiseOrders(string $date, ?int $suiteId = null): array
    {
        $sql = "SELECT 
                    opo.id, opo.order_id, opo.bed_id, opo.patient_id,
                    opo.category_id, opo.menu_id, opo.option_id,
                    opo.quantity, opo.is_cancelled,
                    o.date as order_date,
                    s.bed_no as suite_number,
                    p.name as patient_name
                FROM orders_to_patient_options opo
                INNER JOIN orders o ON o.order_id = opo.order_id
                LEFT JOIN suites s ON s.id = opo.bed_id
                LEFT JOIN people p ON p.id = opo.patient_id
                WHERE DATE(o.date) = ?
                  AND o.buttonType = 'sendorder'
                  AND (opo.is_cancelled = 0 OR opo.is_cancelled IS NULL)";
        $params = [$date];

        if ($suiteId !== null) {
            $sql .= " AND opo.bed_id = ?";
            $params[] = $suiteId;
        }

        return $this->query($sql, $params);
    }

    /** Cancelled orders report (mirrors Reports::getCancelledOrderItems) */
    protected function fetchCancelledItems(string $from, string $to): array
    {
        return $this->query(
            "SELECT 
                opo.id, opo.order_id, opo.bed_id,
                opo.is_cancelled, opo.cancel_reason, opo.cancelled_at,
                opo.patient_name_snapshot, opo.suite_name_snapshot,
                o.date as order_date,
                s.bed_no as suite_number
             FROM orders_to_patient_options opo
             LEFT JOIN orders o ON o.order_id = opo.order_id
             LEFT JOIN suites s ON s.id = opo.bed_id
             WHERE opo.is_cancelled = 1
               AND (
                   (opo.cancelled_at >= ? AND opo.cancelled_at <= ?)
                   OR (opo.cancelled_at IS NULL AND o.date >= ? AND o.date <= ?)
               )
             ORDER BY COALESCE(opo.cancelled_at, o.date) DESC",
            ["{$from} 00:00:00", "{$to} 23:59:59", $from, $to]
        );
    }

    /**
     * Beds serviced per day (mirrors Reports::getBedsServicedPerDay).
     * Counts distinct (bed_id, patient_id) pairs — two different patients
     * served in the same bed on the same day count as 2 beds serviced.
     */
    protected function fetchBedsServicedPerDay(string $from, string $to): array
    {
        return $this->query(
            "SELECT 
                o.date as order_date,
                COUNT(DISTINCT opo.bed_id, opo.patient_id) as beds_count
             FROM orders o
             INNER JOIN orders_to_patient_options opo ON opo.order_id = o.order_id
             INNER JOIN suites s ON s.id = opo.bed_id
             WHERE o.date >= ? AND o.date <= ?
               AND o.status != 0
               AND s.is_deleted = 0
               AND s.status = 1
             GROUP BY o.date
             ORDER BY o.date ASC",
            [$from, $to]
        );
    }

    // ── LOW-LEVEL DB HELPERS ────────────────────────────────────────

    protected function insert(string $table, array $data): int
    {
        $cols = implode('`, `', array_keys($data));
        $placeholders = implode(', ', array_fill(0, count($data), '?'));
        $stmt = self::$link->prepare("INSERT INTO `{$table}` (`{$cols}`) VALUES ({$placeholders})");
        if (!$stmt) {
            throw new \RuntimeException("Prepare failed [{$table}]: " . self::$link->error);
        }
        $types = '';
        $vals  = [];
        foreach ($data as $v) {
            if (is_int($v))       { $types .= 'i'; }
            elseif (is_float($v)) { $types .= 'd'; }
            else                  { $types .= 's'; }
            $vals[] = $v;
        }
        $stmt->bind_param($types, ...$vals);
        if (!$stmt->execute()) {
            $err = $stmt->error;
            $stmt->close();
            throw new \RuntimeException("Execute failed [{$table}]: {$err}");
        }
        $id = $stmt->insert_id;
        $stmt->close();
        return $id;
    }

    protected function update(string $table, array $data, string $whereCol, $whereVal): void
    {
        $setParts = [];
        foreach ($data as $k => $v) {
            if ($v === null) {
                $setParts[] = "`{$k}` = NULL";
            } else {
                $escaped = self::$link->real_escape_string((string) $v);
                $setParts[] = "`{$k}` = '{$escaped}'";
            }
        }
        $setStr  = implode(', ', $setParts);
        $escaped = self::$link->real_escape_string((string) $whereVal);
        self::$link->query("UPDATE `{$table}` SET {$setStr} WHERE `{$whereCol}` = '{$escaped}'");
    }

    protected function fetchRow(string $table, string $col, $val): ?array
    {
        $escaped = self::$link->real_escape_string((string) $val);
        $result  = self::$link->query("SELECT * FROM `{$table}` WHERE `{$col}` = '{$escaped}' LIMIT 1");
        return $result ? ($result->fetch_assoc() ?: null) : null;
    }

    protected function fetchAll(string $table, string $col, $val): array
    {
        $escaped = self::$link->real_escape_string((string) $val);
        $result  = self::$link->query("SELECT * FROM `{$table}` WHERE `{$col}` = '{$escaped}'");
        $rows = [];
        if ($result) { while ($r = $result->fetch_assoc()) { $rows[] = $r; } }
        return $rows;
    }

    protected function query(string $sql, array $params = []): array
    {
        if (empty($params)) {
            $result = self::$link->query($sql);
        } else {
            $stmt = self::$link->prepare($sql);
            if (!$stmt) {
                throw new \RuntimeException("Prepare failed: " . self::$link->error . "\nSQL: $sql");
            }
            $types = '';
            foreach ($params as $p) {
                $types .= is_int($p) ? 'i' : 's';
            }
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $result = $stmt->get_result();
            $stmt->close();
        }
        $rows = [];
        if ($result) { while ($r = $result->fetch_assoc()) { $rows[] = $r; } }
        return $rows;
    }

    protected function countRows(string $table, array $conditions = []): int
    {
        $sql = "SELECT COUNT(*) as cnt FROM `{$table}`";
        if (!empty($conditions)) {
            $parts = [];
            foreach ($conditions as $k => $v) {
                if ($v === null) {
                    $parts[] = "`{$k}` IS NULL";
                } else {
                    $escaped = self::$link->real_escape_string((string) $v);
                    $parts[] = "`{$k}` = '{$escaped}'";
                }
            }
            $sql .= ' WHERE ' . implode(' AND ', $parts);
        }
        $result = self::$link->query($sql);
        return $result ? (int) $result->fetch_assoc()['cnt'] : 0;
    }

    // ── TIME HELPERS ────────────────────────────────────────────────

    protected function ausNow(): string
    {
        return (new \DateTime('now', new \DateTimeZone('Australia/Sydney')))->format('Y-m-d H:i:s');
    }

    protected function ausToday(): string
    {
        return (new \DateTime('now', new \DateTimeZone('Australia/Sydney')))->format('Y-m-d');
    }

    protected function ausTomorrow(): string
    {
        return (new \DateTime('+1 day', new \DateTimeZone('Australia/Sydney')))->format('Y-m-d');
    }

    protected function ausYesterday(): string
    {
        return (new \DateTime('-1 day', new \DateTimeZone('Australia/Sydney')))->format('Y-m-d');
    }
}
