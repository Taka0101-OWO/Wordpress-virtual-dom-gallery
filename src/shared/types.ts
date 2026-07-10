export type Gallery = {
  id: number;
  name: string;
  slug: string;
  status: 'publish' | 'draft';
  menuOrder: number;
  publishedCount: number;
  pendingCount: number;
};

export type MediaSource = {
  width: number;
  url: string;
  expiresAt: number;
};

export type GalleryAsset = {
  id: string;
  width: number;
  height: number;
  alt: string;
  placeholder: string;
  sources: MediaSource[];
};

export type AdminAsset = GalleryAsset & {
  assetId: number;
  status: string;
  galleryId: number | null;
  galleryName: string | null;
  relativePath: string;
  error: string | null;
  updatedAt: string;
};

export type FolderMapping = {
  id: number;
  galleryId: number;
  galleryName: string;
  relativePath: string;
  enabled: boolean;
  lastScanAt: string | null;
  lastError: string | null;
  scanCursor: string | null;
};
