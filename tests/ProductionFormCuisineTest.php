<?php
/**
 * Production Form – Cuisine Type Display Tests
 * =============================================
 * Validates that the production form query returns cuisineValues per
 * menu option so the chef knows which variation to cook, with correct
 * aggregated counts grouped by option_id (i.e., by cuisine variation).
 */

require_once __DIR__ . '/Bootstrap.php';

class ProductionFormCuisineTest extends CITestCase
{
    // Cuisine type IDs (seeded in setUp)
    private const CUISINE_REGULAR  = 84;
    private const CUISINE_HALAL    = 85;
    private const CUISINE_VEGAN    = 86;

    // Additional menu option IDs for variations
    private const OPT_REGULAR  = 10;
    private const OPT_HALAL    = 11;
    private const OPT_VEGAN    = 12;
    private const OPT_MULTI    = 13; // option with multiple cuisines

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCuisineData();
    }

    /**
     * Seed cuisine types and menu option variations for testing.
     */
    private function seedCuisineData(): void
    {
        // Cuisine types in foodmenuconfig
        $this->insert('foodmenuconfig', [
            'id' => self::CUISINE_REGULAR, 'name' => 'Regular',
            'listtype' => 'cuisine', 'sort_order' => 1, 'is_deleted' => 0, 'location_id' => 1,
        ]);
        $this->insert('foodmenuconfig', [
            'id' => self::CUISINE_HALAL, 'name' => 'Halal',
            'listtype' => 'cuisine', 'sort_order' => 2, 'is_deleted' => 0, 'location_id' => 1,
        ]);
        $this->insert('foodmenuconfig', [
            'id' => self::CUISINE_VEGAN, 'name' => 'Vegan',
            'listtype' => 'cuisine', 'sort_order' => 3, 'is_deleted' => 0, 'location_id' => 1,
        ]);

        // Menu option variations: same name, different cuisine
        $this->insert('menu_options', [
            'id' => self::OPT_REGULAR, 'menu_option_name' => 'Fried Eggs on Toast',
            'menu_color' => '#FF0000', 'status' => 1, 'is_deleted' => 0,
            'location_id' => 1,
            'cuisineValues' => json_encode([(string)self::CUISINE_REGULAR]),
        ]);
        $this->insert('menu_options', [
            'id' => self::OPT_HALAL, 'menu_option_name' => 'Fried Eggs on Toast',
            'menu_color' => '#00FF00', 'status' => 1, 'is_deleted' => 0,
            'location_id' => 1,
            'cuisineValues' => json_encode([(string)self::CUISINE_HALAL]),
        ]);
        $this->insert('menu_options', [
            'id' => self::OPT_VEGAN, 'menu_option_name' => 'Scrambled Tofu',
            'menu_color' => '#0000FF', 'status' => 1, 'is_deleted' => 0,
            'location_id' => 1,
            'cuisineValues' => json_encode([(string)self::CUISINE_VEGAN]),
        ]);
        $this->insert('menu_options', [
            'id' => self::OPT_MULTI, 'menu_option_name' => 'Porridge',
            'menu_color' => '#AAAAAA', 'status' => 1, 'is_deleted' => 0,
            'location_id' => 1,
            'cuisineValues' => json_encode([
                (string)self::CUISINE_REGULAR,
                (string)self::CUISINE_HALAL,
            ]),
        ]);
    }

    /**
     * Place an order line with a specific option_id and quantity.
     */
    private function placeOrderLine(int $suiteId, string $date, int $categoryId, int $menuId, int $optionId, int $qty = 1, ?int $patientId = null): int
    {
        // Reuse or create floor order
        $existing = $this->query(
            "SELECT order_id FROM orders WHERE date = ? AND dept_id = 1 AND is_floor_consolidated = 1 AND status != 0 LIMIT 1",
            [$date]
        );
        if (!empty($existing)) {
            $orderId = (int)$existing[0]['order_id'];
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

        $this->insert('orders_to_patient_options', [
            'order_id'              => $orderId,
            'suite_order_detail_id' => $sodId,
            'bed_id'                => $suiteId,
            'patient_id'            => $patientId,
            'category_id'           => $categoryId,
            'menu_id'               => $menuId,
            'option_id'             => $optionId,
            'quantity'              => $qty,
            'status'                => 0,
            'is_cancelled'          => 0,
        ]);

        return $orderId;
    }

    // ── TEST: cuisineValues is returned in production form query ────

    public function testCuisineValuesReturnedInProductionForm(): void
    {
        $today = $this->ausToday();

        // Place order for Regular variation
        $this->placeOrderLine(100, $today, self::BREAKFAST, 1, self::OPT_REGULAR, 2);

        $rows = $this->fetchProductionForm($today);

        $this->assertCount(1, $rows, 'Should have exactly 1 production row');
        $this->assertArrayHasKey('cuisineValues', $rows[0], 'Row must include cuisineValues column');
        $decoded = json_decode($rows[0]['cuisineValues'], true);
        $this->assertIsArray($decoded);
        $this->assertContains((string)self::CUISINE_REGULAR, $decoded);
    }

    // ── TEST: Different cuisine variations produce separate rows ────

    public function testDifferentCuisineVariationsAreSeparateRows(): void
    {
        $today = $this->ausToday();

        // Room-101 orders Regular variant
        $this->placeOrderLine(100, $today, self::BREAKFAST, 1, self::OPT_REGULAR, 1);
        // Room-202 orders Halal variant (same menu name, different option_id)
        $this->placeOrderLine(200, $today, self::BREAKFAST, 1, self::OPT_HALAL, 1);

        $rows = $this->fetchProductionForm($today);

        $this->assertCount(2, $rows, 'Two different cuisine variations = two separate production rows');

        // Index by option_id for easy assertion
        $byOption = [];
        foreach ($rows as $r) { $byOption[(int)$r['option_id']] = $r; }

        $this->assertArrayHasKey(self::OPT_REGULAR, $byOption);
        $this->assertArrayHasKey(self::OPT_HALAL, $byOption);

        // Each should have qty=1
        $this->assertEquals(1, (int)$byOption[self::OPT_REGULAR]['total_qty']);
        $this->assertEquals(1, (int)$byOption[self::OPT_HALAL]['total_qty']);

        // Cuisine values correct
        $regularCuisine = json_decode($byOption[self::OPT_REGULAR]['cuisineValues'], true);
        $this->assertContains((string)self::CUISINE_REGULAR, $regularCuisine);

        $halalCuisine = json_decode($byOption[self::OPT_HALAL]['cuisineValues'], true);
        $this->assertContains((string)self::CUISINE_HALAL, $halalCuisine);
    }

    // ── TEST: Quantities aggregate correctly per variation ──────────

    public function testQuantitiesAggregatePerVariation(): void
    {
        $today = $this->ausToday();

        // 3 rooms ordering Regular variant
        $this->placeOrderLine(100, $today, self::BREAKFAST, 1, self::OPT_REGULAR, 2);
        $this->placeOrderLine(200, $today, self::BREAKFAST, 1, self::OPT_REGULAR, 3);

        // 1 room ordering Halal variant
        $this->placeOrderLine(100, $today, self::BREAKFAST, 1, self::OPT_HALAL, 1);

        $rows = $this->fetchProductionForm($today);

        $byOption = [];
        foreach ($rows as $r) { $byOption[(int)$r['option_id']] = $r; }

        // Regular: 2 + 3 = 5
        $this->assertEquals(5, (int)$byOption[self::OPT_REGULAR]['total_qty'],
            'Regular aggregated qty should be 5 (2+3)');

        // Halal: 1
        $this->assertEquals(1, (int)$byOption[self::OPT_HALAL]['total_qty'],
            'Halal aggregated qty should be 1');
    }

    // ── TEST: Multiple cuisine IDs on a single option ───────────────

    public function testMultipleCuisineIdsOnSingleOption(): void
    {
        $today = $this->ausToday();

        $this->placeOrderLine(100, $today, self::BREAKFAST, 1, self::OPT_MULTI, 4);

        $rows = $this->fetchProductionForm($today);

        $this->assertCount(1, $rows);
        $decoded = json_decode($rows[0]['cuisineValues'], true);
        $this->assertIsArray($decoded);
        $this->assertCount(2, $decoded, 'Option should have 2 cuisine IDs (Regular + Halal)');
        $this->assertContains((string)self::CUISINE_REGULAR, $decoded);
        $this->assertContains((string)self::CUISINE_HALAL, $decoded);
    }

    // ── TEST: Null cuisineValues handled gracefully ─────────────────

    public function testNullCuisineValuesReturnsNull(): void
    {
        $today = $this->ausToday();

        // The base seed option (id=1) has no cuisineValues column set (NULL)
        $this->placeOrderLine(100, $today, self::BREAKFAST, 1, 1, 1);

        $rows = $this->fetchProductionForm($today);

        $this->assertCount(1, $rows);
        // cuisineValues should be NULL (not crash or missing)
        $this->assertArrayHasKey('cuisineValues', $rows[0]);
        // The controller uses ?? '[]' fallback — simulate that here
        $raw = $rows[0]['cuisineValues'] ?? '[]';
        $decoded = json_decode($raw, true);
        $this->assertIsArray($decoded);
        $this->assertEmpty($decoded, 'Null cuisineValues should decode to empty array');
    }

    // ── TEST: Cancelled orders excluded from cuisine counts ─────────

    public function testCancelledOrdersExcludedFromCuisineCounts(): void
    {
        $today = $this->ausToday();

        // Place 3 units of Regular
        $this->placeOrderLine(100, $today, self::BREAKFAST, 1, self::OPT_REGULAR, 3);
        // Place 2 units of Halal
        $this->placeOrderLine(200, $today, self::BREAKFAST, 1, self::OPT_HALAL, 2);

        // Cancel the Halal order
        self::$link->query(
            "UPDATE orders_to_patient_options SET is_cancelled = 1 WHERE option_id = " . self::OPT_HALAL
        );

        $rows = $this->fetchProductionForm($today);

        // Only Regular should remain
        $this->assertCount(1, $rows, 'Cancelled Halal variation should be excluded');
        $this->assertEquals(self::OPT_REGULAR, (int)$rows[0]['option_id']);
        $this->assertEquals(3, (int)$rows[0]['total_qty']);
    }

    // ── TEST: Cross-category variations (same option in multiple meals) ──

    public function testSameVariationAcrossMealCategories(): void
    {
        $today = $this->ausToday();

        // Regular variant ordered in Breakfast AND Lunch (different categories)
        $this->placeOrderLine(100, $today, self::BREAKFAST, 1, self::OPT_REGULAR, 2);
        $this->placeOrderLine(100, $today, self::LUNCH, 2, self::OPT_REGULAR, 1);

        $rows = $this->fetchProductionForm($today);

        // Grouped by category_id + option_id, so should be 2 separate rows
        $this->assertCount(2, $rows, 'Same option in different meal categories = separate rows');

        $totalQty = 0;
        foreach ($rows as $r) {
            $this->assertEquals(self::OPT_REGULAR, (int)$r['option_id']);
            $cuisine = json_decode($r['cuisineValues'], true);
            $this->assertContains((string)self::CUISINE_REGULAR, $cuisine);
            $totalQty += (int)$r['total_qty'];
        }
        $this->assertEquals(3, $totalQty, 'Total across categories should be 3 (2+1)');
    }

    // ── TEST: Cuisine map lookup matches IDs to names ───────────────

    public function testCuisineMapLookup(): void
    {
        // Simulate what the controller does: build cuisineMap from foodmenuconfig
        $cuisineList = $this->query(
            "SELECT id, name FROM foodmenuconfig WHERE listtype = 'cuisine' AND is_deleted = 0"
        );

        $cuisineMap = [];
        foreach ($cuisineList as $c) {
            $cuisineMap[$c['id']] = $c['name'];
        }

        $this->assertArrayHasKey((string)self::CUISINE_REGULAR, $cuisineMap);
        $this->assertArrayHasKey((string)self::CUISINE_HALAL, $cuisineMap);
        $this->assertArrayHasKey((string)self::CUISINE_VEGAN, $cuisineMap);

        $this->assertEquals('Regular', $cuisineMap[(string)self::CUISINE_REGULAR]);
        $this->assertEquals('Halal', $cuisineMap[(string)self::CUISINE_HALAL]);
        $this->assertEquals('Vegan', $cuisineMap[(string)self::CUISINE_VEGAN]);
    }

    // ── TEST: View rendering logic (cuisine badge generation) ───────

    public function testCuisineBadgeRendering(): void
    {
        // Simulate the exact PHP logic from viewPatientOrder.php
        $cuisineMap = [
            (string)self::CUISINE_REGULAR => 'Regular',
            (string)self::CUISINE_HALAL   => 'Halal',
            (string)self::CUISINE_VEGAN   => 'Vegan',
        ];

        // Case 1: JSON string with cuisine IDs
        $rawCuisine = json_encode([(string)self::CUISINE_HALAL]);
        $cuisineIds = is_string($rawCuisine) ? json_decode($rawCuisine, true) : (is_array($rawCuisine) ? $rawCuisine : []);
        $names = [];
        foreach ($cuisineIds as $cid) {
            if (isset($cuisineMap[$cid])) { $names[] = $cuisineMap[$cid]; }
        }
        $this->assertEquals(['Halal'], $names, 'Should resolve Halal from cuisine ID');

        // Case 2: Multiple cuisine IDs
        $rawCuisine = json_encode([(string)self::CUISINE_REGULAR, (string)self::CUISINE_HALAL]);
        $cuisineIds = is_string($rawCuisine) ? json_decode($rawCuisine, true) : (is_array($rawCuisine) ? $rawCuisine : []);
        $names = [];
        foreach ($cuisineIds as $cid) {
            if (isset($cuisineMap[$cid])) { $names[] = $cuisineMap[$cid]; }
        }
        $this->assertEquals(['Regular', 'Halal'], $names, 'Should resolve both Regular and Halal');

        // Case 3: Null / empty
        $rawCuisine = null;
        $raw = $rawCuisine ?? '[]';
        $cuisineIds = is_string($raw) ? json_decode($raw, true) : (is_array($raw) ? $raw : []);
        $this->assertEmpty($cuisineIds, 'Null cuisineValues with ?? fallback should be empty array');

        // Case 4: Already decoded array (edge case)
        $rawCuisine = [(string)self::CUISINE_VEGAN];
        $cuisineIds = is_string($rawCuisine) ? json_decode($rawCuisine, true) : (is_array($rawCuisine) ? $rawCuisine : []);
        $names = [];
        foreach ($cuisineIds as $cid) {
            if (isset($cuisineMap[$cid])) { $names[] = $cuisineMap[$cid]; }
        }
        $this->assertEquals(['Vegan'], $names, 'Should handle already-decoded array');
    }

    // ── TEST: Bed count is correct per variation ────────────────────

    public function testBedCountPerVariation(): void
    {
        $today = $this->ausToday();

        // 2 different beds ordering the same Regular variant
        $this->placeOrderLine(100, $today, self::BREAKFAST, 1, self::OPT_REGULAR, 1);
        $this->placeOrderLine(200, $today, self::BREAKFAST, 1, self::OPT_REGULAR, 2);

        // 1 bed for Halal
        $this->placeOrderLine(100, $today, self::BREAKFAST, 1, self::OPT_HALAL, 1);

        $rows = $this->fetchProductionForm($today);

        $byOption = [];
        foreach ($rows as $r) { $byOption[(int)$r['option_id']] = $r; }

        $this->assertEquals(2, (int)$byOption[self::OPT_REGULAR]['bed_count'],
            'Regular variant ordered from 2 beds');
        $this->assertEquals(1, (int)$byOption[self::OPT_HALAL]['bed_count'],
            'Halal variant ordered from 1 bed');
    }
}
