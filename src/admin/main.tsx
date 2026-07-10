import { createRoot } from 'react-dom/client';
import { AdminApp } from './AdminApp';
import '../styles.css';

declare global {
  interface Window { TakaGalleryAdmin?: { restUrl: string; nonce: string; version: string } }
}

const root = document.getElementById('taka-gallery-admin');
if (root && window.TakaGalleryAdmin) createRoot(root).render(<AdminApp config={window.TakaGalleryAdmin} />);
