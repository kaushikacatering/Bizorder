<?php
/**
 * CommonItemFeatureTest
 * =====================
 * Tests the "Common Item" feature AND allergen/cuisine filtering pipeline.
 *
 * Covers:
 * 1. Database schema (is_common_item column)
 * 2. Data format: how allergenValues/cuisineValues survive the JSON_OBJECT → json_decode → json_encode pipeline
 * 3. Allergen filtering logic (mirrors dashboard JS)
 * 4. Cuisine filtering logic (mirrors dashboard JS)
 * 5. Common item behavior: skip cuisine, keep allergen filtering
 * 6. Edge cases: NULL, empty, malformed data
 *
 * Run:  vendor/bin/phpunit tests/CommonItemFeatureTest.php
 */
class CommonItemFeatureTest extends CITestCase
{
    // Cuisine type constants
    protected const CUISINE_GLUTEN_FREE    = 50;
    protected const CUISINE_DAIRY_FREE     = 51;
    protected const CUISINE_LACTOSE_FREE   = 52;
    protected const CUISINE_LOW_SODIUM     = 53;
    protected const CUISINE_STANDARD       = 54;

    // Allergen constants
    protected const ALLERGEN_EGG     = 60;
    protected const ALLERGEN_NUTS    = 61;
    protected const ALLERGEN_DAIRY   = 62;
    protected const ALLERGEN_LACTOSE = 63;
    protected const ALLERGEN_WHEAT   = 64;

    /**
     * Seed cuisine types and allergens into foodmenuconfig.
     */
    protected function seedConfig(): void
    {
        $cuisines = [
            [self::CUISINE_GLUTEN_FREE,  'Gluten Free',  1],
            [self::CUISINE_DAIRY_FREE,   'Dairy Free',   2],
            [self::CUISINE_LACTOSE_FREE, 'Lactose Free', 3],
            [self::CUISINE_LOW_SODIUM,   'Low Sodium',   4],
            [self::CUISINE_STANDARD,     'Standard',     5],
        ];
        foreach ($cuisines as [$id, $name, $sort]) {
            $this->insert('foodmenuconfig', [
                'id' => $id, 'name' => $name,
                'listtype' => 'cuisine', 'sort_order' => $sort,
                'is_deleted' => 0, 'location_id' => 1, 'created_date' => '2026-01-01',
            ]);
        }
        $allergens = [
            [self::ALLERGEN_EGG,     'Egg',     1],
            [self::ALLERGEN_NUTS,    'Nuts',    2],
            [self::ALLERGEN_DAIRY,   'Dairy',   3],
            [self::ALLERGEN_LACTOSE, 'Lactose', 4],
            [self::ALLERGEN_WHEAT,   'Wheat',   5],
        ];
        foreach ($allergens as [$id, $name, $sort]) {
            $this->insert('foodmenuconfig', [
                'id' => $id, 'name' => $name,
                'listtype' => 'allergen', 'sort_order' => $sort,
                'is_deleted' => 0, 'location_id' => 1, 'created_date' => '2026-01-01',
            ]);
        }
    }

    // ── HELPERS ─────────────────────────────────────────────────────

    protected function createMenuItem(int $id, string $name, bool $isCommonItem = false): void
    {
        $this->insert('menuDetails', [
            'id' => $id, 'name' => $name, 'sort_order' => $id,
            'category' => self::BREAKFAST, 'status' => 1, 'is_deleted' => 0,
            'is_common_item' => $isCommonItem ? 1 : 0,
            'location_id' => 1, 'date_created' => '2026-01-01', 'date_updated' => '2026-01-01',
            'displayOnDashbord' => 1,
        ]);
    }

    protected function insertMenuOption(int $menuDetailId, string $name, array $cuisineIds = [], array $allergenIds = []): int
    {
        $optionId = $this->insert('menu_options', [
            'menu_option_name' => $name,
            'cuisineValues' => json_encode(array_map('strval', $cuisineIds)),
            'description' => '',
            'nutritionValues' => '',
            'allergenValues' => json_encode(array_map('strval', $allergenIds)),
            'status' => 1, 'is_deleted' => 0, 'location_id' => 1,
            'date_created' => '2026-01-01', 'date_updated' => '2026-01-01',
        ]);
        $this->insert('menu_details_to_menu_options', [
            'main_menu_id' => $menuDetailId, 'menu_option_id' => $optionId,
        ]);
        return $optionId;
    }

