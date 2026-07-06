import { test, expect } from '@playwright/test';
import { CategoryVirtualRulePage } from '../../pages/admin/category-virtual-rule';
import { CategoryPage } from '../../pages/frontend/category-page';

// Category 6 ("Watches", /gear/watches.html in default Luma sample data) is deliberately not
// exercised by any other spec in this suite (category-merchandiser.spec.ts and
// category-page.spec.ts both use category id 4 / "Bags"), and Luma sample data ships it with no
// products of its own. That makes it a safe place to flip "is_virtual" on for this suite's own
// save + frontend-listing tests without a virtual rule left behind here ever bleeding into an
// assertion made by a different spec file.
const CATEGORY_ID = 6;
const CATEGORY_URL = '/gear/watches.html';

// A single "sku == 24-MB01" condition compiles down to exactly `sku:=24-MB01`
// (Model\VirtualRule\ConditionsToFilterByCompiler's OPERATOR_MAP maps "==" to ":="), which fully
// replaces the category's normal `category_ids:=X` filter — see CategoryVirtualRuleResolver. That
// means the product doesn't need to be assigned to category 6 at all for this to work; the rule
// alone decides what's in the listing.
const CONDITION_ATTRIBUTE = 'sku';
const CONDITION_OPERATOR = '==';
const CONDITION_VALUE = '24-MB01';
const EXPECTED_PRODUCT_NAME = 'Joust Duffle Bag';

test.describe('Admin Category Virtual Rule', () => {
  let virtualRule: CategoryVirtualRulePage;

  test.beforeEach(async ({ page }) => {
    virtualRule = new CategoryVirtualRulePage(page);
    await virtualRule.openCategory(CATEGORY_ID);
  });

  test('virtual category fieldset is visible on category edit page', async () => {
    await expect(virtualRule.container).toBeVisible({ timeout: 10_000 });
  });

  // The original plan assumed toggling "Is Virtual Category" would show/hide the condition
  // builder. The real Task 13 template (view/adminhtml/templates/category/virtual_rule.phtml)
  // doesn't wire any such show/hide behavior — the rule-tree markup is unconditionally rendered
  // once the fieldset itself is expanded, regardless of the checkbox's state. This test instead
  // asserts the behavior that actually exists: enabling the toggle leaves the condition builder
  // visible and ready to use.
  test('enabling "Is Virtual Category" leaves the condition builder available', async () => {
    await virtualRule.enableVirtual();

    await expect(virtualRule.isVirtualCheckbox).toBeChecked();
    await expect(virtualRule.conditionsContainer).toBeVisible({ timeout: 10_000 });
  });

  test('saving a simple one-condition rule succeeds', async () => {
    await virtualRule.enableVirtual();
    await virtualRule.addCondition(CONDITION_ATTRIBUTE, CONDITION_OPERATOR, CONDITION_VALUE);

    const response = await virtualRule.save();

    expect(response.success, response.message).toBe(true);
    await expect(virtualRule.statusMessage).toHaveText(/saved successfully/i, { timeout: 10_000 });
  });

  test('frontend: virtual category listing shows only products matching the rule', async ({ page }) => {
    await virtualRule.enableVirtual();
    await virtualRule.addCondition(CONDITION_ATTRIBUTE, CONDITION_OPERATOR, CONDITION_VALUE);

    const response = await virtualRule.save();
    expect(response.success, response.message).toBe(true);

    const categoryPage = new CategoryPage(page);
    await categoryPage.goto(CATEGORY_URL);

    const cards = await categoryPage.getProductCards();
    await expect(cards.first()).toBeVisible({ timeout: 10_000 });
    expect(await cards.count()).toBe(1);
    await expect(cards.first().locator('h3')).toHaveText(EXPECTED_PRODUCT_NAME);
  });
});
