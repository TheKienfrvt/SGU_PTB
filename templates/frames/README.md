# Frame templates

## Thêm trực tiếp trên màn hình kiosk

Nhấn **Thêm khung** ở góc trên màn hình **Chọn khung ảnh**, nhập tên tùy chọn rồi chọn một file PNG. PNG phải có từ 1 đến 8 vùng trong suốt khép kín để đặt ảnh và các vùng này không được chạm mép canvas. Hệ thống tự phát hiện vùng ảnh, tạo ID, thumbnail, `template.json` và thư mục template; khung mới được chọn sẵn sau khi trang tải lại.

Các khung mẫu đang dùng nằm trong các thư mục `mau-1`, `mau-2` và `sgu-dayy`.

Phần dưới đây dành cho trường hợp cần chỉnh manifest thủ công.

Each immediate subdirectory is one frame. The kiosk discovers it automatically; do not add frame IDs in JavaScript or PHP configuration.

```text
templates/frames/<template-id>/
├── template.json
├── thumbnail.jpg
├── overlay.png
└── background.png   # optional
```

`template-id` and `template.json.id` must match and use lowercase letters, digits and hyphens (for example `birthday-01`). `thumbnail.jpg` must be a JPEG. `overlay.png` is required, must use transparency where photographs will show, and must exactly match the canvas dimensions. `background.png`, when present, must also exactly match the canvas dimensions.

```json
{
  "id": "birthday-01",
  "name": "Birthday 01",
  "enabled": true,
  "canvas": {
    "width": 1200,
    "height": 1800,
    "orientation": "portrait",
    "background": "#ffffff"
  },
  "thumbnail": "thumbnail.jpg",
  "background": "background.png",
  "overlay": "overlay.png",
  "photo_slots": [
    {
      "id": "slot-1",
      "x": 100,
      "y": 260,
      "width": 1000,
      "height": 1200,
      "fit": "cover",
      "position": "center",
      "rotation": 0
    }
  ]
}
```

Coordinates start at the canvas’s top-left corner. Every slot must stay inside the canvas. `fit` accepts `cover` or `contain`; `position` accepts `center`, the four sides, or a corner such as `top-left`. Rotation is measured in degrees. The older `slots` field remains accepted for compatibility.

Set `enabled` to `false` to hide a frame without deleting it. Invalid, disabled, oversized or unsafe manifests/assets are omitted from the picker. A session stores the validated `template_id`; the backend revalidates that template before restore, capture, compose and confirmation. The renderer reads full-resolution selected JPEGs, applies the canvas background/optional background PNG, places one image per slot, then composites the transparent overlay and writes a new final JPEG. Camera originals are never overwritten.
