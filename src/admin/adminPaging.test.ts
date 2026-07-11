import { describe, expect, it } from 'vitest';
import { ADMIN_ASSETS_PER_PAGE, adminPageCount } from './adminPaging';

describe('admin asset paging', () => {
  it('uses 120 assets per page', () => {
    expect(ADMIN_ASSETS_PER_PAGE).toBe(120);
    expect(adminPageCount(294)).toBe(3);
  });
});