    protected function getOptionsByMenu(int $menuDetailId): array
    {
        return $this->query(
            "SELECT mo.id, mo.menu_option_name, mo.description,
                    mo.cuisineValues AS cuisine_type_ids,
                    mo.nutritionValues AS nutritional_values,
                    mo.allergenValues
             FROM menu_options mo
             JOIN menu_details_to_menu_options mdto ON mdto.menu_option_id = mo.id
             WHERE mdto.main_menu_id = ? AND mo.status = 1 AND mo.is_deleted = 0
             ORDER BY mo.id ASC",
            [$menuDetailId]
        );
    }

    protected function getMenuDetail(int $id): ?array
    {
        return $this->fetchRow('menuDetails', 'id', $id);
    }

    /**
     * Simulate the EXACT data pipeline: MySQL JSON_OBJECT → PHP json_decode → PHP json_encode → parse
     * This catches double-encoding issues that would make filtering fail in JS.
     */
    protected function fetchMenuOptionsViaJsonPipeline(int $menuDetailId): array
    {
        // This mirrors the EXACT query in Menu_model::fetchMenuDetails
        $rows = $this->query(
            "SELECT
                md.id AS menu_id,
                md.name AS menu_name,
                md.is_common_item,
                JSON_ARRAYAGG(
                    JSON_OBJECT(
                        'option_id', mo.id,
                        'menu_option_name', mo.menu_option_name,
                        'allergenValues', mo.allergenValues,
                        'cuisineValues', mo.cuisineValues
                    )
                ) AS menu_options
             FROM menuDetails md
             LEFT JOIN menu_details_to_menu_options mdto ON mdto.main_menu_id = md.id
             LEFT JOIN menu_options mo ON mdto.menu_option_id = mo.id AND mo.status = 1 AND mo.is_deleted = 0
             WHERE md.id = ?
             GROUP BY md.id",
            [$menuDetailId]
        );

        if (empty($rows)) return [];

        $row = $rows[0];
        // Step 1: PHP json_decode (like the model does)
        $options = json_decode($row['menu_options'], true) ?: [];
        
        // Step 2: PHP json_encode then json_decode (simulates the PHP→JS transfer)
        $jsonForJs = json_encode($options);
        $jsOptions = json_decode($jsonForJs, true);

        return [
            'menu_id' => $row['menu_id'],
            'menu_name' => $row['menu_name'],
            'is_common_item' => $row['is_common_item'],
            'options' => $jsOptions,
        ];
    }

    /**
     * Mirrors the dashboard JS: menuHasMatchingVariation()
     * Checks if ANY variation passes cuisine + allergen checks.
     */
    protected function menuHasMatchingVariation(array $variations, array $patientCuisineIds, array $patientAllergyIds = [], bool $isCommonItem = false): bool
    {
        if (empty($variations)) return true;

        $patientIds = array_map('strval', $patientCuisineIds);
        sort($patientIds);
        $allergyIds = array_map('strval', $patientAllergyIds);

        foreach ($variations as $v) {
            if (!$isCommonItem) {
                $rawCuisine = $v['cuisine_type_ids'] ?? $v['cuisineValues'] ?? '[]';
                $vCuisineIds = is_string($rawCuisine) ? (json_decode($rawCuisine, true) ?: []) : ($rawCuisine ?: []);
                $vCuisineStrs = array_map('strval', $vCuisineIds);
                sort($vCuisineStrs);

                if (empty($patientIds)) {
                    if (!empty($vCuisineStrs)) continue;
                } else {
                    if (count($vCuisineStrs) !== count($patientIds)) continue;
                    if ($patientIds !== $vCuisineStrs) continue;
                }
            }

            if (!empty($allergyIds)) {
                $rawAllergen = $v['allergenValues'] ?? '[]';
                $vAllergenIds = is_string($rawAllergen) ? (json_decode($rawAllergen, true) ?: []) : ($rawAllergen ?: []);
                if (!empty($vAllergenIds)) {
                    $conflict = !empty(array_intersect(array_map('strval', $vAllergenIds), $allergyIds));
                    if ($conflict) continue;
                }
            }

            return true;
        }
        return false;
    }

