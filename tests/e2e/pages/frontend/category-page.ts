import { type Page } from '@playwright/test';
import { ListingPage } from './listing';

export class CategoryPage extends ListingPage {
  constructor(page: Page) {
    super(page);
  }
}
