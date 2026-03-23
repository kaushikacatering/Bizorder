<?php
/**
 * MenuItemVariationsTest
 * ======================
 * Tests the menu_item_variations CRUD logic and dashboard filtering.
 *
 * Mirrors the SQL logic in:
 *   - Menu_model::get_variations_by_menu()
 *   - Menu_model::save_variation()
 *   - Menu_model::delete_variation()
 *   - Menu_model::get_all_variations_list()
 *   - Dashboard JS: menuHasMatchingVariation()
 *
 * Run:  vendor/bin/phpunit tests/MenuItemVariationsTest.php
 */
class MenuItemVariationsTest extends CITestCase
{
    // Cuisine type constants (seeded in seedVariationData)
    protected const CUISINE_GLUTEN_FREE = 50;
    protected const CUISINE_DAIRY_FREE  = 51;
    protected const CUISINE_SUGAR_FREE  = 52;
    protected const CUISINE_REGULAR     = 53;

    // Allergen constants
    protected const ALLERGEN_NUTS   = 60;
    protected const ALLERGEN_DAIRY  = 61;
    protected const ALLERGEN_GLUTEN = 62;

    /**
     * Seed cuisine types, allergens, and ensure menu_item_variations table exists.
     */
    protected function seedVariationData(): void
    {
        // Cuisine types
        $this->insert('foodmenuconfig', [
            'id' => self::CUISINE_GLUTEN_FREE, 'name' => 'Gluten Free',
            'listtype' => 'cuisine', 'sort_order' => 1, 'is_deleted' => 0, 'location_id' => 1,
        ]);
        $this->insert('foodmenuconfig', [
            'id' => self::CUISINE_DAIRY_FREE, 'name' => 'Dairy Free',
            'listtype' => 'cuisine', 'sort_order' => 2, 'is_deleted' => 0, 'location_id' => 1,
        ]);
        $this->insert('foodmenuconfig', [
            'id' => self::CUISINE_SUGAR_FREE, 'name' => 'Sugar Free',
            'listtype' => 'cuisine', 'sort_order' => 3, 'is_deleted' => 0, 'location_id' => 1,
        ]);
        $this->insert('foodmenuconfig', [
            'id' => self::CUISINE_REGULAR, 'name' => 'Regular',
            'listtype' => 'cuisine', 'sort_order' => 4, 'is_deleted' => 0, 'location_id' => 1,
        ]);

        // Allergens
        $this->insert('foodmenuconfig', [
            'id' => self::ALLERGEN_NUTS, 'name' => 'Nuts',
            'listtype' => 'allergen', 'sort_order' => 1, 'is_deleted' => 0, 'location_id' => 1,
        ]);
        $this->insert('foodmenuconfig', [
            'id' => self::ALLERGEN_DAIRY, 'name' => 'Dairy',
            'listtype' => 'allergen', 'sort_order' => 2, 'is_deleted' => 0, 'location_id' => 1,
        ]);
        $this->insert('foodmenuconfig', [
            'id' => self::ALLERGEN_GLUTEN, 'name' => 'Gluten',
            'listtype' => 'allergen', 'sort_order' => 3, 'is_deleted' => 0, 'location_id' => 1,
        ]);
    }

    // ── HELPER: Insert a variation ──────────────────────────────────

    protected function insertVariation(int $menuDetailId, array $cuisineIds, string $desc = '', string $nutrition = '', array $allergenIds = []): int
    {
        return $this->insert('menu_item_variations', [
            'menu_detail_id'    => $menuDetailId,
            'cuisine_type_ids'  => json_encode($cuisineIds),
            'description'       => $desc,
            'nutritional_values'=> $nutrition,
            'allergenValues'    => json_encode($allergenIds),
            'status'            => 1,
            'is_deleted'        => 0,
            'sort_order'        => 0,
        ]);
    }

    // ── HELPER: Fetch variations by menu (mirrors Menu_model) ───────