    /**
     * Mirrors the dashboard JS option-level filter.
     * Returns only options that pass cuisine + allergen checks.
     */
    protected function filterOptionsForPatient(array $options, array $patientCuisineIds, array $patientAllergyIds = [], bool $isCommonItem = false): array
    {
        $patientSet = array_map('strval', $patientCuisineIds);
        sort($patientSet);
        $allergyIds = array_map('strval', $patientAllergyIds);

        return array_values(array_filter($options, function($option) use ($patientSet, $allergyIds, $isCommonItem) {
            // CUISINE FILTER
            $matchesCuisine = true;
            if (!$isCommonItem) {
                $rawCuisine = $option['cuisine_type_ids'] ?? $option['cuisineValues'] ?? '[]';
                $itemCuisines = is_string($rawCuisine) ? (json_decode($rawCuisine, true) ?: []) : ($rawCuisine ?: []);
                $itemSet = array_map('strval', $itemCuisines);
                sort($itemSet);

                if (empty($patientSet)) {
                    $matchesCuisine = empty($itemSet);
                } else {
                    $matchesCuisine = ($patientSet === $itemSet);
                }
            }

            // ALLERGEN FILTER (always applies)
            $matchesAllergen = true;
            if (!empty($allergyIds)) {
                $rawAllergen = $option['allergenValues'] ?? '[]';
                $itemAllergens = is_string($rawAllergen) ? (json_decode($rawAllergen, true) ?: []) : ($rawAllergen ?: []);
                if (!empty($itemAllergens)) {
                    $conflict = !empty(array_intersect(array_map('strval', $itemAllergens), $allergyIds));
                    $matchesAllergen = !$conflict;
                }
            }

            return $matchesCuisine && $matchesAllergen;
        }));
    }

    // ═════════════════════════════════════════════════════════════════
    // 1. DATABASE SCHEMA TESTS
    // ═════════════════════════════════════════════════════════════════

    public function testColumnExists()
    {
        $columns = $this->query("SHOW COLUMNS FROM menuDetails LIKE 'is_common_item'");
        $this->assertCount(1, $columns, 'is_common_item column must exist');
        $this->assertEquals('0', $columns[0]['Default']);
    }

    public function testExistingMenusDefaultToNotCommon()
    {
        $menu = $this->getMenuDetail(1);
        $this->assertEquals(0, (int)$menu['is_common_item']);
    }

    public function testCreateAndToggleCommonItem()
    {
        $this->createMenuItem(10, 'Common Menu', true);
        $this->assertEquals(1, (int)$this->getMenuDetail(10)['is_common_item']);

        $this->update('menuDetails', ['is_common_item' => 0], 'id', 10);
        $this->assertEquals(0, (int)$this->getMenuDetail(10)['is_common_item']);
    }

    // ═════════════════════════════════════════════════════════════════
    // 2. DATA FORMAT / JSON PIPELINE TESTS
    // These catch double-encoding bugs that break JS filtering
    // ═════════════════════════════════════════════════════════════════

    /**
     * Verify allergenValues survives MySQL JSON_OBJECT → PHP json_decode → PHP json_encode pipeline.
     * This is the EXACT path data takes from DB to JavaScript.
     */
    public function testAllergenValues_SurviveJsonPipeline()
    {
        $this->seedConfig();
        $this->createMenuItem(10, 'Test Menu');
        $this->insertMenuOption(10, 'Egg Dish', [], [self::ALLERGEN_EGG, self::ALLERGEN_WHEAT]);

        $result = $this->fetchMenuOptionsViaJsonPipeline(10);
        $this->assertNotEmpty($result['options']);

        $option = $result['options'][0];
        // After the full pipeline, allergenValues should be parseable
        $allergens = is_string($option['allergenValues'])
            ? json_decode($option['allergenValues'], true)
            : $option['allergenValues'];
        $this->assertIsArray($allergens, 'allergenValues must be a parseable array after pipeline');
        $this->assertContains(strval(self::ALLERGEN_EGG), array_map('strval', $allergens), 'Egg allergen ID must survive pipeline');
        $this->assertContains(strval(self::ALLERGEN_WHEAT), array_map('strval', $allergens), 'Wheat allergen ID must survive pipeline');
    }

