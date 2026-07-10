// @vitest-environment jsdom

import '@testing-library/jest-dom/vitest';
import { act, fireEvent, render } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { GalleryTile } from './GalleryTile';
import type { PositionedAsset } from './layout';
import { resetRevealed } from './revealState';

const item: PositionedAsset = {
  id: '00000000000000000000000000000001',
  width: 480,
  height: 720,
  alt: '',
  placeholder: '#000000',
  sources: [{
    width: 480,
    url: '/taka-media/v1/00000000000000000000000000000001/480.webp?exp=1&sig=test',
    expiresAt: 1,
  }],
  x: 0,
  y: 0,
  renderWidth: 240,
  renderHeight: 360,
  bottom: 360,
};

let enterViewport: (() => void) | undefined;
let observedThreshold = 0;

class MockIntersectionObserver implements IntersectionObserver {
  readonly root = null;
  readonly rootMargin = '0px';
  readonly scrollMargin = '0px';
  readonly thresholds: readonly number[];

  constructor(private callback: IntersectionObserverCallback, options?: IntersectionObserverInit) {
    observedThreshold = Number(options?.threshold || 0);
    this.thresholds = [observedThreshold];
    enterViewport = () => this.callback([{ isIntersecting: true } as IntersectionObserverEntry], this);
  }

  disconnect = vi.fn();
  observe = vi.fn();
  takeRecords = vi.fn(() => []);
  unobserve = vi.fn();
}

describe('GalleryTile reveal', () => {
  beforeEach(() => {
    resetRevealed();
    enterViewport = undefined;
    observedThreshold = 0;
    vi.stubGlobal('IntersectionObserver', MockIntersectionObserver);
  });

  it('waits for both decode and viewport entry, then does not replay the same asset', async () => {
    const first = render(<GalleryTile item={item} stagger={40} onExpired={vi.fn()} />);
    const image = first.container.querySelector('img');
    if (!image) throw new Error('Gallery image was not rendered.');
    Object.defineProperty(image, 'decode', { configurable: true, value: vi.fn().mockResolvedValue(undefined) });

    fireEvent.load(image);
    await act(async () => { await Promise.resolve(); });

    expect(image).not.toHaveClass('is-ready');
    expect(observedThreshold).toBe(0.08);

    await act(async () => enterViewport?.());
    expect(image).toHaveClass('is-ready');

    first.unmount();
    const second = render(<GalleryTile item={item} stagger={40} onExpired={vi.fn()} />);
    expect(second.container.querySelector('img')).toHaveClass('is-ready');
  });
});