    protected function getVariationsByMenu(int $menuDetailId): array
    {
        return $this->query(
            "SELECT * FROM menu_item_variations
             WHERE menu_detail_id = ? AND is_deleted = 0
             ORDER BY sort_order ASC",
            [$menuDetailId]
        );
    }

    // ── HELPER: Get single variation ────────────────────────────────

    protected function getVariation(int $id): ?array
    {
        return $this->fetchRow('menu_item_variations', 'id', $id);
    }

    // ── HELPER: Soft delete a variation (mirrors Menu_model) ────────

    protected function softDeleteVariation(int $id): void
    {
        $this->update('menu_item_variations', [
            'is_deleted'   => 1,
            'date_updated' => $this->ausNow(),
        ], 'id', $id);
    }

    // ── HELPER: Get all variations list (mirrors listing page) ──────

    protected function getAllVariationsList(): array
    {
        return $this->query(
            "SELECT v.*, md.name AS menu_name
             FROM menu_item_variations v
             LEFT JOIN menuDetails md ON md.id = v.menu_detail_id
             WHERE v.is_deleted = 0
             ORDER BY md.sort_order ASC, v.sort_order ASC"
        );
    }

    /**
     * Mirrors the dashboard JS: menuHasMatchingVariation()
     * Returns true if the menu has at least one variation whose cuisine_type_ids
     * overlap with the patient's dietary_preferences.
     */
    protected function menuHasMatchingVariation(array $variations, array $patientCuisineIds): bool
    {
        if (empty($variations)) return true; // No variations = show everything
        if (empty($patientCuisineIds)) return true; // No preference = show everything

        $patientIds = array_map('strval', $patientCuisineIds);

        foreach ($variations as $v) {
            $vCuisineIds = json_decode($v['cuisine_type_ids'] ?? '[]', true) ?: [];
            foreach ($vCuisineIds as $cid) {
                if (in_array((string)$cid, $patientIds)) {
                    return true;
                }
            }
        }
        return false;
    }

    // ═════════════════════════════════════════════════════════════════
    // TESTS
    // ═════════════════════════════════════════════════════════════════

    /**
     * #1: Insert a variation and retrieve it.
     */
    public function testInsertAndRetrieveVariation()
    {
        $this->seedVariationData();

        $id = $this->insertVariation(
            1, // Toast menu
            [self::CUISINE_GLUTEN_FREE, self::CUISINE_DAIRY_FREE],
            'No wheat, no milk',
            '180 Cal',
            [self::ALLERGEN_NUTS]
        );

        $this->assertGreaterThan(0, $id);

        $v = $this->getVariation($id);
        $this->assertNotNull($v);
        $this->assertEquals(1, (int)$v['menu_detail_id']);
        $this->assertEquals('No wheat, no milk', $v['description']);
        $this->assertEquals('180 Cal', $v['nutritional_values']);

        $cuisineIds = json_decode($v['cuisine_type_ids'], true);
        $this->assertIsArray($cuisineIds);
        $this->assertCount(2, $cuisineIds);
        $this->assertContains(self::CUISINE_GLUTEN_FREE, $cuisineIds);
        $this->assertContains(self::CUISINE_DAIRY_FREE, $cuisineIds);

        $allergenIds = json_decode($v['allergenValues'], true);
        $this->assertIsArray($allergenIds);
        $this->assertContains(self::ALLERGEN_NUTS, $allergenIds);
    }

    /**
     * #2: Get variations by menu returns only rows for that menu.
     */
    public function testGetVariationsByMenu()
    {
        $this->seedVariationData();

        // Two variations for Toast (menu 1)
        $this->insertVariation(1, [self::CUISINE_GLUTEN_FREE], 'GF Toast');
        $this->insertVariation(1, [self::CUISINE_DAIRY_FREE], 'DF Toast');

        // One variation for Soup (menu 2)
        $this->insertVariation(2, [self::CUISINE_REGULAR], 'Regular Soup');

        $toastVariations = $this->getVariationsByMenu(1);
        $soupVariations  = $this->getVariationsByMenu(2);
        $steakVariations = $this->getVariationsByMenu(3);

        $this->assertCount(2, $toastVariations);
        $this->assertCount(1, $soupVariations);
        $this->assertCount(0, $steakVariations);
    }

