export function DebugHud({ loaded, mounted }: { loaded: number; mounted: number }) {
  return (
    <aside className="taka-gallery__debug" aria-label="Gallery development statistics">
      <span>Loaded images: {loaded}</span>
      <span>Mounted DOM tiles: {mounted}</span>
      <span>Window buffer: 0.5 viewport</span>
    </aside>
  );
}
