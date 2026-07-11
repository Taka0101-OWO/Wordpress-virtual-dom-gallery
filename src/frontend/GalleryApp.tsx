import { useCallback, useEffect, useLayoutEffect, useMemo, useRef, useState } from 'react';
import { LoaderCircle, RefreshCw } from 'lucide-react';
import { apiFetch } from '../shared/api';
import type { Gallery, GalleryAsset } from '../shared/types';
import { buildMasonryLayout, columnCount, visibleItems } from './layout';
import { GalleryTile } from './GalleryTile';
import { DebugHud } from './DebugHud';

type Config = {
  gallery: string;
  navigation: boolean;
  mobileColumns: number;
  tabletColumns: number;
  desktopColumns: number;
  gap: number;
  debug?: boolean;
};

type PageState = {
  items: GalleryAsset[];
  cursor: string | null;
  seed: string;
  loaded: boolean;
};

type Props = { config: Config; restUrl: string };

export function GalleryApp({ config, restUrl }: Props) {
  const rootRef = useRef<HTMLDivElement>(null);
  const [galleries, setGalleries] = useState<Gallery[]>([]);
  const [active, setActive] = useState('');
  const [pages, setPages] = useState<Record<string, PageState>>({});
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');
  const [containerWidth, setContainerWidth] = useState(0);
  const [viewport, setViewport] = useState({ top: 0, height: window.innerHeight, width: window.innerWidth });
  const requestLock = useRef(false);
  const storageKey = `taka-gallery:${window.location.pathname}`;

  useEffect(() => {
    apiFetch<Gallery[]>(restUrl, 'galleries').then((rows) => {
      setGalleries(rows);
      const url = new URL(window.location.href);
      const legacyQuery = url.searchParams.get('gallery') || '';
      url.searchParams.delete('gallery');
      const remembered = config.navigation
        ? String(window.history.state?.takaGallery || sessionStorage.getItem(storageKey) || legacyQuery)
        : '';
      const initial = rows.find((row) => row.slug === remembered)?.slug || rows.find((row) => row.slug === config.gallery)?.slug || rows[0]?.slug || '';
      setActive(initial);
      if (initial) sessionStorage.setItem(storageKey, initial);
      window.history.replaceState({ ...window.history.state, takaGallery: initial }, '', `${url.pathname}${url.search}${url.hash}`);
    }).catch((reason: Error) => setError(reason.message));
  }, [config.gallery, config.navigation, restUrl, storageKey]);

  useLayoutEffect(() => {
    if (!rootRef.current) return;
    const observer = new ResizeObserver(([entry]) => setContainerWidth(entry.contentRect.width));
    observer.observe(rootRef.current);
    return () => observer.disconnect();
  }, []);

  useEffect(() => {
    let frame = 0;
    const update = () => {
      cancelAnimationFrame(frame);
      frame = requestAnimationFrame(() => {
        const root = rootRef.current;
        if (!root) return;
        const absoluteTop = root.getBoundingClientRect().top + window.scrollY;
        setViewport({ top: Math.max(0, window.scrollY - absoluteTop), height: window.innerHeight, width: window.innerWidth });
      });
    };
    update();
    window.addEventListener('scroll', update, { passive: true });
    window.addEventListener('resize', update, { passive: true });
    return () => {
      cancelAnimationFrame(frame);
      window.removeEventListener('scroll', update);
      window.removeEventListener('resize', update);
    };
  }, []);

  const current = pages[active] || { items: [], cursor: null, seed: '', loaded: false };
  const columns = columnCount(viewport.width, config.mobileColumns, config.tabletColumns, config.desktopColumns);
  const layout = useMemo(() => buildMasonryLayout(current.items, containerWidth, columns, config.gap), [current.items, containerWidth, columns, config.gap]);
  const visible = useMemo(() => visibleItems(layout.items, viewport.top, viewport.height), [layout.items, viewport]);

  const loadMore = useCallback(async (reset = false) => {
    if (!active || requestLock.current) return;
    const page = reset ? { items: [], cursor: null, seed: '', loaded: false } : (pages[active] || { items: [], cursor: null, seed: '', loaded: false });
    if (!reset && page.loaded && !page.cursor) return;
    requestLock.current = true;
    setLoading(true);
    setError('');
    try {
      const params = new URLSearchParams({ limit: '30' });
      if (page.cursor) params.set('cursor', page.cursor);
      if (page.seed) params.set('shuffle_seed', page.seed);
      const response = await apiFetch<{ items: GalleryAsset[]; nextCursor: string | null; shuffleSeed: string }>(restUrl, `galleries/${encodeURIComponent(active)}/items?${params}`);
      setPages((previous) => ({
        ...previous,
        [active]: {
          items: reset ? response.items : [...(previous[active]?.items || []), ...response.items],
          cursor: response.nextCursor,
          seed: response.shuffleSeed,
          loaded: true,
        },
      }));
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : 'Unable to load gallery.');
    } finally {
      requestLock.current = false;
      setLoading(false);
    }
  }, [active, pages, restUrl]);

  useEffect(() => {
    if (active && !pages[active]?.loaded) void loadMore();
  }, [active, loadMore, pages]);

  useEffect(() => {
    const remaining = layout.height - (viewport.top + viewport.height);
    if (remaining < viewport.height * 2 && current.cursor && !loading) void loadMore();
  }, [current.cursor, layout.height, loadMore, loading, viewport]);

  const switchGallery = useCallback((slug: string, push = true) => {
    if (slug === active) return;
    setActive(slug);
    sessionStorage.setItem(storageKey, slug);
    if (push) {
      const url = new URL(window.location.href);
      url.searchParams.delete('gallery');
      window.history.pushState({ ...window.history.state, takaGallery: slug }, '', `${url.pathname}${url.search}${url.hash}`);
    }
    window.scrollTo({ top: 0, behavior: 'auto' });
  }, [active, storageKey]);

  useEffect(() => {
    const pop = (event: PopStateEvent) => {
      const slug = String(event.state?.takaGallery || '');
      if (slug && galleries.some((gallery) => gallery.slug === slug)) switchGallery(slug, false);
    };
    window.addEventListener('popstate', pop);
    return () => window.removeEventListener('popstate', pop);
  }, [galleries, switchGallery]);

  const refreshAsset = useCallback(async (publicId: string) => {
    try {
      const response = await apiFetch<{ items: GalleryAsset[] }>(restUrl, 'media/refresh', { method: 'POST', body: JSON.stringify({ publicIds: [publicId] }) });
      const replacement = response.items[0];
      if (!replacement) return;
      setPages((previous) => ({
        ...previous,
        [active]: { ...previous[active], items: previous[active].items.map((item) => item.id === publicId ? replacement : item) },
      }));
    } catch {
      setError('A display image expired and could not be refreshed.');
    }
  }, [active, restUrl]);

  return (
    <section className="taka-gallery" aria-label="Gallery">
      {config.navigation && galleries.length > 1 && (
        <nav className="taka-gallery__nav" aria-label="Gallery categories">
          {galleries.map((gallery) => (
            <button key={gallery.id} type="button" className={gallery.slug === active ? 'is-active' : ''} onClick={() => switchGallery(gallery.slug)}>
              {gallery.name}
            </button>
          ))}
        </nav>
      )}
      <div ref={rootRef} className="taka-masonry" style={{ height: layout.height || (loading ? '70vh' : 0), '--taka-gap': `${config.gap}px` } as React.CSSProperties}>
        {visible.map((item, index) => (
          <GalleryTile key={item.id} item={item} stagger={Math.min(120, (index % columns) * 40)} onExpired={() => refreshAsset(item.id)} />
        ))}
      </div>
      <div className="taka-gallery__status" aria-live="polite">
        {loading && <LoaderCircle className="is-spinning" aria-label="Loading" />}
        {error && <button type="button" onClick={() => loadMore()}><RefreshCw aria-hidden="true" /> Retry</button>}
      </div>
      {config.debug && <DebugHud loaded={current.items.length} mounted={visible.length} />}
    </section>
  );
}