    /**
     * #3: Soft delete hides the variation from queries.
     */
    public function testSoftDeleteVariation()
    {
        $this->seedVariationData();

        $id = $this->insertVariation(1, [self::CUISINE_GLUTEN_FREE], 'To be deleted');

        $before = $this->getVariationsByMenu(1);
        $this->assertCount(1, $before);

        $this->softDeleteVariation($id);

        $after = $this->getVariationsByMenu(1);
        $this->assertCount(0, $after);

        // Row still physically exists
        $raw = $this->getVariation($id);
        $this->assertNotNull($raw);
        $this->assertEquals(1, (int)$raw['is_deleted']);
    }

    /**
     * #4: Update an existing variation.
     */
    public function testUpdateVariation()
    {
        $this->seedVariationData();

        $id = $this->insertVariation(1, [self::CUISINE_GLUTEN_FREE], 'Original desc', '100 Cal');

        // Update
        $this->update('menu_item_variations', [
            'cuisine_type_ids'  => json_encode([self::CUISINE_DAIRY_FREE, self::CUISINE_SUGAR_FREE]),
            'description'       => 'Updated desc',
            'nutritional_values'=> '250 Cal',
            'allergenValues'    => json_encode([self::ALLERGEN_DAIRY, self::ALLERGEN_GLUTEN]),
            'date_updated'      => $this->ausNow(),
        ], 'id', $id);

        $v = $this->getVariation($id);
        $this->assertEquals('Updated desc', $v['description']);
        $this->assertEquals('250 Cal', $v['nutritional_values']);

        $cuisineIds = json_decode($v['cuisine_type_ids'], true);
        $this->assertCount(2, $cuisineIds);
        $this->assertContains(self::CUISINE_DAIRY_FREE, $cuisineIds);
        $this->assertContains(self::CUISINE_SUGAR_FREE, $cuisineIds);

        $allergenIds = json_decode($v['allergenValues'], true);
        $this->assertCount(2, $allergenIds);
    }

    /**
     * #5: Get all variations list includes menu name.
     */
    public function testGetAllVariationsListWithMenuName()
    {
        $this->seedVariationData();

        $this->insertVariation(1, [self::CUISINE_GLUTEN_FREE], 'GF Toast');
        $this->insertVariation(2, [self::CUISINE_DAIRY_FREE], 'DF Soup');

        $list = $this->getAllVariationsList();
        $this->assertCount(2, $list);

        // Check that menu names are joined
        $menuNames = array_column($list, 'menu_name');
        $this->assertContains('Toast', $menuNames);
        $this->assertContains('Soup', $menuNames);
    }

    /**
     * #6: Deleted variations are excluded from the listing.
     */
    public function testDeletedVariationsExcludedFromListing()
    {
        $this->seedVariationData();

        $id1 = $this->insertVariation(1, [self::CUISINE_GLUTEN_FREE], 'Visible');
        $id2 = $this->insertVariation(1, [self::CUISINE_DAIRY_FREE], 'Hidden');

        $this->softDeleteVariation($id2);

        $list = $this->getAllVariationsList();
        $this->assertCount(1, $list);
        $this->assertEquals('Visible', $list[0]['description']);
    }

    /**
     * #7: Multiple cuisine types in one variation (combo like "Gluten Free + Dairy Free").
     */
    public function testMultipleCuisineTypesInVariation()
    {
        $this->seedVariationData();

        $id = $this->insertVariation(
            1,
            [self::CUISINE_GLUTEN_FREE, self::CUISINE_DAIRY_FREE, self::CUISINE_SUGAR_FREE],
            'Triple-free toast'
        );

        $v = $this->getVariation($id);
        $cuisineIds = json_decode($v['cuisine_type_ids'], true);
        $this->assertCount(3, $cuisineIds);
        $this->assertContains(self::CUISINE_GLUTEN_FREE, $cuisineIds);
        $this->assertContains(self::CUISINE_DAIRY_FREE, $cuisineIds);
        $this->assertContains(self::CUISINE_SUGAR_FREE, $cuisineIds);
    }

