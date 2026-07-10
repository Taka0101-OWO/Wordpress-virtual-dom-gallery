import type { GalleryAsset } from '../shared/types';

export type PositionedAsset = GalleryAsset & {
  x: number;
  y: number;
  renderWidth: number;
  renderHeight: number;
  bottom: number;
};

export function columnCount(viewportWidth: number, mobile: number, tablet: number, desktop: number): number {
  if (viewportWidth < 768) return mobile;
  if (viewportWidth < 1025) return tablet;
  return desktop;
}

export function buildMasonryLayout(items: GalleryAsset[], containerWidth: number, columns: number, gap: number): { items: PositionedAsset[]; height: number } {
  if (!items.length || containerWidth <= 0 || columns <= 0) return { items: [], height: 0 };
  const width = Math.max(1, (containerWidth - gap * (columns - 1)) / columns);
  const heights = Array.from({ length: columns }, () => 0);
  const positioned = items.map((item) => {
    let column = 0;
    for (let index = 1; index < heights.length; index += 1) {
      if (heights[index] < heights[column]) column = index;
    }
    const height = item.width > 0 && item.height > 0 ? width * (item.height / item.width) : width;
    const x = column * (width + gap);
    const y = heights[column];
    heights[column] = y + height + gap;
    return { ...item, x, y, renderWidth: width, renderHeight: height, bottom: y + height };
  });
  return { items: positioned, height: Math.max(0, ...heights) - gap };
}

export function visibleItems(items: PositionedAsset[], viewportTop: number, viewportHeight: number, overscan = 0.5): PositionedAsset[] {
  const padding = viewportHeight * overscan;
  const start = viewportTop - padding;
  const end = viewportTop + viewportHeight + padding;
  return items.filter((item) => item.bottom >= start && item.y <= end);
}
