import { FormEvent, useCallback, useEffect, useState } from 'react';
import { AlertTriangle, Check, CircleGauge, FolderSync, GalleryHorizontalEnd, LoaderCircle, Plus, RefreshCw, RotateCcw, Save, Settings, Trash2 } from 'lucide-react';
import { apiFetch } from '../shared/api';
import type { AdminAsset, FolderMapping, Gallery } from '../shared/types';

type Config = { restUrl: string; nonce: string; version: string };
type AssetResponse = { items: AdminAsset[]; total: number; page: number; pages: number };
type SettingsData = {
  originals_path: string;
  derivatives_path: string;
  url_ttl: number;
  scan_batch: number;
  process_batch: number;
  legacy_upload_prefix: string;
  originalsReadable?: boolean;
  derivativesWritable?: boolean;
  imagickAvailable?: boolean;
};

const tabs = [
  { id: 'pending_review', label: '待發布', icon: CircleGauge },
  { id: 'published', label: '已發布', icon: Check },
  { id: 'queued', label: '處理佇列', icon: RefreshCw },
  { id: 'error', label: '錯誤', icon: AlertTriangle },
  { id: 'settings', label: '設定', icon: Settings },
] as const;

export function AdminApp({ config }: { config: Config }) {
  const [activeTab, setActiveTab] = useState<string>('pending_review');
  const [galleries, setGalleries] = useState<Gallery[]>([]);
  const [folders, setFolders] = useState<FolderMapping[]>([]);
  const [assets, setAssets] = useState<AssetResponse>({ items: [], total: 0, page: 1, pages: 0 });
  const [settings, setSettings] = useState<SettingsData | null>(null);
  const [galleryFilter, setGalleryFilter] = useState(0);
  const [selected, setSelected] = useState<Set<number>>(new Set());
  const [busy, setBusy] = useState('');
  const [notice, setNotice] = useState<{ type: 'success' | 'error'; message: string } | null>(null);

  const request = useCallback(<T,>(path: string, init?: RequestInit) => apiFetch<T>(config.restUrl, path, init, config.nonce), [config]);

  const loadStructure = useCallback(async () => {
    const [galleryRows, folderRows, settingRows] = await Promise.all([
      request<Gallery[]>('galleries?context=edit'),
      request<FolderMapping[]>('folders'),
      request<SettingsData>('settings'),
    ]);
    setGalleries(galleryRows);
    setFolders(folderRows);
    setSettings(settingRows);
  }, [request]);

  const loadAssets = useCallback(async (page = 1) => {
    if (activeTab === 'settings') return;
    const query = new URLSearchParams({ status: activeTab, page: String(page), perPage: '48' });
    if (galleryFilter) query.set('galleryId', String(galleryFilter));
    setAssets(await request<AssetResponse>(`assets?${query}`));
    setSelected(new Set());
  }, [activeTab, galleryFilter, request]);

  useEffect(() => { loadStructure().catch(showError(setNotice)); }, [loadStructure]);
  useEffect(() => { loadAssets().catch(showError(setNotice)); }, [loadAssets]);

  const runJob = async (job: 'scan' | 'process') => {
    setBusy(job);
    setNotice(null);
    try {
      const result = await request<Record<string, unknown>>(`jobs/${job}`, { method: 'POST', body: '{}' });
      setNotice({ type: 'success', message: job === 'scan' ? 'NAS 增量掃描完成一個批次。' : '衍生檔處理完成一個批次。' });
      await Promise.all([loadStructure(), loadAssets()]);
      return result;
    } catch (reason) {
      setNotice({ type: 'error', message: errorMessage(reason) });
    } finally {
      setBusy('');
    }
  };

  const applySelection = async (action: 'publish' | 'retry' | 'exclude') => {
    if (!selected.size) return;
    setBusy(action);
    try {
      await request(`assets/${action}`, { method: 'POST', body: JSON.stringify({ assetIds: Array.from(selected) }) });
      setNotice({ type: 'success', message: action === 'publish' ? `已發布 ${selected.size} 張圖片。` : action === 'exclude' ? `已排除 ${selected.size} 張圖片。` : `已將 ${selected.size} 張圖片放回處理佇列。` });
      await Promise.all([loadStructure(), loadAssets()]);
    } catch (reason) {
      setNotice({ type: 'error', message: errorMessage(reason) });
    } finally {
      setBusy('');
    }
  };

  const assignSelection = async (destination: number) => {
    if (!selected.size || !destination) return;
    setBusy('assign');
    try {
      await request('assets/assign', { method: 'POST', body: JSON.stringify({ assetIds: Array.from(selected), galleryId: destination }) });
      setNotice({ type: 'success', message: `已改派 ${selected.size} 張圖片。` });
      await Promise.all([loadStructure(), loadAssets()]);
    } catch (reason) { setNotice({ type: 'error', message: errorMessage(reason) }); }
    finally { setBusy(''); }
  };

  return (
    <main className="taka-admin">
      <header className="taka-admin__header">
        <div>
          <span className="taka-admin__eyebrow">Taka</span>
          <h1>Gallery Manager</h1>
        </div>
        <div className="taka-admin__actions">
          <button className="button" type="button" disabled={!!busy} onClick={() => runJob('scan')}>
            {busy === 'scan' ? <LoaderCircle className="is-spinning" /> : <FolderSync />} 同步 NAS
          </button>
          <button className="button button-primary" type="button" disabled={!!busy} onClick={() => runJob('process')}>
            {busy === 'process' ? <LoaderCircle className="is-spinning" /> : <RefreshCw />} 處理佇列
          </button>
        </div>
      </header>

      {notice && <div className={`taka-notice is-${notice.type}`} role="status">{notice.message}</div>}

      <section className="taka-admin__summary" aria-label="圖庫狀態">
        <div><strong>{galleries.length}</strong><span>圖庫</span></div>
        <div><strong>{galleries.reduce((sum, row) => sum + row.publishedCount, 0)}</strong><span>已發布</span></div>
        <div><strong>{galleries.reduce((sum, row) => sum + row.pendingCount, 0)}</strong><span>待確認</span></div>
        <div className={settings?.originalsReadable && settings?.derivativesWritable && settings?.imagickAvailable ? 'is-healthy' : 'is-warning'}>
          <strong>{settings?.originalsReadable && settings?.derivativesWritable && settings?.imagickAvailable ? '正常' : '需檢查'}</strong><span>儲存環境</span>
        </div>
      </section>

      <nav className="taka-admin__tabs" aria-label="Gallery Manager views">
        {tabs.map(({ id, label, icon: Icon }) => (
          <button key={id} type="button" className={activeTab === id ? 'is-active' : ''} onClick={() => setActiveTab(id)}>
            <Icon aria-hidden="true" /> {label}
          </button>
        ))}
      </nav>

      {activeTab === 'settings' ? (
        settings && <SettingsView settings={settings} galleries={galleries} folders={folders} request={request} onChanged={loadStructure} setNotice={setNotice} />
      ) : (
        <section className="taka-assets-section">
          <div className="taka-assets-toolbar">
            <label>圖庫
              <select value={galleryFilter} onChange={(event) => setGalleryFilter(Number(event.target.value))}>
                <option value={0}>全部</option>
                {galleries.map((gallery) => <option key={gallery.id} value={gallery.id}>{gallery.name}</option>)}
              </select>
            </label>
            <span>{assets.total} 張</span>
            <div className="taka-assets-toolbar__commands">
              <button className="button" type="button" disabled={!assets.items.length} onClick={() => setSelected(selected.size === assets.items.length ? new Set() : new Set(assets.items.map((item) => item.assetId)))}>
                <Check /> {selected.size === assets.items.length && assets.items.length ? '取消全選' : '全選本頁'}
              </button>
              {activeTab === 'pending_review' && <AssignControl galleries={galleries} disabled={!selected.size || !!busy} onAssign={assignSelection} />}
              {activeTab === 'pending_review' && <button className="button" disabled={!selected.size || !!busy} onClick={() => applySelection('exclude')}><Trash2 /> 排除</button>}
              {activeTab === 'pending_review' && <button className="button button-primary" disabled={!selected.size || !!busy} onClick={() => applySelection('publish')}><Check /> 發布所選</button>}
              {activeTab === 'error' && <button className="button" disabled={!selected.size || !!busy} onClick={() => applySelection('retry')}><RotateCcw /> 重試所選</button>}
            </div>
          </div>
          <AssetGrid items={assets.items} selected={selected} onSelected={setSelected} />
          {!assets.items.length && <div className="taka-empty"><GalleryHorizontalEnd /><span>目前沒有項目</span></div>}
          {assets.pages > 1 && <Pagination page={assets.page} pages={assets.pages} onPage={loadAssets} />}
        </section>
      )}
    </main>
  );
}