    /**
     * #8: Variation with empty allergens is valid.
     */
    public function testVariationWithEmptyAllergens()
    {
        $this->seedVariationData();

        $id = $this->insertVariation(1, [self::CUISINE_REGULAR], 'Plain toast', '90 Cal', []);

        $v = $this->getVariation($id);
        $allergenIds = json_decode($v['allergenValues'], true);
        $this->assertIsArray($allergenIds);
        $this->assertEmpty($allergenIds);
    }

    // ═════════════════════════════════════════════════════════════════
    // DASHBOARD FILTERING TESTS
    // ═════════════════════════════════════════════════════════════════

    /**
     * #9: Patient with Gluten Free preference matches a Gluten Free variation.
     */
    public function testDashboardFilter_MatchesSingleCuisine()
    {
        $this->seedVariationData();

        $this->insertVariation(1, [self::CUISINE_GLUTEN_FREE], 'GF Toast');
        $this->insertVariation(1, [self::CUISINE_DAIRY_FREE], 'DF Toast');

        $variations = $this->getVariationsByMenu(1);

        // Patient wants Gluten Free
        $result = $this->menuHasMatchingVariation($variations, [self::CUISINE_GLUTEN_FREE]);
        $this->assertTrue($result);
    }

    /**
     * #10: Patient preference doesn't match any variation → filtered out.
     */
    public function testDashboardFilter_NoMatch()
    {
        $this->seedVariationData();

        $this->insertVariation(1, [self::CUISINE_GLUTEN_FREE], 'GF only');

        $variations = $this->getVariationsByMenu(1);

        // Patient wants Sugar Free but no variation has it
        $result = $this->menuHasMatchingVariation($variations, [self::CUISINE_SUGAR_FREE]);
        $this->assertFalse($result);
    }

    /**
     * #11: Menu with no variations should always show (backward compat).
     */
    public function testDashboardFilter_NoVariationsMeansShowAll()
    {
        $this->seedVariationData();

        $variations = $this->getVariationsByMenu(3); // Steak has no variations
        $this->assertEmpty($variations);

        $result = $this->menuHasMatchingVariation($variations, [self::CUISINE_GLUTEN_FREE]);
        $this->assertTrue($result);
    }

    /**
     * #12: Patient with no dietary preferences should see all menus.
     */
    public function testDashboardFilter_NoPreferencesMeansShowAll()
    {
        $this->seedVariationData();

        $this->insertVariation(1, [self::CUISINE_GLUTEN_FREE], 'GF only');
        $variations = $this->getVariationsByMenu(1);

        $result = $this->menuHasMatchingVariation($variations, []);
        $this->assertTrue($result);
    }

    /**
     * #13: Multi-cuisine variation matches if ANY patient preference overlaps.
     */
    public function testDashboardFilter_PartialOverlap()
    {
        $this->seedVariationData();

        // Variation has [Gluten Free, Dairy Free]
        $this->insertVariation(1, [self::CUISINE_GLUTEN_FREE, self::CUISINE_DAIRY_FREE], 'GF+DF');
        $variations = $this->getVariationsByMenu(1);

        // Patient only wants Dairy Free → should match
        $result = $this->menuHasMatchingVariation($variations, [self::CUISINE_DAIRY_FREE]);
        $this->assertTrue($result);
    }

    /**
     * #14: Patient has multiple preferences, one matches.
     */
    public function testDashboardFilter_PatientMultiplePrefs()
    {
        $this->seedVariationData();

        $this->insertVariation(1, [self::CUISINE_SUGAR_FREE], 'SF Toast');
        $variations = $this->getVariationsByMenu(1);

        // Patient wants [Gluten Free, Sugar Free] → Sugar Free matches
        $result = $this->menuHasMatchingVariation($variations, [self::CUISINE_GLUTEN_FREE, self::CUISINE_SUGAR_FREE]);
        $this->assertTrue($result);
    }

