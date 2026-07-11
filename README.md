# Taka Virtual Gallery

[繁體中文](#繁體中文) | [English](#english)

## 繁體中文

Taka Virtual Gallery 是一個以私人檔案儲存空間為來源的 WordPress 圖庫外掛。它不會把原圖註冊到 WordPress 媒體庫，而是建立索引、產生 WebP 衍生檔，並以虛擬化瀑布流呈現大量圖片。

> [!NOTE]
> 本專案是 **vibecoding 產物**。在正式環境使用前，請自行審查程式碼、安全邊界及部署方式。

### v0.1.5 功能

- 增量與背景資料夾掃描，保留持久化進度並可靠處理數字檔名游標
- 圖片批次審核、指派、排除、還原、發布與撤回
- 可重試的 WebP 衍生檔處理及等比例私人縮圖生成
- 由瀏覽器工作階段綁定的短效簽名 URL 傳送私人圖片
- Apache 傳送採 fail-closed 設計，不會退回公開 X-Sendfile 路徑
- Elementor widget 與 `[taka_gallery]` shortcode
- 響應式、視窗化 masonry layout 與穩定的單頁隨機排序
- 黑色 placeholder 與一次性圖片顯示動畫

### 系統需求

- WordPress 6.6 或以上
- PHP 8.1 或以上
- PHP Imagick
- 可供 PHP 讀取的原圖目錄
- 可供 PHP 寫入的衍生檔目錄
- Apache `mod_xsendfile`
- Node.js 與 npm（僅開發或重新建置前端時需要）

### 安裝與設定

1. 將外掛目錄放入 WordPress 的 `wp-content/plugins/`，或封裝為 ZIP 後上傳。
2. 啟用 **Taka Virtual Gallery**。
3. 在 **Taka Gallery → 設定** 填入原圖與衍生檔的絕對路徑。
4. 確認儲存環境狀態正常，再建立圖庫、映射相對資料夾及開始掃描。
5. 在 Elementor 加入 **Taka Virtual Gallery** widget，或使用 `[taka_gallery]` shortcode。

本倉庫刻意不提供特定主機、NAS、容器或反向代理設定。請依自己的環境，以最小權限原則設定私人儲存空間及媒體傳送。

### 開發

```bash
npm install
npm test
npm run lint
npm run lint:php
npm run build
```

`assets/dist/` 是外掛執行所需的已編譯前端資源；`node_modules/`、本機快取及環境設定不會提交。

### 安全界線

外掛不會把原始路徑、原始檔名、EXIF 資料或永久媒體 URL 傳給公開瀏覽器。衍生檔 URL 綁定 HttpOnly 工作階段並具有有效期限，但這不是 DRM：任何已在瀏覽器顯示的圖片仍可透過開發者工具或螢幕截圖保存。

### 靈感與致謝

- [brunofranciscojs/react-gallery](https://github.com/brunofranciscojs/react-gallery)
- [gs25087/Virtualized-Masonry-Grid](https://github.com/gs25087/Virtualized-Masonry-Grid)

### 授權

本專案依 [GNU General Public License v2.0 or later](LICENSE) 授權。

## English

Taka Virtual Gallery is a WordPress gallery plugin backed by private file storage. Instead of registering originals in the WordPress media library, it indexes source folders, creates WebP derivatives, and renders large collections through a virtualized masonry layout.

> [!NOTE]
> This project is a **vibecoding product**. Review the code, security boundaries, and deployment design before using it in production.

### v0.1.5 features

- Incremental and background folder scanning with persistent progress and reliable numeric filename cursors
- Batch review, assignment, exclusion, restoration, publishing, and withdrawal
- Retryable WebP derivative processing with proportional private thumbnail generation
- Private images delivered through short-lived signed URLs bound to a browser session
- Fail-closed Apache delivery that never falls back to exposing X-Sendfile paths
- Elementor widget and `[taka_gallery]` shortcode
- Responsive, windowed masonry with stable per-page random ordering
- Black placeholders and a one-time image reveal animation

### Requirements

- WordPress 6.6 or later
- PHP 8.1 or later
- PHP Imagick
- An originals directory readable by PHP
- A derivatives directory writable by PHP
- Apache `mod_xsendfile`
- Node.js and npm only for development or rebuilding frontend assets

### Installation and configuration

1. Place the plugin directory in `wp-content/plugins/`, or package it as a ZIP and upload it through WordPress.
2. Activate **Taka Virtual Gallery**.
3. Open **Taka Gallery → Settings** and enter absolute paths for originals and derivatives.
4. Confirm that the storage health check passes before creating galleries, mapping relative folders, or starting a scan.
5. Add the **Taka Virtual Gallery** Elementor widget or use the `[taka_gallery]` shortcode.

This repository intentionally excludes host-specific NAS, container, proxy, and server configuration. Configure private storage and media delivery for your environment using least-privilege access.

### Development

```bash
npm install
npm test
npm run lint
npm run lint:php
npm run build
```

`assets/dist/` contains the compiled frontend assets required at runtime. `node_modules/`, local caches, and environment configuration are excluded.

### Security boundary

The plugin does not expose original paths, original filenames, EXIF data, or permanent media URLs to public browsers. Derivative URLs are session-bound and expire, but this is not DRM: any image displayed in a browser can still be saved through developer tools or screenshots.

### Inspiration and acknowledgements

- [brunofranciscojs/react-gallery](https://github.com/brunofranciscojs/react-gallery)
- [gs25087/Virtualized-Masonry-Grid](https://github.com/gs25087/Virtualized-Masonry-Grid)

### License

Licensed under the [GNU General Public License v2.0 or later](LICENSE).