    /**
     * Verify cuisineValues survives the JSON pipeline.
     */
    public function testCuisineValues_SurviveJsonPipeline()
    {
        $this->seedConfig();
        $this->createMenuItem(10, 'Test Menu');
        $this->insertMenuOption(10, 'GF Dish', [self::CUISINE_GLUTEN_FREE, self::CUISINE_DAIRY_FREE], []);

        $result = $this->fetchMenuOptionsViaJsonPipeline(10);
        $option = $result['options'][0];

        $cuisines = is_string($option['cuisineValues'])
            ? json_decode($option['cuisineValues'], true)
            : $option['cuisineValues'];
        $this->assertIsArray($cuisines);
        $this->assertCount(2, $cuisines);
    }

    /**
     * Verify NULL allergenValues is handled correctly through pipeline.
     */
    public function testNullAllergenValues_Pipeline()
    {
        $this->seedConfig();
        $this->createMenuItem(10, 'Test Menu');
        // Insert option with NULL allergenValues directly
        $optId = $this->insert('menu_options', [
            'menu_option_name' => 'No Allergens Set',
            'cuisineValues' => '[]', 'allergenValues' => NULL,
            'status' => 1, 'is_deleted' => 0, 'location_id' => 1,
            'date_created' => '2026-01-01', 'date_updated' => '2026-01-01',
        ]);
        $this->insert('menu_details_to_menu_options', ['main_menu_id' => 10, 'menu_option_id' => $optId]);

        $result = $this->fetchMenuOptionsViaJsonPipeline(10);
        $option = $result['options'][0];

        // NULL allergenValues should be null or parseable to empty
        $allergens = $option['allergenValues'];
        if (is_string($allergens)) {
            $parsed = json_decode($allergens, true);
            $this->assertTrue($parsed === null || $parsed === [], 'NULL allergenValues should decode to null or empty');
        } else {
            $this->assertNull($allergens, 'NULL allergenValues should be null after pipeline');
        }
    }

    /**
     * Verify empty array allergenValues is handled correctly.
     */
    public function testEmptyAllergenValues_Pipeline()
    {
        $this->seedConfig();
        $this->createMenuItem(10, 'Test Menu');
        $this->insertMenuOption(10, 'Safe Dish', [], []);

        $result = $this->fetchMenuOptionsViaJsonPipeline(10);
        $option = $result['options'][0];

        $allergens = is_string($option['allergenValues'])
            ? json_decode($option['allergenValues'], true)
            : $option['allergenValues'];
        $this->assertIsArray($allergens);
        $this->assertEmpty($allergens);
    }

    // ═════════════════════════════════════════════════════════════════
    // 3. ALLERGEN FILTERING TESTS (the reported production bug)
    // ═════════════════════════════════════════════════════════════════

    /**
     * USER'S EXACT SCENARIO: Patient has Egg allergy.
     * Egg dishes with Egg allergen MUST be hidden.
     */
    public function testAllergen_EggPatient_EggDishesHidden()
    {
        $this->seedConfig();
        $this->createMenuItem(10, 'Breakfast Main Option');

        // Create egg dishes with Egg allergen (like production)
        $this->insertMenuOption(10, 'Shakshuka Fried Egg', [], [self::ALLERGEN_EGG, self::ALLERGEN_WHEAT]);
        $this->insertMenuOption(10, 'Poached Egg on Toast', [], [self::ALLERGEN_EGG, self::ALLERGEN_WHEAT]);
        $this->insertMenuOption(10, 'Breakfast Frittata', [], [self::ALLERGEN_EGG]);
        // Non-egg dish
        $this->insertMenuOption(10, 'Porridge', [], []);

        $options = $this->getOptionsByMenu(10);
        $this->assertCount(4, $options, 'Should have 4 options');

        // Patient with Egg allergy, no dietary preferences
        $visible = $this->filterOptionsForPatient($options, [], [self::ALLERGEN_EGG]);
        $visibleNames = array_column($visible, 'menu_option_name');

        $this->assertCount(1, $visible, 'Only 1 option should pass (Porridge)');
        $this->assertContains('Porridge', $visibleNames);
        $this->assertNotContains('Shakshuka Fried Egg', $visibleNames, 'Egg dish must be filtered');
        $this->assertNotContains('Poached Egg on Toast', $visibleNames, 'Egg dish must be filtered');
        $this->assertNotContains('Breakfast Frittata', $visibleNames, 'Egg dish must be filtered');
    }

