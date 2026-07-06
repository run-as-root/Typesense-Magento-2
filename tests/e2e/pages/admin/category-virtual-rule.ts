import { type Page, type Locator } from '@playwright/test';

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
    // Deliberately no leading slash: the "admin" Playwright project's baseURL is
    // `${BASE_URL}/backend/admin/` (see playwright.config.ts). A leading-slash path resolves
    // against the *origin*, discarding that "/backend/admin/" prefix entirely and landing on the
    // storefront instead of the admin — confirmed live: CategoryMerchandiserPage.gotoCategoryEdit()
    // has this exact bug (it uses a leading slash) and its own spec's "section visible"/"products
    // table" assertions fail against a live Warden instance as a result. Not fixed here since it's
    // pre-existing, unrelated sibling test infra outside this task's scope — just not repeated.
    await this.page.goto(`catalog/category/edit/id/${categoryId}`, { waitUntil: 'domcontentloaded' });
    // Fieldset is collapsible/opened=false by default (see view/adminhtml/ui_component/
    // category_form.xml), mirroring CategoryMerchandiserPage.gotoCategoryEdit()'s own
    // click-to-expand pattern for the sibling "TypeSense Merchandising" fieldset.
    const section = this.page
      .locator('[data-index="typesense_virtual_category"], :has-text("TypeSense Virtual Category")')
      .first();
    if (await section.isVisible()) {
      await section.click();
    }
    await this.page.waitForTimeout(1000);
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

    const newChildSelect = this.conditionsContainer.locator('select[id$="__new_child"]').first();
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
