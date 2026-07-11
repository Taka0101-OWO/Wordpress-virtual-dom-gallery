// @vitest-environment jsdom

import '@testing-library/jest-dom/vitest';
import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { DebugHud } from './DebugHud';

describe('DebugHud', () => {
  it('shows loaded, mounted, and window buffer statistics', () => {
    render(<DebugHud loaded={300} mounted={14} />);
    expect(screen.getByText('Loaded images: 300')).toBeTruthy();
    expect(screen.getByText('Mounted DOM tiles: 14')).toBeTruthy();
    expect(screen.getByText('Window buffer: 0.5 viewport')).toBeTruthy();
  });
});