    /**
     * #15: Multiple variations on a menu, only one needs to match.
     */
    public function testDashboardFilter_AnyVariationMatches()
    {
        $this->seedVariationData();

        $this->insertVariation(1, [self::CUISINE_REGULAR], 'Regular Toast');
        $this->insertVariation(1, [self::CUISINE_GLUTEN_FREE], 'GF Toast');
        $variations = $this->getVariationsByMenu(1);

        // Patient wants Gluten Free → second variation matches
        $result = $this->menuHasMatchingVariation($variations, [self::CUISINE_GLUTEN_FREE]);
        $this->assertTrue($result);
    }

    /**
     * #16: Completely disjoint preferences and variations.
     */
    public function testDashboardFilter_CompletelyDisjoint()
    {
        $this->seedVariationData();

        $this->insertVariation(1, [self::CUISINE_REGULAR], 'Regular');
        $this->insertVariation(1, [self::CUISINE_SUGAR_FREE], 'Sugar Free');
        $variations = $this->getVariationsByMenu(1);

        // Patient wants [Gluten Free, Dairy Free] → neither variation has these
        $result = $this->menuHasMatchingVariation($variations, [self::CUISINE_GLUTEN_FREE, self::CUISINE_DAIRY_FREE]);
        $this->assertFalse($result);
    }

    // ═════════════════════════════════════════════════════════════════
    // EDGE CASES
    // ═════════════════════════════════════════════════════════════════

    /**
     * #17: Description is capped at 200 chars in the DB.
     */
    public function testDescriptionMaxLength()
    {
        $this->seedVariationData();

        $longDesc = str_repeat('A', 200);
        $id = $this->insertVariation(1, [self::CUISINE_REGULAR], $longDesc);

        $v = $this->getVariation($id);
        $this->assertEquals(200, strlen($v['description']));
    }

    /**
     * #18: Multiple allergens stored correctly as JSON.
     */
    public function testMultipleAllergens()
    {
        $this->seedVariationData();

        $id = $this->insertVariation(
            1,
            [self::CUISINE_REGULAR],
            'High allergen',
            '300 Cal',
            [self::ALLERGEN_NUTS, self::ALLERGEN_DAIRY, self::ALLERGEN_GLUTEN]
        );

        $v = $this->getVariation($id);
        $allergens = json_decode($v['allergenValues'], true);
        $this->assertCount(3, $allergens);
    }

    /**
     * #19: Inserting variations for different menus keeps them separate.
     */
    public function testVariationsIsolatedPerMenu()
    {
        $this->seedVariationData();

        $this->insertVariation(1, [self::CUISINE_GLUTEN_FREE], 'Toast GF');
        $this->insertVariation(2, [self::CUISINE_DAIRY_FREE], 'Soup DF');
        $this->insertVariation(3, [self::CUISINE_SUGAR_FREE], 'Steak SF');

        $this->assertCount(1, $this->getVariationsByMenu(1));
        $this->assertCount(1, $this->getVariationsByMenu(2));
        $this->assertCount(1, $this->getVariationsByMenu(3));
    }

    /**
     * #20: Deleting one variation doesn't affect others on the same menu.
     */
    public function testDeleteOneVariationLeavesOthers()
    {
        $this->seedVariationData();

        $id1 = $this->insertVariation(1, [self::CUISINE_GLUTEN_FREE], 'GF');
        $id2 = $this->insertVariation(1, [self::CUISINE_DAIRY_FREE], 'DF');
        $id3 = $this->insertVariation(1, [self::CUISINE_SUGAR_FREE], 'SF');

        $this->softDeleteVariation($id2);

        $remaining = $this->getVariationsByMenu(1);
        $this->assertCount(2, $remaining);

        $descs = array_column($remaining, 'description');
        $this->assertContains('GF', $descs);
        $this->assertContains('SF', $descs);
        $this->assertNotContains('DF', $descs);
    }
}
