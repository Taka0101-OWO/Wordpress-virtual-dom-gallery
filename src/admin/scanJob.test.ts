import { describe, expect, it } from 'vitest';
import { scanJobActive, scanJobLabel, scanJobNotice, scanJobProgress, type ScanJobState } from './scanJob';

const job = (overrides: Partial<ScanJobState> = {}): ScanJobState => ({
  id: 'test', status: 'running', phase: 'discovering', total: 126, scanned: 100, remaining: 26,
  discovered: 24, queued: 0, errors: [], startedAt: 1, updatedAt: 1, nextRunAt: 1, ...overrides,
});

describe('scan job presentation', () => {
  it('calculates bounded progress', () => {
    expect(scanJobProgress(job())).toBe(79);
    expect(scanJobProgress(job({ status: 'complete' }))).toBe(100);
  });

  it('identifies active states and waiting copy', () => {
    expect(scanJobActive(job({ status: 'waiting' }))).toBe(true);
    expect(scanJobActive(job({ status: 'complete' }))).toBe(false);
    expect(scanJobLabel(job({ status: 'waiting' }))).toBe('等待檔案穩定');
  });

  it('reports completed counts and errors', () => {
    expect(scanJobNotice(job({ status: 'complete', queued: 24 })).message).toContain('加入處理佇列 24 張');
    expect(scanJobNotice(job({ status: 'failed', errors: [{ folderId: 2, message: 'unreadable' }] })).type).toBe('error');
  });
});
