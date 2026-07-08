import { type Page, type Locator, expect } from '@playwright/test';

/**
 * Page object for the "TypeSense Virtual Category" fieldset on the category edit page.
 *
 * Selectors below were read from the REAL Task 13 output (Block\Adminhtml\Category\VirtualRule +
 * view/adminhtml/templates/category/virtual_rule.phtml), not the original plan's guessed
 * "#typesense-virtual-rule-container" id — that id doesn't exist. Unlike CategoryMerchandiserPage's
 * client-rendered AJAX grid, this fieldset embeds Magento's own native Magento_Rule condition
 * builder (the same widget Catalog Price Rule / Cart Price Rule use), so the condition tree is real
 * server-rendered rule builder HTML driven by Magento_Rule/rules.js (VarienRulesForm) — every
 * attribute/operator/value element id follows that module's own "{prefix}__{nodeId}__{field}"
 * convention, not anything this module invented.
 */
export class CategoryVirtualRulePage {
  readonly page: Page;
  readonly container: Locator;
  readonly isVirtualCheckbox: Locator;
  readonly rootIdInput: Locator;
  readonly rootName: Locator;
  /** The rule-tree root, id = Block\Adminhtml\Category\VirtualRule::getJsObjectName(). */
  readonly conditionsContainer: Locator;
  readonly saveButton: Locator;
  readonly statusMessage: Locator;

  constructor(page: Page) {
    this.page = page;
    this.container = page.locator('#typesense-category-virtual-rule');
    this.isVirtualCheckbox = page.locator('#typesense-vrule-is-virtual');
    this.rootIdInput = page.locator('#typesense-vrule-root-id');
    this.rootName = page.locator('#typesense-vrule-root-name');
    this.conditionsContainer = page.locator('#typesense_virtual_rule_conditions_fieldset');
    this.saveButton = page.locator('#typesense-vrule-save-btn');
    this.statusMessage = page.locator('#typesense-vrule-status');
  }

  async openCategory(categoryId: number) {
    // No leading slash: the "admin" Playwright project's baseURL is `${BASE_URL}/backend/` (see
    // playwright.config.ts). A leading-slash path resolves against the *origin*, discarding that
    // "/backend/" prefix entirely and landing on the storefront instead of the admin.
    await this.page.goto(`catalog/category/edit/id/${categoryId}`, { waitUntil: 'domcontentloaded' });
    // Fieldset is collapsible/opened=false by default (see view/adminhtml/ui_component/
    // category_form.xml), mirroring CategoryMerchandiserPage.gotoCategoryEdit()'s own
    // click-to-expand pattern for the sibling "TypeSense Merchandising" fieldset.
    //
    // Must target the ".admin__collapsible-title" header specifically, not the whole
    // "[data-index=...]" fieldset wrapper: the wrapper's own bounding box includes the (hidden,
    // zero-height-when-collapsed) content area, so a plain `.click()` on the wrapper can land
    // outside the actual clickable title bar and silently fail to expand the section.
    //
    // The collapsible's click handler is a KnockoutJS binding that only attaches once the UI
    // component form finishes initializing client-side (well after "domcontentloaded" fires) —
    // clicking too early is a silent no-op. Wait for the header to actually be visible/stable
    // first, then retry the click until the (KO-rendered) container really shows up instead of
    // trusting a single click + fixed sleep.
    const section = this.page
      .locator('[data-index="typesense_virtual_category"] .admin__collapsible-title')
      .first();
    await section.waitFor({ state: 'visible', timeout: 15_000 });
    await expect(async () => {
      if (await this.container.isVisible()) return;
      await section.click();
      await expect(this.container).toBeVisible({ timeout: 3_000 });
    }).toPass({ timeout: 15_000 });
  }

  async enableVirtual() {
    if (!(await this.isVirtualCheckbox.isChecked())) {
      await this.isVirtualCheckbox.check();
    }
  }

  /**
   * Reveals a rule-builder field hidden behind Magento_Rule's "click label to edit" pattern:
   * Magento\Rule\Block\Editable wraps every attribute/operator/value element in a
   * `<span class="element">` that starts CSS-hidden until its sibling `<a class="label">` is
   * clicked (see rules.js's VarienRulesForm.initParam()/showParamInputField()). Without this,
   * Playwright's actionability checks (element must be visible) reject select/fill calls on the
   * real, still-hidden control.
   */
  private async reveal(field: Locator): Promise<void> {
    const ruleParam = field.locator(
      'xpath=ancestor::span[contains(concat(" ", normalize-space(@class), " "), " rule-param ")][1]',
    );
    const label = ruleParam.locator('a.label');
    if ((await label.count()) > 0 && (await label.isVisible().catch(() => false))) {
      await label.click();
    }
  }

  /**
   * Adds one leaf "Product Attribute" condition via the root combine's "Add" dropdown.
   *
   * Model\VirtualRule\Condition\Combine::getNewChildSelectOptions() renders every "Product
   * Attribute" option's value as "<ConditionClass>|<attributeCode>" (e.g.
   * "RunAsRoot\TypeSense\Model\VirtualRule\Condition\Product|sku"); Controller\Adminhtml\
   * CategoryVirtualRule\NewConditionHtml::execute() splits that on "|" and pre-fills the new
   * condition row's attribute from it. That means the attribute is already set by the time the
   * AJAX-fetched row lands in the DOM — this method only has to fill in operator + value on it.
   *
   * Only supports adding a single top-level condition (all this suite needs): the newly-inserted
   * row is always the last one in the tree, since Magento inserts new rows above the trailing
   * "add" control (Combine::asHtmlRecursive()).
   */
  async addCondition(attribute: string, operator: string, value: string): Promise<void> {
    const operatorSelects = this.conditionsContainer.locator('select[id$="__operator"]');
    const rowsBefore = await operatorSelects.count();

    // The "new_child" select is wrapped in the same Magento_Rule "click label to reveal" pattern
    // as operator/value fields (its visible face is the "+" add-condition button) — it needs the
    // same reveal() treatment before Playwright's actionability checks will allow selecting it.
    const newChildSelect = this.conditionsContainer.locator('select[id$="__new_child"]').first();
    await this.reveal(newChildSelect);
    await newChildSelect.selectOption({
      value: `RunAsRoot\\TypeSense\\Model\\VirtualRule\\Condition\\Product|${attribute}`,
    });

    // Wait for rules.js's onChange handler to finish its AJAX round-trip to newConditionHtml and
    // insert the new row before asserting anything about it.
    await this.page.waitForFunction(
      (expectedCount) => document.querySelectorAll('select[id$="__operator"]').length > expectedCount,
      rowsBefore,
      { timeout: 10_000 },
    );

    const newOperator = operatorSelects.last();
    await this.reveal(newOperator);
    await newOperator.selectOption(operator);

    const newValue = this.conditionsContainer.locator('input[id$="__value"]').last();
    await this.reveal(newValue);
    await newValue.fill(value);
  }

  /**
   * Clicks Save and waits for the underlying `typesense/categoryvirtualrule/save` AJAX POST to
   * complete, returning its parsed JSON body ({success: boolean, message?: string}) so callers can
   * assert against the real server response instead of only UI text.
   */
  async save(): Promise<{ success: boolean; message?: string }> {
    const responsePromise = this.page.waitForResponse(
      (response) => response.url().includes('/categoryvirtualrule/save') && response.request().method() === 'POST',
    );
    await this.saveButton.click();
    const response = await responsePromise;

    return response.json();
  }
}
