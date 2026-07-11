# Demo / 開發示範

## 繁體中文

`demo/index.html` 是 Taka Virtual Gallery 的開發測試入口，不是可獨立開啟的靜態 Demo。它需要同源 WordPress 提供 `/wp-json/taka-gallery/v1/` REST API 與有效媒體工作階段。

Demo 設定會開啟 DOM virtualization HUD，顯示已載入圖片、目前掛載的 DOM tile，以及半個 viewport overscan。正式 WordPress 頁面只會為具有 `manage_taka_galleries` 權限的管理員開啟 HUD。

- [查看 virtualization 截圖](../docs/media/virtualized-masonry.webp)
- [查看 HUD 特寫](../docs/media/debug-hud.png)

## English

`demo/index.html` is a development harness for Taka Virtual Gallery, not a standalone static demo. It requires a same-origin WordPress installation that serves `/wp-json/taka-gallery/v1/` and establishes valid media sessions.

The demo configuration enables the DOM virtualization HUD, which reports loaded images, currently mounted DOM tiles, and the half-viewport overscan buffer. Production WordPress pages enable the HUD only for administrators with the `manage_taka_galleries` capability.

- [View the virtualization screenshot](../docs/media/virtualized-masonry.webp)
- [View the HUD close-up](../docs/media/debug-hud.png)