function AssignControl({ galleries, disabled, onAssign }: { galleries: Gallery[]; disabled: boolean; onAssign: (id: number) => void }) {
  const [destination, setDestination] = useState(0);
  return <span className="taka-assign"><select aria-label="改派至圖庫" value={destination} onChange={(event) => setDestination(Number(event.target.value))}><option value="0">改派圖庫</option>{galleries.map((gallery) => <option key={gallery.id} value={gallery.id}>{gallery.name}</option>)}</select><button className="button" disabled={disabled || !destination} onClick={() => onAssign(destination)}>套用</button></span>;
}

function AssetGrid({ items, selected, onSelected }: { items: AdminAsset[]; selected: Set<number>; onSelected: (value: Set<number>) => void }) {
  const toggle = (id: number) => {
    const next = new Set(selected);
    if (next.has(id)) next.delete(id);
    else next.add(id);
    onSelected(next);
  };
  return (
    <div className="taka-asset-grid">
      {items.map((asset) => {
        const source = asset.sources.find((item) => item.width === 480) || asset.sources[0];
        return (
          <article key={asset.assetId} className={selected.has(asset.assetId) ? 'taka-asset is-selected' : 'taka-asset'}>
            <label className="taka-asset__check"><input type="checkbox" checked={selected.has(asset.assetId)} onChange={() => toggle(asset.assetId)} /><span className="screen-reader-text">選取圖片</span></label>
            {source ? <img src={source.url} alt="" loading="lazy" /> : <div className="taka-asset__fallback" />}
            <div className="taka-asset__meta">
              <strong>{asset.galleryName || '未分類'}</strong>
              <span title={asset.relativePath}>{asset.relativePath.split('/').pop()}</span>
              {asset.error && <em>{asset.error}</em>}
            </div>
          </article>
        );
      })}
    </div>
  );
}

