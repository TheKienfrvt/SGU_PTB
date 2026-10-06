# SGU Photobooth

Bản mã nguồn kiosk SGU dựa trên [PhotoboothProject/photobooth](https://github.com/PhotoboothProject/photobooth), tại commit `1658977e1151c2eea23697d9b93ca5026fe63583`. Giấy phép gốc nằm trong [LICENSE](LICENSE); hướng dẫn upstream được giữ tại [UPSTREAM_README.md](UPSTREAM_README.md).

## Luồng đang dùng

1. Người dùng chọn khung từ `templates/frames/`.
2. Trình duyệt xin quyền Cam Link và hiển thị preview.
3. Sau mỗi lần đếm ngược, trình duyệt tạo JPEG từ frame Cam Link và gửi tới `api/captureSession.php`.
4. Số ảnh theo số ô của khung đã chọn; backend ghép JPEG cuối, tạo preview và cho phép chụp lại từng ô.
5. Người dùng có thể tải ảnh, chọn thư mục tự lưu và tạo tờ in A5 từ hai khung thành phẩm.

Preset Docker tại `docker/kiosk.config.inc.php` đặt `sgu.capture_mode=browser` và tắt Windows Camera Agent. Bản xuất này không chứa Sony SDK bridge, driver, token hay dữ liệu phiên chụp. Các phần video và tính năng upstream khác vẫn nằm trong mã nền, nhưng không thuộc luồng kiosk SGU đang nghiệm thu.

## Chạy và kiểm tra

Trên Windows có Docker Desktop:

```powershell
docker compose config --quiet
docker compose up -d --build photobooth
```

Sau khi mở kiosk trên `localhost` hoặc HTTPS, cấp quyền camera trong trình duyệt, chọn đúng Cam Link rồi thử một phiên với khung có số ô mong muốn. Cấu hình đã tồn tại trong Docker volume sẽ không bị preset ghi đè. Lệnh in cần được cấu hình và kiểm tra trên máy vận hành.

Kiểm thử mã nguồn:

```powershell
npm ci
node --test tests/js/*.test.cjs
npm run eslint
```

Các kiểm thử phần mềm dùng fixture hoặc mock. Chúng không xác nhận Cam Link, ảnh in hoặc máy in thật. Không đưa `.env`, `config/my.config.inc.php`, `data/`, `private/`, `var/` hoặc ảnh của khách vào Git; các đường dẫn này đã được ignore.
