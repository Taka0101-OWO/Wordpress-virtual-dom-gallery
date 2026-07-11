export const ADMIN_ASSETS_PER_PAGE = 120;

export function adminPageCount(total: number): number {
  return Math.ceil(Math.max(0, total) / ADMIN_ASSETS_PER_PAGE);
}
