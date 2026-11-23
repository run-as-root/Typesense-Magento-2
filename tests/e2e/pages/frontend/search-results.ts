import { type Page } from '@playwright/test';
import { ListingPage } from './listing';

export class SearchResultsPage extends ListingPage {
  constructor(page: Page) {
    super(page);
  }

  async gotoQuery(query: string, extra = '') {
    await this.goto(`/catalogsearch/result/?q=${encodeURIComponent(query)}${extra}`);
  }
}