    /**
     * Menu-level filter: ALL options have conflicting allergen → hide entire menu.
     */
    public function testAllergen_AllOptionsConflict_MenuHidden()
    {
        $this->seedConfig();

        $this->insertMenuOption(1, 'Egg Toast', [], [self::ALLERGEN_EGG]);
        $this->insertMenuOption(1, 'Egg Muffin', [], [self::ALLERGEN_EGG]);
        $options = $this->getOptionsByMenu(1);

        $result = $this->menuHasMatchingVariation($options, [], [self::ALLERGEN_EGG]);
        $this->assertFalse($result, 'Menu should be hidden when ALL options conflict with patient allergen');
    }

    /**
     * Menu-level filter: SOME options safe → menu shown.
     */
    public function testAllergen_SomeOptionsSafe_MenuShown()
    {
        $this->seedConfig();

        $this->insertMenuOption(1, 'Egg Toast', [], [self::ALLERGEN_EGG]);
        $this->insertMenuOption(1, 'Plain Toast', [], []);
        $options = $this->getOptionsByMenu(1);

        $result = $this->menuHasMatchingVariation($options, [], [self::ALLERGEN_EGG]);
        $this->assertTrue($result, 'Menu should show when some options are safe');
    }

    /**
     * Options with NULL allergenValues bypass allergen filter (no allergens = safe).
     * This is correct behavior but highlights a data completeness risk.
     */
    public function testAllergen_NullAllergenValues_PassesFilter()
    {
        $this->seedConfig();
        // Option with NULL allergenValues
        $optId = $this->insert('menu_options', [
            'menu_option_name' => 'Unknown Allergens', 'cuisineValues' => '[]',
            'allergenValues' => NULL, 'status' => 1, 'is_deleted' => 0,
            'location_id' => 1, 'date_created' => '2026-01-01', 'date_updated' => '2026-01-01',
        ]);
        $this->insert('menu_details_to_menu_options', ['main_menu_id' => 1, 'menu_option_id' => $optId]);

        $options = $this->getOptionsByMenu(1);
        $visible = $this->filterOptionsForPatient($options, [], [self::ALLERGEN_EGG]);

        // Options with NULL allergens pass (we can't know what they contain)
        $this->assertCount(1, $visible, 'Options with NULL allergenValues pass the filter (no data = assumed safe)');
    }

    /**
     * Multiple allergens: patient allergic to Egg AND Nuts.
     * Option with only Egg allergen should be hidden.
     * Option with only Nuts allergen should be hidden.
     * Option with no allergens should be shown.
     */
    public function testAllergen_MultiplePatientAllergies()
    {
        $this->seedConfig();

        $this->insertMenuOption(1, 'Egg Dish', [], [self::ALLERGEN_EGG]);
        $this->insertMenuOption(1, 'Nut Dish', [], [self::ALLERGEN_NUTS]);
        $this->insertMenuOption(1, 'Egg & Nut Dish', [], [self::ALLERGEN_EGG, self::ALLERGEN_NUTS]);
        $this->insertMenuOption(1, 'Plain Dish', [], []);
        $options = $this->getOptionsByMenu(1);

        $visible = $this->filterOptionsForPatient($options, [], [self::ALLERGEN_EGG, self::ALLERGEN_NUTS]);
        $visibleNames = array_column($visible, 'menu_option_name');

        $this->assertCount(1, $visible);
        $this->assertContains('Plain Dish', $visibleNames);
    }

    // ═════════════════════════════════════════════════════════════════
    // 4. CUISINE FILTERING TESTS
    // ═════════════════════════════════════════════════════════════════

    /**
     * Patient [Lactose Free, Low Sodium] sees only exact match variations.
     */
    public function testCuisine_ExactSetMatch()
    {
        $this->seedConfig();

        $this->insertMenuOption(1, 'Standard Toast', [], []);
        $this->insertMenuOption(1, 'GF Toast', [self::CUISINE_GLUTEN_FREE], []);
        $this->insertMenuOption(1, 'LF+LS Toast', [self::CUISINE_LACTOSE_FREE, self::CUISINE_LOW_SODIUM], []);
        $options = $this->getOptionsByMenu(1);

        $visible = $this->filterOptionsForPatient(
            $options,
            [self::CUISINE_LACTOSE_FREE, self::CUISINE_LOW_SODIUM],
            []
        );
        $visibleNames = array_column($visible, 'menu_option_name');

        $this->assertCount(1, $visible);
        $this->assertContains('LF+LS Toast', $visibleNames);
        $this->assertNotContains('Standard Toast', $visibleNames, 'Standard should not match [LF,LS] patient');
        $this->assertNotContains('GF Toast', $visibleNames, 'GF should not match [LF,LS] patient');
    }

