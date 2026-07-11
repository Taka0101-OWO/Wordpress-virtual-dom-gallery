export type ScanJobState = {
  id: string;
  status: 'idle' | 'queued' | 'running' | 'waiting' | 'complete' | 'failed';
  phase: 'idle' | 'discovering' | 'waiting' | 'verifying' | 'complete';
  total: number;
  scanned: number;
  remaining: number;
  discovered: number;
  queued: number;
  errors: Array<{ folderId: number; message: string }>;
  startedAt: number;
  updatedAt: number;
  nextRunAt: number;
};

export function scanJobActive(job: ScanJobState | null): boolean {
  return !!job && ['queued', 'running', 'waiting'].includes(job.status);
}

export function scanJobProgress(job: ScanJobState): number {
  if (job.status === 'complete' || job.status === 'failed') return 100;
  if (job.total < 1) return 0;
  return Math.max(0, Math.min(100, Math.round((job.scanned / job.total) * 100)));
}

export function scanJobLabel(job: ScanJobState): string {
  if (job.status === 'waiting') return '等待檔案穩定';
  if (job.phase === 'verifying') return '確認檔案並加入處理佇列';
  if (job.status === 'queued') return '等待背景掃描';
  if (job.status === 'failed') return '同步完成，但部分資料夾發生錯誤';
  if (job.status === 'complete') return 'NAS 同步完成';
  return '掃描 NAS 圖片';
}

export function scanJobNotice(job: ScanJobState): { type: 'success' | 'error'; message: string } {
  const failed = job.errors.length;
  if (failed) {
    return { type: 'error', message: `NAS 同步完成：發現 ${job.discovered} 張，加入處理佇列 ${job.queued} 張，${failed} 個資料夾發生錯誤。` };
  }
  return { type: 'success', message: `NAS 同步完成：發現 ${job.discovered} 張，加入處理佇列 ${job.queued} 張。` };
}
