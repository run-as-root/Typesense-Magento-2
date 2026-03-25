import { type Page, type Locator } from '@playwright/test';

export class SynonymsPage {
  readonly page: Page;
  readonly collectionSelector: Locator;
  readonly synonymRows: Locator;
  readonly typeSelector: Locator;
  readonly synonymsInput: Locator;
  readonly rootInput: Locator;
  readonly submitButton: Locator;
  readonly emptyState: Locator;

  constructor(page: Page) {
    this.page = page;
    this.collectionSelector = page.locator('.ts-collection-bar select');
    this.synonymRows = page.locator('.ts-synonym-row');
    this.typeSelector = page.locator('#synonym-type, [name="type"]');
    this.synonymsInput = page.locator('#synonym-words, [name="synonyms"]');
    this.rootInput = page.locator('#synonym-root, [name="root"]');
    this.submitButton = page.locator('button:has-text("Add Synonym"), button:has-text("Save")');
    this.emptyState = page.locator('.ts-empty');
  }

  async goto(collection?: string) {
    const url = collection
      ? `/typesense/synonym/index?collection=${encodeURIComponent(collection)}`
      : '/typesense/synonym/index';
    await this.page.goto(url, { waitUntil: 'domcontentloaded' });
  }

  async selectCollection(name: string) {
    await this.collectionSelector.selectOption({ label: name });
    await this.page.waitForTimeout(1000);
  }

  async addMultiWaySynonym(words: string) {
    await this.typeSelector.selectOption('multi-way');
    await this.synonymsInput.fill(words);
    await this.submitButton.click();
    await this.page.waitForTimeout(1000);
  }

  async addOneWaySynonym(root: string, words: string) {
    await this.typeSelector.selectOption('one-way');
    await this.rootInput.fill(root);
    await this.synonymsInput.fill(words);
    await this.submitButton.click();
    await this.page.waitForTimeout(1000);
  }

  async deleteSynonym(index: number) {
    const row = this.synonymRows.nth(index);
    await row.locator('button:has-text("Delete")').click();
    await this.page.waitForTimeout(1000);
  }
}
