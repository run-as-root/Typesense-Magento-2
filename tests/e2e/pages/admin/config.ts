import { type Page, type Locator } from '@playwright/test';

export class ConfigPage {
  readonly page: Page;

  constructor(page: Page) {
    this.page = page;
  }

  async goto() {
    await this.page.goto('/system_config/edit/section/run_as_root_typesense/', { waitUntil: 'domcontentloaded' });
  }

  group(id: string) {
    return this.page.locator(`#run_as_root_typesense_${id}`);
  }

  groupHeader(id: string) {
    return this.page.locator(`#run_as_root_typesense_${id}-head`);
  }

  field(groupId: string, fieldId: string) {
    return this.page.locator(`#run_as_root_typesense_${groupId}_${fieldId}`);
  }

  async openGroup(id: string) {
    const header = this.groupHeader(id);
    const body = this.group(id);
    if (!(await body.isVisible())) {
      await header.click();
      await this.page.waitForTimeout(500);
    }
  }

  async save() {
    await this.page.locator('#save').click();
    await this.page.waitForLoadState('domcontentloaded');
  }
}
