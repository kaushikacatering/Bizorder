<?php
/**
 * MenuOptionsManagementTest
 * =========================
 * Tests the menu_options CRUD logic used by the Menu Management page,
 * including the menu_details_to_menu_options link table and dashboard filtering.
 *
 * Mirrors the SQL logic in:
 *   - Menu_model::get_variations_by_menu()   (now reads menu_options via join)
 *   - Menu_model::save_variation()            (now writes to menu_options)
 *   - Menu_model::delete_variation()          (now soft-deletes menu_options)
 *   - Menu_model::get_all_variations_list()   (now reads menu_options via join)
 *   - Dashboard JS: menuHasMatchingVariation()
 *
 * Run:  vendor/bin/phpunit tests/MenuOptionsManagementTest.php
 */
class MenuOptionsManagementTest extends CITestCase
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
     * Seed cuisine types and allergens.
     */
    protected function seedVariationData(): void
    {
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

    // ── HELPER: Insert a menu option and link to a menu item ────────

    protected function insertMenuOption(int $menuDetailId, string $name, array $cuisineIds, string $desc = '', string $nutrition = '', array $allergenIds = []): int
    {
        $optionId = $this->insert('menu_options', [
            'menu_option_name'  => $name,
            'cuisineValues'     => json_encode($cuisineIds),
            'description'       => $desc,
            'nutritionValues'   => $nutrition,
            'allergenValues'    => json_encode($allergenIds),
            'status'            => 1,
            'is_deleted'        => 0,
            'location_id'       => 1,
        ]);

        // Create the link
        $this->insert('menu_details_to_menu_options', [
            'main_menu_id'   => $menuDetailId,
            'menu_option_id' => $optionId,
        ]);

        return $optionId;
    }

    // ── HELPER: Fetch options by menu (mirrors Menu_model::get_variations_by_menu) ──

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

    // ── HELPER: Get single option ───────────────────────────────────

    protected function getOption(int $id): ?array
    {
        return $this->fetchRow('menu_options', 'id', $id);
    }

    // ── HELPER: Soft delete an option ───────────────────────────────

    protected function softDeleteOption(int $id): void
    {
        $this->update('menu_options', [
            'is_deleted'   => 1,
            'date_updated' => $this->ausNow(),
        ], 'id', $id);
    }

    // ── HELPER: Remove link ─────────────────────────────────────────

    protected function removeLink(int $menuDetailId, int $optionId): void
    {
        $this->query(
            "DELETE FROM menu_details_to_menu_options WHERE main_menu_id = ? AND menu_option_id = ?",
            [$menuDetailId, $optionId]
        );
    }

    // ── HELPER: Get all options list (mirrors listing page) ─────────

    protected function getAllOptionsList(): array
    {
        return $this->query(
            "SELECT mo.id, mo.menu_option_name, mo.description,
                    mo.cuisineValues AS cuisine_type_ids,
                    mo.nutritionValues AS nutritional_values,
                    mo.allergenValues,
                    md.name AS menu_name
             FROM menu_options mo
             LEFT JOIN menu_details_to_menu_options mdto ON mdto.menu_option_id = mo.id
             LEFT JOIN menuDetails md ON md.id = mdto.main_menu_id
             WHERE mo.is_deleted = 0 AND mo.status = 1
             ORDER BY md.sort_order ASC, mo.id ASC"
        );
    }

    /**
     * Mirrors the dashboard JS: menuHasMatchingVariation()
     */
    protected function menuHasMatchingVariation(array $variations, array $patientCuisineIds): bool
    {
        if (empty($variations)) return true;
        if (empty($patientCuisineIds)) return true;

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
     * #1: Insert a menu option linked to a menu item and retrieve it.
     */
    public function testInsertAndRetrieveOption()
    {
        $this->seedVariationData();

        $id = $this->insertMenuOption(
            1, // Toast menu
            'GF Toast',
            [self::CUISINE_GLUTEN_FREE, self::CUISINE_DAIRY_FREE],
            'No wheat, no milk',
            '180 Cal',
            [self::ALLERGEN_NUTS]
        );

        $this->assertGreaterThan(0, $id);

        $opt = $this->getOption($id);
        $this->assertNotNull($opt);
        $this->assertEquals('GF Toast', $opt['menu_option_name']);
        $this->assertEquals('No wheat, no milk', $opt['description']);
        $this->assertEquals('180 Cal', $opt['nutritionValues']);

        $cuisineIds = json_decode($opt['cuisineValues'], true);
        $this->assertIsArray($cuisineIds);
        $this->assertCount(2, $cuisineIds);
        $this->assertContains(self::CUISINE_GLUTEN_FREE, $cuisineIds);
        $this->assertContains(self::CUISINE_DAIRY_FREE, $cuisineIds);

        $allergenIds = json_decode($opt['allergenValues'], true);
        $this->assertIsArray($allergenIds);
        $this->assertContains(self::ALLERGEN_NUTS, $allergenIds);
    }

    /**
     * #2: Get options by menu returns only rows linked to that menu.
     */
    public function testGetOptionsByMenu()
    {
        $this->seedVariationData();

        $this->insertMenuOption(1, 'GF Toast', [self::CUISINE_GLUTEN_FREE]);
        $this->insertMenuOption(1, 'DF Toast', [self::CUISINE_DAIRY_FREE]);
        $this->insertMenuOption(2, 'Regular Soup', [self::CUISINE_REGULAR]);

        $toastOptions = $this->getOptionsByMenu(1);
        $soupOptions  = $this->getOptionsByMenu(2);
        $steakOptions = $this->getOptionsByMenu(3);

        $this->assertCount(2, $toastOptions);
        $this->assertCount(1, $soupOptions);
        $this->assertCount(0, $steakOptions);
    }

    /**
     * #3: Soft delete hides the option from queries.
     */
    public function testSoftDeleteOption()
    {
        $this->seedVariationData();

        $id = $this->insertMenuOption(1, 'To be deleted', [self::CUISINE_GLUTEN_FREE]);

        $before = $this->getOptionsByMenu(1);
        $this->assertCount(1, $before);

        $this->softDeleteOption($id);

        $after = $this->getOptionsByMenu(1);
        $this->assertCount(0, $after);

        // Row still physically exists
        $raw = $this->getOption($id);
        $this->assertNotNull($raw);
        $this->assertEquals(1, (int)$raw['is_deleted']);
    }

    /**
     * #4: Update an existing option.
     */
    public function testUpdateOption()
    {
        $this->seedVariationData();

        $id = $this->insertMenuOption(1, 'Original', [self::CUISINE_GLUTEN_FREE], 'Original desc', '100 Cal');

        $this->update('menu_options', [
            'menu_option_name'  => 'Updated Name',
            'cuisineValues'     => json_encode([self::CUISINE_DAIRY_FREE, self::CUISINE_SUGAR_FREE]),
            'description'       => 'Updated desc',
            'nutritionValues'   => '250 Cal',
            'allergenValues'    => json_encode([self::ALLERGEN_DAIRY, self::ALLERGEN_GLUTEN]),
            'date_updated'      => $this->ausNow(),
        ], 'id', $id);

        $opt = $this->getOption($id);
        $this->assertEquals('Updated Name', $opt['menu_option_name']);
        $this->assertEquals('Updated desc', $opt['description']);
        $this->assertEquals('250 Cal', $opt['nutritionValues']);

        $cuisineIds = json_decode($opt['cuisineValues'], true);
        $this->assertCount(2, $cuisineIds);
        $this->assertContains(self::CUISINE_DAIRY_FREE, $cuisineIds);
        $this->assertContains(self::CUISINE_SUGAR_FREE, $cuisineIds);
    }

    /**
     * #5: Get all options list includes menu name via join.
     */
    public function testGetAllOptionsListWithMenuName()
    {
        $this->seedVariationData();

        $this->insertMenuOption(1, 'GF Toast', [self::CUISINE_GLUTEN_FREE]);
        $this->insertMenuOption(2, 'DF Soup', [self::CUISINE_DAIRY_FREE]);

        $list = $this->getAllOptionsList();
        // seedBaseData creates 1 seeded option (id=1 'Regular'), plus our 2
        $this->assertGreaterThanOrEqual(2, count($list));

        $menuNames = array_column($list, 'menu_name');
        $this->assertContains('Toast', $menuNames);
        $this->assertContains('Soup', $menuNames);
    }

    /**
     * #6: Deleted options are excluded from the listing.
     */
    public function testDeletedOptionsExcludedFromListing()
    {
        $this->seedVariationData();

        $id1 = $this->insertMenuOption(1, 'Visible', [self::CUISINE_GLUTEN_FREE]);
        $id2 = $this->insertMenuOption(1, 'Hidden', [self::CUISINE_DAIRY_FREE]);

        $this->softDeleteOption($id2);

        $list = $this->getAllOptionsList();
        // Filter to only our test options (exclude seeded 'Regular' option)
        $ourOptions = array_filter($list, fn($o) => in_array($o['menu_option_name'], ['Visible', 'Hidden']));
        $this->assertCount(1, $ourOptions);
        $this->assertEquals('Visible', array_values($ourOptions)[0]['menu_option_name']);
    }

    /**
     * #7: Multiple cuisine types in one option.
     */
    public function testMultipleCuisineTypesInOption()
    {
        $this->seedVariationData();

        $id = $this->insertMenuOption(
            1,
            'Triple-free toast',
            [self::CUISINE_GLUTEN_FREE, self::CUISINE_DAIRY_FREE, self::CUISINE_SUGAR_FREE]
        );

        $opt = $this->getOption($id);
        $cuisineIds = json_decode($opt['cuisineValues'], true);
        $this->assertCount(3, $cuisineIds);
    }

    /**
     * #8: Option with empty allergens is valid.
     */
    public function testOptionWithEmptyAllergens()
    {
        $this->seedVariationData();

        $id = $this->insertMenuOption(1, 'Plain toast', [self::CUISINE_REGULAR], 'Plain', '90 Cal', []);

        $opt = $this->getOption($id);
        $allergenIds = json_decode($opt['allergenValues'], true);
        $this->assertIsArray($allergenIds);
        $this->assertEmpty($allergenIds);
    }

    // ═════════════════════════════════════════════════════════════════
    // DASHBOARD FILTERING TESTS
    // ═════════════════════════════════════════════════════════════════

    /**
     * #9: Patient with matching cuisine preference finds a match.
     */
    public function testDashboardFilter_MatchesSingleCuisine()
    {
        $this->seedVariationData();

        $this->insertMenuOption(1, 'GF Toast', [self::CUISINE_GLUTEN_FREE]);
        $this->insertMenuOption(1, 'DF Toast', [self::CUISINE_DAIRY_FREE]);

        $options = $this->getOptionsByMenu(1);

        $result = $this->menuHasMatchingVariation($options, [self::CUISINE_GLUTEN_FREE]);
        $this->assertTrue($result);
    }

    /**
     * #10: Patient preference doesn't match any option → filtered out.
     */
    public function testDashboardFilter_NoMatch()
    {
        $this->seedVariationData();

        $this->insertMenuOption(1, 'GF only', [self::CUISINE_GLUTEN_FREE]);

        $options = $this->getOptionsByMenu(1);

        $result = $this->menuHasMatchingVariation($options, [self::CUISINE_SUGAR_FREE]);
        $this->assertFalse($result);
    }

    /**
     * #11: Menu with no options should always show (backward compat).
     */
    public function testDashboardFilter_NoOptionsMeansShowAll()
    {
        $this->seedVariationData();

        $options = $this->getOptionsByMenu(3);
        $this->assertEmpty($options);

        $result = $this->menuHasMatchingVariation($options, [self::CUISINE_GLUTEN_FREE]);
        $this->assertTrue($result);
    }

    /**
     * #12: Patient with no dietary preferences should see all menus.
     */
    public function testDashboardFilter_NoPreferencesMeansShowAll()
    {
        $this->seedVariationData();

        $this->insertMenuOption(1, 'GF only', [self::CUISINE_GLUTEN_FREE]);
        $options = $this->getOptionsByMenu(1);

        $result = $this->menuHasMatchingVariation($options, []);
        $this->assertTrue($result);
    }

    /**
     * #13: Multi-cuisine option matches if ANY patient preference overlaps.
     */
    public function testDashboardFilter_PartialOverlap()
    {
        $this->seedVariationData();

        $this->insertMenuOption(1, 'GF+DF', [self::CUISINE_GLUTEN_FREE, self::CUISINE_DAIRY_FREE]);
        $options = $this->getOptionsByMenu(1);

        $result = $this->menuHasMatchingVariation($options, [self::CUISINE_DAIRY_FREE]);
        $this->assertTrue($result);
    }

    /**
     * #14: Patient has multiple preferences, one matches.
     */
    public function testDashboardFilter_PatientMultiplePrefs()
    {
        $this->seedVariationData();

        $this->insertMenuOption(1, 'SF Toast', [self::CUISINE_SUGAR_FREE]);
        $options = $this->getOptionsByMenu(1);

        $result = $this->menuHasMatchingVariation($options, [self::CUISINE_GLUTEN_FREE, self::CUISINE_SUGAR_FREE]);
        $this->assertTrue($result);
    }

    /**
     * #15: Multiple options on a menu, only one needs to match.
     */
    public function testDashboardFilter_AnyOptionMatches()
    {
        $this->seedVariationData();

        $this->insertMenuOption(1, 'Regular Toast', [self::CUISINE_REGULAR]);
        $this->insertMenuOption(1, 'GF Toast', [self::CUISINE_GLUTEN_FREE]);
        $options = $this->getOptionsByMenu(1);

        $result = $this->menuHasMatchingVariation($options, [self::CUISINE_GLUTEN_FREE]);
        $this->assertTrue($result);
    }

    /**
     * #16: Completely disjoint preferences and options.
     */
    public function testDashboardFilter_CompletelyDisjoint()
    {
        $this->seedVariationData();

        $this->insertMenuOption(1, 'Regular', [self::CUISINE_REGULAR]);
        $this->insertMenuOption(1, 'Sugar Free', [self::CUISINE_SUGAR_FREE]);
        $options = $this->getOptionsByMenu(1);

        $result = $this->menuHasMatchingVariation($options, [self::CUISINE_GLUTEN_FREE, self::CUISINE_DAIRY_FREE]);
        $this->assertFalse($result);
    }

    // ═════════════════════════════════════════════════════════════════
    // EDGE CASES / LINK TABLE TESTS
    // ═════════════════════════════════════════════════════════════════

    /**
     * #17: Multiple allergens stored correctly as JSON.
     */
    public function testMultipleAllergens()
    {
        $this->seedVariationData();

        $id = $this->insertMenuOption(
            1, 'High allergen', [self::CUISINE_REGULAR],
            'Careful!', '300 Cal',
            [self::ALLERGEN_NUTS, self::ALLERGEN_DAIRY, self::ALLERGEN_GLUTEN]
        );

        $opt = $this->getOption($id);
        $allergens = json_decode($opt['allergenValues'], true);
        $this->assertCount(3, $allergens);
    }

    /**
     * #18: Options are isolated per menu item.
     */
    public function testOptionsIsolatedPerMenu()
    {
        $this->seedVariationData();

        $this->insertMenuOption(1, 'Toast GF', [self::CUISINE_GLUTEN_FREE]);
        $this->insertMenuOption(2, 'Soup DF', [self::CUISINE_DAIRY_FREE]);
        $this->insertMenuOption(3, 'Steak SF', [self::CUISINE_SUGAR_FREE]);

        $this->assertCount(1, $this->getOptionsByMenu(1));
        $this->assertCount(1, $this->getOptionsByMenu(2));
        $this->assertCount(1, $this->getOptionsByMenu(3));
    }

    /**
     * #19: Deleting one option leaves others on the same menu intact.
     */
    public function testDeleteOneOptionLeavesOthers()
    {
        $this->seedVariationData();

        $id1 = $this->insertMenuOption(1, 'GF', [self::CUISINE_GLUTEN_FREE]);
        $id2 = $this->insertMenuOption(1, 'DF', [self::CUISINE_DAIRY_FREE]);
        $id3 = $this->insertMenuOption(1, 'SF', [self::CUISINE_SUGAR_FREE]);

        $this->softDeleteOption($id2);

        $remaining = $this->getOptionsByMenu(1);
        $this->assertCount(2, $remaining);

        $names = array_column($remaining, 'menu_option_name');
        $this->assertContains('GF', $names);
        $this->assertContains('SF', $names);
        $this->assertNotContains('DF', $names);
    }

    /**
     * #20: Removing a link keeps the option record but hides it from that menu.
     */
    public function testRemoveLinkHidesFromMenu()
    {
        $this->seedVariationData();

        $id = $this->insertMenuOption(1, 'Linked Option', [self::CUISINE_REGULAR]);

        $this->assertCount(1, $this->getOptionsByMenu(1));

        // Remove the link but don't delete the option
        $this->removeLink(1, $id);

        $this->assertCount(0, $this->getOptionsByMenu(1));

        // Option still exists
        $opt = $this->getOption($id);
        $this->assertNotNull($opt);
        $this->assertEquals(0, (int)$opt['is_deleted']);
    }

    /**
     * #21: menu_option_name field is stored and returned correctly via aliased query.
     */
    public function testMenuOptionNameViaAliasedQuery()
    {
        $this->seedVariationData();

        $this->insertMenuOption(1, 'Brown Bread', [self::CUISINE_REGULAR]);
        $this->insertMenuOption(1, 'White Bread', [self::CUISINE_GLUTEN_FREE]);

        $options = $this->getOptionsByMenu(1);
        $names = array_column($options, 'menu_option_name');
        $this->assertContains('Brown Bread', $names);
        $this->assertContains('White Bread', $names);
    }
}