function SettingsView({ settings, galleries, folders, request, onChanged, setNotice }: {
  settings: SettingsData;
  galleries: Gallery[];
  folders: FolderMapping[];
  request: <T>(path: string, init?: RequestInit) => Promise<T>;
  onChanged: () => Promise<void>;
  setNotice: (notice: { type: 'success' | 'error'; message: string } | null) => void;
}) {
  const [form, setForm] = useState(settings);
  const [galleryName, setGalleryName] = useState('');
  const [galleryId, setGalleryId] = useState(0);
  const [folderPath, setFolderPath] = useState('');
  const [migration, setMigration] = useState<Array<{ postId: number; postTitle: string; widgetId: string; count: number; suggestedName: string }>>([]);
  const [migrationNames, setMigrationNames] = useState<Record<string, string>>({});

  useEffect(() => setForm(settings), [settings]);

  const saveSettings = async (event: FormEvent) => {
    event.preventDefault();
    try {
      await request('settings', { method: 'POST', body: JSON.stringify(form) });
      await onChanged();
      setNotice({ type: 'success', message: '儲存設定已更新。' });
    } catch (reason) { setNotice({ type: 'error', message: errorMessage(reason) }); }
  };

  const addGallery = async (event: FormEvent) => {
    event.preventDefault();
    if (!galleryName.trim()) return;
    try {
      await request('galleries', { method: 'POST', body: JSON.stringify({ name: galleryName }) });
      setGalleryName('');
      await onChanged();
    } catch (reason) { setNotice({ type: 'error', message: errorMessage(reason) }); }
  };

  const addFolder = async (event: FormEvent) => {
    event.preventDefault();
    if (!galleryId || !folderPath.trim()) return;
    try {
      await request('folders', { method: 'POST', body: JSON.stringify({ galleryId, relativePath: folderPath }) });
      setFolderPath('');
      await onChanged();
    } catch (reason) { setNotice({ type: 'error', message: errorMessage(reason) }); }
  };

  const removeFolder = async (id: number) => {
    if (!window.confirm('移除此 NAS 映射？已匯入的圖片不會被刪除。')) return;
    await request(`folders/${id}`, { method: 'DELETE' });
    await onChanged();
  };

  const removeGallery = async (id: number) => {
    if (!window.confirm('刪除此圖庫與關聯？NAS 原圖及衍生檔不會被刪除。')) return;
    await request(`galleries/${id}`, { method: 'DELETE' });
    await onChanged();
  };

  const toggleGallery = async (gallery: Gallery) => {
    await request(`galleries/${gallery.id}`, { method: 'POST', body: JSON.stringify({ status: gallery.status === 'publish' ? 'draft' : 'publish' }) });
    await onChanged();
  };

  const discoverMigration = async () => {
    try {
      const rows = await request<typeof migration>('migration/discover');
      setMigration(rows);
      setMigrationNames(Object.fromEntries(rows.map((row) => [`${row.postId}:${row.widgetId}`, row.suggestedName])));
      if (!rows.length) setNotice({ type: 'success', message: '沒有找到可匯入的 Elementor Gallery。' });
    } catch (reason) { setNotice({ type: 'error', message: errorMessage(reason) }); }
  };

  const importMigration = async (row: typeof migration[number]) => {
    const key = `${row.postId}:${row.widgetId}`;
    try {
      const result = await request<{ imported: number; missing: string[] }>('migration/import', { method: 'POST', body: JSON.stringify({ postId: row.postId, widgetId: row.widgetId, name: migrationNames[key] || row.suggestedName }) });
      setNotice({ type: result.missing.length ? 'error' : 'success', message: `已建立 ${result.imported} 筆私有索引；找不到 ${result.missing.length} 筆原檔。` });
      setMigration((current) => current.filter((candidate) => `${candidate.postId}:${candidate.widgetId}` !== key));
      await onChanged();
    } catch (reason) { setNotice({ type: 'error', message: errorMessage(reason) }); }
  };

  return (
    <div className="taka-settings">
      <section>
        <h2>儲存與批次</h2>
        <form className="taka-form" onSubmit={saveSettings}>
          <label>原圖私有路徑<input value={form.originals_path} onChange={(event) => setForm({ ...form, originals_path: event.target.value })} /></label>
          <label>衍生檔私有路徑<input value={form.derivatives_path} onChange={(event) => setForm({ ...form, derivatives_path: event.target.value })} /></label>
          <label>舊媒體路徑前綴<input value={form.legacy_upload_prefix} onChange={(event) => setForm({ ...form, legacy_upload_prefix: event.target.value })} /></label>
          <div className="taka-form__row">
            <label>URL 時效（秒）<input type="number" min="60" max="3600" value={form.url_ttl} onChange={(event) => setForm({ ...form, url_ttl: Number(event.target.value) })} /></label>
            <label>掃描批次<input type="number" min="10" max="1000" value={form.scan_batch} onChange={(event) => setForm({ ...form, scan_batch: Number(event.target.value) })} /></label>
            <label>處理批次<input type="number" min="1" max="20" value={form.process_batch} onChange={(event) => setForm({ ...form, process_batch: Number(event.target.value) })} /></label>
          </div>
          <button className="button button-primary" type="submit"><Save /> 儲存</button>
        </form>
      </section>

      <section>
        <h2>圖庫</h2>
        <form className="taka-inline-form" onSubmit={addGallery}><input placeholder="圖庫名稱" value={galleryName} onChange={(event) => setGalleryName(event.target.value)} /><button className="button" type="submit"><Plus /> 新增</button></form>
        <div className="taka-list">
          {galleries.map((gallery) => <div key={gallery.id}><span><strong>{gallery.name}</strong><small>{gallery.slug} · {gallery.publishedCount} 張 · {gallery.status === 'publish' ? '公開' : '草稿'}</small></span><span className="taka-list__actions"><button type="button" title={gallery.status === 'publish' ? '轉為草稿' : '發布圖庫'} onClick={() => toggleGallery(gallery)}>{gallery.status === 'publish' ? <RotateCcw /> : <Check />}</button><button type="button" title="刪除圖庫" onClick={() => removeGallery(gallery.id)}><Trash2 /></button></span></div>)}
        </div>
      </section>

      <section>
        <h2>NAS 資料夾映射</h2>
        <form className="taka-inline-form" onSubmit={addFolder}>
          <select value={galleryId} onChange={(event) => setGalleryId(Number(event.target.value))}><option value="0">選擇圖庫</option>{galleries.map((gallery) => <option key={gallery.id} value={gallery.id}>{gallery.name}</option>)}</select>
          <input placeholder="例如 Gallery/Events" value={folderPath} onChange={(event) => setFolderPath(event.target.value)} />
          <button className="button" type="submit"><Plus /> 建立映射</button>
        </form>
        <div className="taka-list">
          {folders.map((folder) => <div key={folder.id} className={folder.lastError ? 'has-error' : ''}><span><strong>{folder.relativePath}</strong><small>{folder.galleryName} · {folder.scanCursor ? '同步中' : folder.lastScanAt || '尚未同步'}</small>{folder.lastError && <em>{folder.lastError}</em>}</span><button type="button" title="移除映射" onClick={() => removeFolder(folder.id)}><Trash2 /></button></div>)}
        </div>
      </section>
      <section className="taka-migration">
        <h2>舊 Elementor 圖庫</h2>
        <button className="button" type="button" onClick={discoverMigration}><FolderSync /> 掃描舊圖庫</button>
        <div className="taka-list">
          {migration.map((row) => {
            const key = `${row.postId}:${row.widgetId}`;
            return <div key={key}><span><strong>{row.postTitle || `Post ${row.postId}`}</strong><small>{row.count} 張 · widget {row.widgetId}</small><input value={migrationNames[key] || ''} onChange={(event) => setMigrationNames({ ...migrationNames, [key]: event.target.value })} /></span><button className="button" type="button" title="匯入" onClick={() => importMigration(row)}><Plus /></button></div>;
          })}
        </div>
      </section>
    </div>
  );
}

function Pagination({ page, pages, onPage }: { page: number; pages: number; onPage: (page: number) => void }) {
  return <nav className="taka-pagination" aria-label="分頁"><button className="button" disabled={page <= 1} onClick={() => onPage(page - 1)}>上一頁</button><span>{page} / {pages}</span><button className="button" disabled={page >= pages} onClick={() => onPage(page + 1)}>下一頁</button></nav>;
}

function errorMessage(reason: unknown): string { return reason instanceof Error ? reason.message : '操作失敗。'; }
function showError(setter: (notice: { type: 'error'; message: string }) => void) { return (reason: unknown) => setter({ type: 'error', message: errorMessage(reason) }); }