    /**
     * No-preference patient sees only standard (empty cuisine) items.
     */
    public function testCuisine_NoPreference_SeesStandard()
    {
        $this->seedConfig();

        $this->insertMenuOption(1, 'Standard Toast', [], []);
        $this->insertMenuOption(1, 'GF Toast', [self::CUISINE_GLUTEN_FREE], []);
        $options = $this->getOptionsByMenu(1);

        $visible = $this->filterOptionsForPatient($options, [], []);
        $this->assertCount(1, $visible);
        $this->assertEquals('Standard Toast', $visible[0]['menu_option_name']);
    }

    /**
     * Cuisine + Allergen combined: patient [LF,LS] with Egg allergy.
     * Only LF+LS variations without Egg should show.
     */
    public function testCuisine_PlusAllergen_Combined()
    {
        $this->seedConfig();

        $this->insertMenuOption(1, 'LF+LS Egg Dish', [self::CUISINE_LACTOSE_FREE, self::CUISINE_LOW_SODIUM], [self::ALLERGEN_EGG]);
        $this->insertMenuOption(1, 'LF+LS Plain Dish', [self::CUISINE_LACTOSE_FREE, self::CUISINE_LOW_SODIUM], []);
        $this->insertMenuOption(1, 'Standard Plain', [], []);
        $options = $this->getOptionsByMenu(1);

        $visible = $this->filterOptionsForPatient(
            $options,
            [self::CUISINE_LACTOSE_FREE, self::CUISINE_LOW_SODIUM],
            [self::ALLERGEN_EGG]
        );
        $visibleNames = array_column($visible, 'menu_option_name');

        $this->assertCount(1, $visible);
        $this->assertContains('LF+LS Plain Dish', $visibleNames);
        $this->assertNotContains('LF+LS Egg Dish', $visibleNames, 'Egg allergen should filter this');
        $this->assertNotContains('Standard Plain', $visibleNames, 'Cuisine mismatch should filter this');
    }

    // ═════════════════════════════════════════════════════════════════
    // 5. COMMON ITEM FEATURE TESTS
    // ═════════════════════════════════════════════════════════════════

    /**
     * Common item: ALL patients see all options regardless of dietary preferences.
     * Allergen filtering still applies.
     */
    public function testCommonItem_SkipsCuisine_KeepsAllergen()
    {
        $this->seedConfig();

        $this->insertMenuOption(1, 'Paneer Roll', [self::CUISINE_DAIRY_FREE], []);
        $this->insertMenuOption(1, 'Sandwich', [], []);
        $this->insertMenuOption(1, 'Milkshake', [], [self::ALLERGEN_LACTOSE]);
        $options = $this->getOptionsByMenu(1);

        // Patient A: GF preference + Lactose allergy, COMMON item
        $visible = $this->filterOptionsForPatient(
            $options,
            [self::CUISINE_GLUTEN_FREE], // would normally filter out non-GF items
            [self::ALLERGEN_LACTOSE],
            true // is_common_item
        );
        $visibleNames = array_column($visible, 'menu_option_name');

        $this->assertCount(2, $visible, 'Common item: cuisine skipped, Milkshake filtered by allergen');
        $this->assertContains('Paneer Roll', $visibleNames, 'DF dish shows despite patient being GF');
        $this->assertContains('Sandwich', $visibleNames, 'Standard dish shows despite patient having preferences');
        $this->assertNotContains('Milkshake', $visibleNames, 'Lactose item still filtered by allergen');
    }

    /**
     * Common item: menu level also skips cuisine but checks allergen.
     */
    public function testCommonItem_MenuLevel_SkipsCuisine()
    {
        $this->seedConfig();

        // Only GF option exists — normally [DF] patient wouldn't see this menu
        $this->insertMenuOption(1, 'GF Toast', [self::CUISINE_GLUTEN_FREE], []);
        $options = $this->getOptionsByMenu(1);

        $normalResult = $this->menuHasMatchingVariation($options, [self::CUISINE_DAIRY_FREE], [], false);
        $this->assertFalse($normalResult, 'Non-common: DF patient should not see GF-only menu');

        $commonResult = $this->menuHasMatchingVariation($options, [self::CUISINE_DAIRY_FREE], [], true);
        $this->assertTrue($commonResult, 'Common item: DF patient should see GF menu (cuisine skipped)');
    }

