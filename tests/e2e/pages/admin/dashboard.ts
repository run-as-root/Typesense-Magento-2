import { type Page, type Locator } from '@playwright/test';

export class DashboardPage {
  readonly page: Page;
  readonly container: Locator;
  readonly statusBar: Locator;
  readonly testConnectionButton: Locator;
  readonly connectionCard: Locator;
  readonly checklistCard: Locator;
  readonly checklistItems: Locator;
  readonly collectionsTable: Locator;
  readonly quickActions: Locator;

  constructor(page: Page) {
    this.page = page;
    this.container = page.locator('.ts-dashboard');
    this.statusBar = page.locator('.ts-status-bar');
    this.testConnectionButton = page.locator('#ts-test-connection');
    this.connectionCard = page.locator('.ts-card').filter({ hasText: 'Connection' });
    this.checklistCard = page.locator('.ts-card').filter({ hasText: 'Checklist' });
    this.checklistItems = page.locator('.ts-checklist li');
    this.collectionsTable = page.locator('.ts-card').filter({ hasText: 'Collections' });
    this.quickActions = page.locator('.ts-card').filter({ hasText: 'Quick Actions' });
  }

  async goto() {
    await this.page.goto('/typesense/dashboard/index', { waitUntil: 'domcontentloaded' });
  }

  async testConnection() {
    await this.testConnectionButton.click();
    await this.page.waitForTimeout(2000);
  }
}
