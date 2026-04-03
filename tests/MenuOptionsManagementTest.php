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
     * EXACT SET MATCH logic:
     * - Patient has preferences: variation must have EXACTLY the same set of cuisines
     * - Patient has NO preferences: only match standard variations (empty cuisine)
     * - Always check allergen exclusion
     */
    protected function menuHasMatchingVariation(array $variations, array $patientCuisineIds, array $patientAllergyIds = []): bool
    {
        if (empty($variations)) return true; // No variations = show everything (backward compat)

        $patientIds = array_map('strval', $patientCuisineIds);
        sort($patientIds);
        $allergyIds = array_map('strval', $patientAllergyIds);

        foreach ($variations as $v) {
            $vCuisineIds = json_decode($v['cuisine_type_ids'] ?? '[]', true) ?: [];
            $vCuisineStrs = array_map('strval', $vCuisineIds);
            sort($vCuisineStrs);

            // EXACT SET MATCH for cuisine:
            if (empty($patientIds)) {
                // No dietary preferences: only match standard variations (empty cuisine)
                if (!empty($vCuisineStrs)) continue;
            } else {
                // Has dietary preferences: variation must have EXACTLY the same set of cuisines
                if (count($vCuisineStrs) !== count($patientIds)) continue;
                if ($patientIds !== $vCuisineStrs) continue;
            }

            // Check allergen exclusion
            if (!empty($allergyIds)) {
                $vAllergenIds = json_decode($v['allergenValues'] ?? '[]', true) ?: [];
                if (!empty($vAllergenIds)) {
                    $conflict = !empty(array_intersect(array_map('strval', $vAllergenIds), $allergyIds));
                    if ($conflict) continue;
                }
            }

            return true; // Found a matching variation
        }
        return false;
    }

    /**
     * Mirrors the option-level cuisine filtering on the dashboard.
     * For each individual menu option, checks if it should be shown to a patient.
     */
    protected function optionMatchesCuisine(array $option, array $patientCuisineIds): bool
    {
        $patientSet = array_map('strval', $patientCuisineIds);
        sort($patientSet);

        $itemCuisines = json_decode($option['cuisine_type_ids'] ?? $option['cuisineValues'] ?? '[]', true) ?: [];
        $itemSet = array_map('strval', $itemCuisines);
        sort($itemSet);

        if (empty($patientSet)) {
            // No dietary preferences: show only standard items (empty cuisine)
            return empty($itemSet);
        } else {
            // Has preferences: EXACT set match required
            return ($patientSet === $itemSet);
        }
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
     * #12: Patient with no dietary preferences should see menus with standard (empty cuisine) variations.
     */
    public function testDashboardFilter_NoPreferencesMeansShowStandard()
    {
        $this->seedVariationData();

        // Menu with only a GF variation — no standard variation
        $this->insertMenuOption(1, 'GF only', [self::CUISINE_GLUTEN_FREE]);
        $options = $this->getOptionsByMenu(1);

        // Patient with no prefs should NOT see this menu (no standard variation exists)
        $result = $this->menuHasMatchingVariation($options, []);
        $this->assertFalse($result, 'No-pref patient should not see menus with only dietary-specific variations');
    }

    /**
     * #13: Variation [GF,DF] does NOT match patient with just [DF] — exact set match required.
     */
    public function testDashboardFilter_PartialOverlap_NoMatch()
    {
        $this->seedVariationData();

        $this->insertMenuOption(1, 'GF+DF', [self::CUISINE_GLUTEN_FREE, self::CUISINE_DAIRY_FREE]);
        $options = $this->getOptionsByMenu(1);

        // Patient wants only DF, but variation has [GF,DF] — NOT an exact match
        $result = $this->menuHasMatchingVariation($options, [self::CUISINE_DAIRY_FREE]);
        $this->assertFalse($result, 'Variation [GF,DF] should not match patient with only [DF]');
    }

    /**
     * #14: Patient has [GF,SF] — variation with only [SF] does NOT match (exact set required).
     */
    public function testDashboardFilter_PatientMultiplePrefs_NoPartialMatch()
    {
        $this->seedVariationData();

        $this->insertMenuOption(1, 'SF Toast', [self::CUISINE_SUGAR_FREE]);
        $options = $this->getOptionsByMenu(1);

        // Patient wants [GF,SF], variation only has [SF] — NOT an exact match
        $result = $this->menuHasMatchingVariation($options, [self::CUISINE_GLUTEN_FREE, self::CUISINE_SUGAR_FREE]);
        $this->assertFalse($result, 'Variation [SF] should not match patient with [GF,SF] — exact set required');
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
    // 2-RESTRICTION BUG TESTS (Client reported: patients with 2
    // dietary restrictions see limited options or nothing)
    // ═════════════════════════════════════════════════════════════════

    /**
     * BUG TEST #1: Patient with [GF,DF] sees menu that has exact [GF,DF] combo variation.
     */
    public function testTwoRestrictions_ExactComboExists_ShowsMenu()
    {
        $this->seedVariationData();

        // Menu "Toast" has: standard, GF-only, DF-only, and a GF+DF combo
        $this->insertMenuOption(1, 'Standard Toast', []);
        $this->insertMenuOption(1, 'GF Toast', [self::CUISINE_GLUTEN_FREE]);
        $this->insertMenuOption(1, 'DF Toast', [self::CUISINE_DAIRY_FREE]);
        $this->insertMenuOption(1, 'GF+DF Toast', [self::CUISINE_GLUTEN_FREE, self::CUISINE_DAIRY_FREE]);

        $options = $this->getOptionsByMenu(1);

        $result = $this->menuHasMatchingVariation($options, [self::CUISINE_GLUTEN_FREE, self::CUISINE_DAIRY_FREE]);
        $this->assertTrue($result, 'Patient with [GF,DF] should see menu when exact [GF,DF] variation exists');
    }

    /**
     * BUG TEST #2: Patient with [GF,DF] sees NOTHING when menu only has separate GF and DF variations.
     * THIS IS THE REPORTED BUG — exact match means [GF] alone or [DF] alone won't match [GF,DF].
     */
    public function testTwoRestrictions_OnlySeparateVariations_NoMenu()
    {
        $this->seedVariationData();

        // Menu "Toast" has GF and DF as separate variations, but NOT a [GF,DF] combo
        $this->insertMenuOption(1, 'GF Toast', [self::CUISINE_GLUTEN_FREE]);
        $this->insertMenuOption(1, 'DF Toast', [self::CUISINE_DAIRY_FREE]);

        $options = $this->getOptionsByMenu(1);

        // With EXACT match, patient [GF,DF] won't match [GF] or [DF] separately
        $result = $this->menuHasMatchingVariation($options, [self::CUISINE_GLUTEN_FREE, self::CUISINE_DAIRY_FREE]);
        $this->assertFalse($result, 'Patient with [GF,DF] should NOT match separate [GF] and [DF] variations (exact set match)');
    }

    /**
     * BUG TEST #3: Patient [GF,DF] — option-level filtering hides all non-exact variations.
     * Verifies the per-option filter mirrors the menu-level filter.
     */
    public function testTwoRestrictions_OptionLevelFiltering()
    {
        $this->seedVariationData();

        $this->insertMenuOption(1, 'Standard Toast', []);
        $this->insertMenuOption(1, 'GF Toast', [self::CUISINE_GLUTEN_FREE]);
        $this->insertMenuOption(1, 'DF Toast', [self::CUISINE_DAIRY_FREE]);
        $this->insertMenuOption(1, 'GF+DF Toast', [self::CUISINE_GLUTEN_FREE, self::CUISINE_DAIRY_FREE]);

        $options = $this->getOptionsByMenu(1);
        $patientPrefs = [self::CUISINE_GLUTEN_FREE, self::CUISINE_DAIRY_FREE];

        $visible = array_filter($options, fn($opt) => $this->optionMatchesCuisine($opt, $patientPrefs));
        $visibleNames = array_column($visible, 'menu_option_name');

        $this->assertCount(1, $visible, 'Patient [GF,DF] should see exactly 1 option (the combo)');
        $this->assertContains('GF+DF Toast', $visibleNames);
        $this->assertNotContains('GF Toast', $visibleNames);
        $this->assertNotContains('DF Toast', $visibleNames);
        $this->assertNotContains('Standard Toast', $visibleNames);
    }

    /**
     * BUG TEST #4: No-preference patient sees only standard (empty cuisine) options.
     */
    public function testNoPreference_SeesOnlyStandard()
    {
        $this->seedVariationData();

        $this->insertMenuOption(1, 'Standard Toast', []);
        $this->insertMenuOption(1, 'GF Toast', [self::CUISINE_GLUTEN_FREE]);
        $this->insertMenuOption(1, 'GF+DF Toast', [self::CUISINE_GLUTEN_FREE, self::CUISINE_DAIRY_FREE]);

        $options = $this->getOptionsByMenu(1);

        // Menu level: should show because standard variation exists
        $result = $this->menuHasMatchingVariation($options, []);
        $this->assertTrue($result, 'No-pref patient should see menu when standard variation exists');

        // Option level: only standard shown
        $visible = array_filter($options, fn($opt) => $this->optionMatchesCuisine($opt, []));
        $visibleNames = array_column($visible, 'menu_option_name');
        $this->assertCount(1, $visible);
        $this->assertContains('Standard Toast', $visibleNames);
    }

    /**
     * BUG TEST #5: Single-restriction patient [GF] sees only GF options, not GF+DF combos.
     */
    public function testSingleRestriction_ExactMatch()
    {
        $this->seedVariationData();

        $this->insertMenuOption(1, 'Standard Toast', []);
        $this->insertMenuOption(1, 'GF Toast', [self::CUISINE_GLUTEN_FREE]);
        $this->insertMenuOption(1, 'GF+DF Toast', [self::CUISINE_GLUTEN_FREE, self::CUISINE_DAIRY_FREE]);

        $options = $this->getOptionsByMenu(1);
        $patientPrefs = [self::CUISINE_GLUTEN_FREE];

        // Menu level: should show because [GF] variation exists
        $result = $this->menuHasMatchingVariation($options, $patientPrefs);
        $this->assertTrue($result);

        // Option level: only [GF] shown, not [GF,DF] or standard
        $visible = array_filter($options, fn($opt) => $this->optionMatchesCuisine($opt, $patientPrefs));
        $visibleNames = array_column($visible, 'menu_option_name');
        $this->assertCount(1, $visible);
        $this->assertContains('GF Toast', $visibleNames);
        $this->assertNotContains('GF+DF Toast', $visibleNames);
    }

    /**
     * BUG TEST #6: Allergen exclusion works with exact cuisine match.
     * Patient [GF,DF] with Sugar allergy — combo variation with Sugar allergen is hidden.
     */
    public function testTwoRestrictions_AllergenExclusion()
    {
        $this->seedVariationData();

        // GF+DF variation that contains a sugar allergen
        $this->insertMenuOption(1, 'GF+DF Sweet Toast', [self::CUISINE_GLUTEN_FREE, self::CUISINE_DAIRY_FREE],
            'Sweet version', '200 Cal', [self::ALLERGEN_NUTS]);

        $options = $this->getOptionsByMenu(1);

        // Patient has [GF,DF] and is allergic to nuts
        $result = $this->menuHasMatchingVariation($options, 
            [self::CUISINE_GLUTEN_FREE, self::CUISINE_DAIRY_FREE],
            [self::ALLERGEN_NUTS]
        );
        $this->assertFalse($result, 'Variation should be excluded due to allergen conflict even with exact cuisine match');
    }

    /**
     * BUG TEST #7: Patient [GF,DF] — menu has only standard and [GF] variations → sees nothing.
     * This is the real-world scenario the client reported.
     */
    public function testTwoRestrictions_RealWorldBug_NoComboVariation()
    {
        $this->seedVariationData();

        // Typical menu setup: standard + individual dietary variations, no combo
        $this->insertMenuOption(1, 'Standard Eggs', []);
        $this->insertMenuOption(1, 'GF Eggs', [self::CUISINE_GLUTEN_FREE]);

        $options = $this->getOptionsByMenu(1);

        // Patient with [GF,DF] should NOT match [GF] or [] — this is the bug scenario
        $result = $this->menuHasMatchingVariation($options, [self::CUISINE_GLUTEN_FREE, self::CUISINE_DAIRY_FREE]);
        $this->assertFalse($result, 'Patient with [GF,DF] correctly sees nothing when no [GF,DF] combo variation exists');

        // At option level, also nothing visible
        $visible = array_filter($options, fn($opt) => $this->optionMatchesCuisine($opt, 
            [self::CUISINE_GLUTEN_FREE, self::CUISINE_DAIRY_FREE]));
        $this->assertCount(0, $visible, 'No options should be visible for [GF,DF] patient when no combo exists');
    }

    /**
     * BUG TEST #8: Cuisine ID order doesn't matter — [DF,GF] matches [GF,DF].
     */
    public function testTwoRestrictions_OrderIndependent()
    {
        $this->seedVariationData();

        // Variation stored as [GF, DF]
        $this->insertMenuOption(1, 'GF+DF Toast', [self::CUISINE_GLUTEN_FREE, self::CUISINE_DAIRY_FREE]);

        $options = $this->getOptionsByMenu(1);

        // Patient stored as [DF, GF] (reversed order)
        $result = $this->menuHasMatchingVariation($options, [self::CUISINE_DAIRY_FREE, self::CUISINE_GLUTEN_FREE]);
        $this->assertTrue($result, 'Cuisine order should not matter — [DF,GF] matches [GF,DF]');
    }

    /**
     * BUG TEST #9: Three restrictions — exact match with triple combo.
     */
    public function testThreeRestrictions_ExactTripleMatch()
    {
        $this->seedVariationData();

        $this->insertMenuOption(1, 'GF+DF+SF Toast', [self::CUISINE_GLUTEN_FREE, self::CUISINE_DAIRY_FREE, self::CUISINE_SUGAR_FREE]);
        $this->insertMenuOption(1, 'GF+DF Toast', [self::CUISINE_GLUTEN_FREE, self::CUISINE_DAIRY_FREE]);

        $options = $this->getOptionsByMenu(1);

        // Patient with all 3 should only match the triple combo
        $result = $this->menuHasMatchingVariation($options,
            [self::CUISINE_GLUTEN_FREE, self::CUISINE_DAIRY_FREE, self::CUISINE_SUGAR_FREE]);
        $this->assertTrue($result);

        // Option level: only triple combo visible
        $visible = array_filter($options, fn($opt) => $this->optionMatchesCuisine($opt,
            [self::CUISINE_GLUTEN_FREE, self::CUISINE_DAIRY_FREE, self::CUISINE_SUGAR_FREE]));
        $this->assertCount(1, $visible);
        $this->assertEquals('GF+DF+SF Toast', array_values($visible)[0]['menu_option_name']);
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