    /**
     * Common item: menu level allergen still hides menu when ALL options conflict.
     */
    public function testCommonItem_MenuLevel_AllergenStillWorks()
    {
        $this->seedConfig();

        $this->insertMenuOption(1, 'Egg Dish 1', [], [self::ALLERGEN_EGG]);
        $this->insertMenuOption(1, 'Egg Dish 2', [self::CUISINE_GLUTEN_FREE], [self::ALLERGEN_EGG]);
        $options = $this->getOptionsByMenu(1);

        $result = $this->menuHasMatchingVariation($options, [], [self::ALLERGEN_EGG], true);
        $this->assertFalse($result, 'Common item: menu hidden when ALL options have conflicting allergen');
    }

    /**
     * USER'S EXACT EXAMPLE: Breakfast Options (common) → Paneer Roll, Sandwich, Milkshake.
     * Patient with Lactose allergy → Milkshake hidden, other 2 shown.
     */
    public function testCommonItem_UserExampleBreakfastOptions()
    {
        $this->seedConfig();
        $this->createMenuItem(10, 'Breakfast Options', true);

        $this->insertMenuOption(10, 'Paneer Roll', [], []);
        $this->insertMenuOption(10, 'Sandwich', [], []);
        $this->insertMenuOption(10, 'Milkshake', [], [self::ALLERGEN_LACTOSE]);
        $options = $this->getOptionsByMenu(10);

        $menu = $this->getMenuDetail(10);
        $isCommon = (int)$menu['is_common_item'] === 1;

        // Menu should show
        $menuVisible = $this->menuHasMatchingVariation($options, [], [self::ALLERGEN_LACTOSE], $isCommon);
        $this->assertTrue($menuVisible);

        // Option-level: Milkshake hidden
        $visible = $this->filterOptionsForPatient($options, [], [self::ALLERGEN_LACTOSE], $isCommon);
        $visibleNames = array_column($visible, 'menu_option_name');
        $this->assertCount(2, $visible);
        $this->assertContains('Paneer Roll', $visibleNames);
        $this->assertContains('Sandwich', $visibleNames);
        $this->assertNotContains('Milkshake', $visibleNames);
    }

    /**
     * Non-common item regression: all existing behavior preserved.
     */
    public function testNonCommonItem_Regression()
    {
        $this->seedConfig();

        $this->insertMenuOption(1, 'GF Toast', [self::CUISINE_GLUTEN_FREE], []);
        $this->insertMenuOption(1, 'Standard Toast', [], []);
        $options = $this->getOptionsByMenu(1);

        // GF patient sees GF
        $visible = $this->filterOptionsForPatient($options, [self::CUISINE_GLUTEN_FREE], [], false);
        $this->assertCount(1, $visible);
        $this->assertEquals('GF Toast', $visible[0]['menu_option_name']);

        // No-pref patient sees Standard
        $visible = $this->filterOptionsForPatient($options, [], [], false);
        $this->assertCount(1, $visible);
        $this->assertEquals('Standard Toast', $visible[0]['menu_option_name']);

        // [GF,DF] patient sees nothing (no combo exists)
        $visible = $this->filterOptionsForPatient($options, [self::CUISINE_GLUTEN_FREE, self::CUISINE_DAIRY_FREE], [], false);
        $this->assertCount(0, $visible);
    }

    /**
     * Common item: all different diet patients see the same menu.
     */
    public function testCommonItem_AllPatientsSeeSameMenu()
    {
        $this->seedConfig();
        $this->createMenuItem(10, 'Common Beverages', true);
        $this->insertMenuOption(10, 'Water', [], []);
        $this->insertMenuOption(10, 'Orange Juice', [], []);
        $options = $this->getOptionsByMenu(10);

        $diets = [
            [],
            [self::CUISINE_GLUTEN_FREE],
            [self::CUISINE_DAIRY_FREE],
            [self::CUISINE_GLUTEN_FREE, self::CUISINE_DAIRY_FREE],
            [self::CUISINE_LACTOSE_FREE, self::CUISINE_LOW_SODIUM],
        ];

        foreach ($diets as $diet) {
            $visible = $this->filterOptionsForPatient($options, $diet, [], true);
            $this->assertCount(2, $visible, 'Common item: patient with diet [' . implode(',', $diet) . '] should see all 2 options');
        }
    }

