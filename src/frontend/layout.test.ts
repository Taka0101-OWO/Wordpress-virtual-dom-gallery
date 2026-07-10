import { describe, expect, it } from 'vitest';
import type { GalleryAsset } from '../shared/types';
import { buildMasonryLayout, columnCount, visibleItems } from './layout';

const asset = (id: string, width: number, height: number): GalleryAsset => ({
  id, width, height, alt: '', placeholder: '#000000', sources: [],
});

describe('columnCount', () => {
  it('uses two mobile, three tablet, and three desktop columns', () => {
    expect(columnCount(390, 2, 3, 3)).toBe(2);
    expect(columnCount(768, 2, 3, 3)).toBe(3);
    expect(columnCount(1024, 2, 3, 3)).toBe(3);
    expect(columnCount(1440, 2, 3, 3)).toBe(3);
  });
});

describe('buildMasonryLayout', () => {
  it('places each image in the current shortest column without changing aspect ratio', () => {
    const result = buildMasonryLayout([
      asset('a', 100, 200), asset('b', 100, 100), asset('c', 100, 50), asset('d', 100, 100),
    ], 302, 3, 2);
    expect(result.items[0]).toMatchObject({ x: 0, y: 0, renderWidth: 99.33333333333333 });
    expect(result.items[1].x).toBeCloseTo(101.333);
    expect(result.items[2].x).toBeCloseTo(202.666);
    expect(result.items[3].x).toBeCloseTo(202.666);
    expect(result.items[0].renderHeight / result.items[0].renderWidth).toBeCloseTo(2);
    expect(result.height).toBeGreaterThan(0);
  });

  it('returns an empty stable layout before width is measured', () => {
    expect(buildMasonryLayout([asset('a', 1, 1)], 0, 3, 2)).toEqual({ items: [], height: 0 });
  });
});

describe('visibleItems', () => {
  it('keeps only the viewport plus half a viewport above and below', () => {
    const layout = buildMasonryLayout(Array.from({ length: 30 }, (_, index) => asset(String(index), 100, 100)), 100, 1, 0);
    const top = visibleItems(layout.items, 0, 600);
    const middle = visibleItems(layout.items, 1200, 600);
    const bottom = visibleItems(layout.items, 2400, 600);

    expect(top.map((item) => item.id)).toEqual(Array.from({ length: 10 }, (_, index) => String(index)));
    expect(middle.map((item) => item.id)).toEqual(Array.from({ length: 14 }, (_, index) => String(index + 8)));
    expect(bottom.map((item) => item.id)).toEqual(Array.from({ length: 10 }, (_, index) => String(index + 20)));
  });
});
