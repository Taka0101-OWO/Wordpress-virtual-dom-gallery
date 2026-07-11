import { describe, expect, it } from 'vitest';
import { processResultNotice } from './processResult';

describe('processResultNotice', () => {
  it('reports processed images', () => {
    expect(processResultNotice({ processed: 5, errors: [] })).toEqual({ type: 'success', message: '衍生檔處理完成：成功 5 張。' });
  });

  it('reports failures and points to the error tab', () => {
    expect(processResultNotice({ processed: 3, errors: [{ assetId: 4, message: 'failed' }] })).toEqual({
      type: 'error',
      message: '衍生檔處理完成：成功 3 張，失敗 1 張。請查看「錯誤」分頁。',
    });
  });

  it('reports an active process lock', () => {
    expect(processResultNotice({ locked: true })).toEqual({ type: 'error', message: '另一個衍生檔工作正在執行中，請稍後再試。' });
  });

  it('reports an empty queue', () => {
    expect(processResultNotice({ processed: 0, errors: [] })).toEqual({ type: 'success', message: '目前沒有待處理圖片。' });
  });
});