    // ═════════════════════════════════════════════════════════════════
    // 6. EDGE CASES & DATA FORMAT ROBUSTNESS
    // ═════════════════════════════════════════════════════════════════

    /**
     * allergenValues as various formats: string IDs, integer IDs, mixed.
     */
    public function testAllergen_MixedIdFormats()
    {
        $this->seedConfig();
        // Insert with string IDs (normal)
        $this->insertMenuOption(1, 'String IDs', [], [self::ALLERGEN_EGG]);
        $options = $this->getOptionsByMenu(1);

        // Patient allergy as string
        $result1 = $this->filterOptionsForPatient($options, [], [self::ALLERGEN_EGG]);
        $this->assertCount(0, $result1, 'String ID comparison should work');

        // Patient allergy as same numeric value
        $result2 = $this->filterOptionsForPatient($options, [], [strval(self::ALLERGEN_EGG)]);
        $this->assertCount(0, $result2, 'String comparison should work regardless');
    }

    /**
     * Option with empty string allergenValues should pass filter (no allergens known).
     */
    public function testAllergen_EmptyStringAllergenValues()
    {
        $this->seedConfig();
        $optId = $this->insert('menu_options', [
            'menu_option_name' => 'Empty String Allergens', 'cuisineValues' => '[]',
            'allergenValues' => '', 'status' => 1, 'is_deleted' => 0,
            'location_id' => 1, 'date_created' => '2026-01-01', 'date_updated' => '2026-01-01',
        ]);
        $this->insert('menu_details_to_menu_options', ['main_menu_id' => 1, 'menu_option_id' => $optId]);

        $options = $this->getOptionsByMenu(1);
        $visible = $this->filterOptionsForPatient($options, [], [self::ALLERGEN_EGG]);
        $this->assertCount(1, $visible, 'Empty string allergenValues = no known allergens = passes filter');
    }

    /**
     * Cuisine order doesn't matter: [LS,LF] matches [LF,LS].
     */
    public function testCuisine_OrderIndependent()
    {
        $this->seedConfig();
        // Stored as [LF, LS]
        $this->insertMenuOption(1, 'LF+LS', [self::CUISINE_LACTOSE_FREE, self::CUISINE_LOW_SODIUM], []);
        $options = $this->getOptionsByMenu(1);

        // Patient has [LS, LF] (reversed)
        $visible = $this->filterOptionsForPatient($options, [self::CUISINE_LOW_SODIUM, self::CUISINE_LACTOSE_FREE], []);
        $this->assertCount(1, $visible, 'Cuisine order should not matter');
    }

    /**
     * is_common_item field included in dashboard-style query.
     */
    public function testDashboardQueryIncludesCommonItem()
    {
        $this->createMenuItem(10, 'Common', true);
        $this->createMenuItem(11, 'Regular', false);

        $results = $this->query(
            "SELECT id, name, is_common_item FROM menuDetails WHERE id IN (10, 11) ORDER BY id"
        );

        $this->assertEquals(1, (int)$results[0]['is_common_item']);
        $this->assertEquals(0, (int)$results[1]['is_common_item']);
    }

    /**
     * Full pipeline test: is_common_item survives JSON pipeline.
     */
    public function testCommonItem_SurvivesJsonPipeline()
    {
        $this->seedConfig();
        $this->createMenuItem(10, 'Common Menu', true);
        $this->insertMenuOption(10, 'Test', [], []);

        $result = $this->fetchMenuOptionsViaJsonPipeline(10);
        $this->assertEquals('1', $result['is_common_item'], 'is_common_item should survive the query pipeline');
    }

    /**
     * Save menu data with is_common_item (mirrors controller).
     */
    public function testSaveMenuWithCommonItemFlag()
    {
        $id = $this->insert('menuDetails', [
            'name' => 'Controller Save Test', 'inputType' => 'checkbox',
            'is_single_select' => 'no', 'is_main_menu' => 'no',
            'is_common_item' => 1, 'description' => '',
            'sort_order' => 1, 'status' => 1, 'is_deleted' => 0,
            'date_created' => '2026-04-18', 'date_updated' => '2026-04-18',
        ]);
        $this->assertEquals(1, (int)$this->getMenuDetail($id)['is_common_item']);
    }
}
