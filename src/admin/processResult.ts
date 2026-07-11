export type ProcessResult = {
  processed?: number;
  errors?: Array<{ assetId: number; message: string }>;
  locked?: boolean;
};

export function processResultNotice(result: ProcessResult): { type: 'success' | 'error'; message: string } {
  if (result.locked) {
    return { type: 'error', message: '另一個衍生檔工作正在執行中，請稍後再試。' };
  }

  const processed = Math.max(0, Number(result.processed || 0));
  const failed = result.errors?.length || 0;
  if (!processed && !failed) {
    return { type: 'success', message: '目前沒有待處理圖片。' };
  }
  if (failed) {
    return { type: 'error', message: `衍生檔處理完成：成功 ${processed} 張，失敗 ${failed} 張。請查看「錯誤」分頁。` };
  }
  return { type: 'success', message: `衍生檔處理完成：成功 ${processed} 張。` };
}
