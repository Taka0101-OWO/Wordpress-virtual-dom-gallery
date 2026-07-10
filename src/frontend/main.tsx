import { createRoot } from 'react-dom/client';
import { GalleryApp } from './GalleryApp';
import '../styles.css';

declare global {
  interface Window { TakaGalleryFrontend?: { restUrl: string; version: string } }
}

document.querySelectorAll<HTMLElement>('.taka-gallery-root').forEach((element) => {
  if (element.dataset.mounted === 'true') return;
  element.dataset.mounted = 'true';
  const config = JSON.parse(element.dataset.config || '{}');
  const restUrl = window.TakaGalleryFrontend?.restUrl;
  if (!restUrl) return;
  createRoot(element).render(<GalleryApp config={config} restUrl={restUrl} />);
});
