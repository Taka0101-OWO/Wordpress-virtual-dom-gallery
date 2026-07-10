import { useEffect, useRef, useState } from 'react';
import type { PositionedAsset } from './layout';
import { hasRevealed, markRevealed } from './revealState';

type Props = {
  item: PositionedAsset;
  stagger: number;
  onExpired: () => void;
};

export function GalleryTile({ item, stagger, onExpired }: Props) {
  const tileRef = useRef<HTMLDivElement>(null);
  const alreadyRevealed = hasRevealed(item.id);
  const [decoded, setDecoded] = useState(alreadyRevealed);
  const [enteredViewport, setEnteredViewport] = useState(alreadyRevealed);
  const [ready, setReady] = useState(alreadyRevealed);
  const failed = useRef(false);
  const sorted = [...item.sources].sort((a, b) => a.width - b.width);
  const srcSet = sorted.map((source) => `${source.url} ${source.width}w`).join(', ');
  const fallback = sorted[Math.min(sorted.length - 1, 1)]?.url || sorted[0]?.url;

  useEffect(() => {
    if (alreadyRevealed || !tileRef.current) return;
    if (!('IntersectionObserver' in window)) {
      setEnteredViewport(true);
      return;
    }

    const observer = new IntersectionObserver((entries) => {
      if (entries.some((entry) => entry.isIntersecting)) {
        setEnteredViewport(true);
        observer.disconnect();
      }
    }, { threshold: 0.08 });
    observer.observe(tileRef.current);
    return () => observer.disconnect();
  }, [alreadyRevealed]);

  useEffect(() => {
    if (!ready && decoded && enteredViewport) {
      markRevealed(item.id);
      setReady(true);
    }
  }, [decoded, enteredViewport, item.id, ready]);

  return (
    <div
      ref={tileRef}
      className="taka-tile"
      data-asset-id={item.id}
      style={{
        transform: `translate3d(${item.x}px, ${item.y}px, 0)`,
        width: item.renderWidth,
        height: item.renderHeight,
        backgroundColor: '#000',
      }}
    >
      <img
        src={fallback}
        srcSet={srcSet}
        sizes={`${Math.ceil(item.renderWidth)}px`}
        alt={item.alt}
        width={item.width}
        height={item.height}
        loading="lazy"
        decoding="async"
        draggable={false}
        className={ready ? 'is-ready' : ''}
        style={{ '--taka-delay': `${alreadyRevealed ? 0 : stagger}ms` } as React.CSSProperties}
        onLoad={(event) => {
          const image = event.currentTarget;
          const done = () => setDecoded(true);
          if (typeof image.decode === 'function') void image.decode().then(done).catch(done);
          else done();
        }}
        onError={() => {
          if (!failed.current) {
            failed.current = true;
            onExpired();
          }
        }}
      />
    </div>
  );
}
