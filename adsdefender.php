<?php
/**
 * Plugin Name:  AdsDefender
 * Description:  Bảo vệ ngân sách quảng cáo Google Ads khỏi click fraud — tự động sync IP từ Matomo,
 *               block ở tầng PHP, tích hợp marketing & tracking đầy đủ.
 * Version:      2.5.121
 * Author:       AdsDefender
 * Update URI:   https://github.com/rubidev/adsdefender-wp-plugin
 * Requires PHP: 7.4
 *
 * ════════════════════════════════════════════════════════════════════════════════
 *  TÍNH NĂNG CHÍNH
 * ════════════════════════════════════════════════════════════════════════════════
 *
 *  🛡  CHẶN CLICK FRAUD
 *      • Sync danh sách IP fraud từ Matomo AdsDefender mỗi 30 phút (WP-Cron)
 *      • Block ở 3 lớp: .htaccess (server-level), PHP (init hook), visitor cookie
 *      • Hỗ trợ IPv4, IPv6, CIDR (vd: 1.2.3.0/24, 2405::/32)
 *      • 3 hành động khi block: 403 Forbidden, Redirect, Trang trắng
 *      • Trang 403 tĩnh /adsdefender-blocked.html — không qua PHP, không redirect loop
 *      • Block thủ công: thêm IP/CIDR với ghi chú, không bị sync ghi đè
 *      • Visitor Blacklist: block theo Matomo visitor cookie dù đổi IP
 *      • Whitelist: IP/CIDR whitelist bảo vệ admin, văn phòng khỏi bị block nhầm
 *
 *  🌐  TƯƠNG THÍCH WEB SERVER
 *      • LiteSpeed Enterprise / Apache: ghi Deny vào .htaccess — block trước PHP
 *      • OpenLiteSpeed / Nginx: cookie bypass cache (adsdefender_nc=1)
 *      • Auto-detect SERVER_SOFTWARE hoặc chọn thủ công trong Settings
 *
 *  ☁️  CLOUDFLARE SUPPORT
 *      • Khi bật "Behind Cloudflare": lấy IP thật từ CF-Connecting-IP
 *      • Tự xác minh REMOTE_ADDR thuộc dải Cloudflare (bundle 23 CIDR ranges)
 *        trước khi tin header → chống spoofing header
 *
 *  🔗  CROSS-SITE BLOCK SHARING
 *      • Gán tag cho site (vd: "dien-lanh") — sites cùng tag chia sẻ block list
 *      • IP fraud site A → tự block trên tất cả sites cùng tag
 *
 *  🚀  MARKETING
 *      • Contact Bar: thanh liên hệ cố định (call/Zalo/Messenger) cấu hình trong admin
 *      • Popup & Lead: popup tùy chỉnh, thu thập lead vào DB
 *      • Leads Manager: xem, filter, export leads
 *      • UTM Attribution: ghi lại UTM source/medium/campaign mỗi session
 *
 *  📊  TRACKING TÍCH HỢP (1-click bật tắt)
 *      • Google Tag Manager (GTM)
 *      • Matomo / TrackSG
 *      • Meta Pixel (Facebook)
 *
 *  📝  CUSTOM SCRIPTS
 *      • Inject script vào: <head>, sau <body>, body bottom, trước </body>
 *      • Dùng cho chat widget, schema JSON-LD, phone tracking...
 *
 *  📲  THÔNG BÁO TELEGRAM
 *      • Alert khi có IP mới bị block (mỗi lần cron)
 *      • Alert khi có lead mới
 *
 *  🔄  TỰ ĐỘNG CẬP NHẬT
 *      • Hiển thị badge update trong WordPress admin khi có phiên bản mới
 *      • Cập nhật 1-click từ GitHub release — không cần vào WP.org
 *
 * ════════════════════════════════════════════════════════════════════════════════
 *  CÀI ĐẶT
 * ════════════════════════════════════════════════════════════════════════════════
 *
 *  1. Upload thư mục adsdefender/ vào /wp-content/plugins/
 *  2. Kích hoạt plugin trong WordPress → Plugins
 *  3. Vào AdsDefender → Hệ thống → Cài đặt:
 *       • Matomo URL: https://track.saigon.pro/
 *       • Secret Key: lấy tại Matomo → Personal Settings → Security → Auth token
 *       • Site ID: bấm "Tự động phát hiện" hoặc nhập tay
 *       • Web Server: chọn đúng loại server (hoặc để Auto)
 *       • Bật chặn IP: tick checkbox
 *  4. Bấm "Sync ngay" để lấy danh sách IP đầu tiên
 *
 * ════════════════════════════════════════════════════════════════════════════════
 *  CẤU TRÚC FILE
 * ════════════════════════════════════════════════════════════════════════════════
 *
 *  adsdefender.php          — Plugin entry, constants, activation hooks
 *  includes/
 *    core.php               — IP check, sync, .htaccess, REST API, site groups
 *    brute-force.php        — Brute force protection (login attempt limiting)
 *    firewall.php           — Request firewall: SQLi, XSS, path traversal, bad UA
 *    hide-login.php         — Custom login URL + XML-RPC disable
 *    rate-limit.php         — Rate limiting per IP (sliding window)
 *    honeypot.php           — Honeypot fields in login/comment/register forms
 *    404-detector.php       — 404 flood detection → lockout
 *    scripts.php            — Custom scripts, GTM, TrackSG, Meta Pixel inject
 *    contact-bar.php        — Contact bar front-end & admin
 *    popup.php              — Popup & lead capture front-end & admin
 *    utm.php                — UTM tracking & attribution
 *    update.php             — Auto-update via GitHub release
 *  admin/
 *    menu.php               — Admin menu: Dashboard, Marketing, Bảo vệ, Hệ thống
 *
 * ════════════════════════════════════════════════════════════════════════════════
 *  CHANGELOG
 * ════════════════════════════════════════════════════════════════════════════════
 *
 *  2.5.121 NANG CAP FIREWALL (SQLi / XSS / Path Traversal / RCE) — do tren noi dung
 *          doi thuc: ban cu chan nham 40% noi dung hop le VA bo lot 38% tan cong that.
 *          Ban moi: 0% chan nham, 0% bo lot (30 mau hop le + 26 mau tan cong).
 *          - Them lop CHUAN HOA payload truoc khi so khop: giai ma toi da 3 lop
 *            URL-encode, HTML entity, bo null byte, gom khoang trang. Nho vay bat duoc
 *            ..%252f..%252fetc/passwd va javas&#99;ript: ma ban cu bo lot
 *          - Bo comment SQL bang KHOANG TRANG (khong xoa trang) de bat UNION/**\/SELECT
 *            va /*!50000UNION*\/ — xoa trang bien thanh UNIONSELECT lam \b khong con khop
 *          - Viet lai 4 bo pattern theo nguyen tac "can dau hieu cu phap": khong bat tu
 *            khoa SQL / ten ham dung mot minh. Truoc day "select san pham from shop",
 *            "update thong tin set nay", "drop table ghe qua", "chi phi 1=1 trieu",
 *            bai viet nhac eval() hay base64_decode() deu bi chan + KHOA IP 24h
 *          - Bo pattern chan moi URL .php co query (redirect_to, link trong binh luan)
 *          - Them lop tan cong ban cu khong co: PHP object injection, SSTI ({{7*7}}),
 *            XXE, Log4Shell/JNDI, prototype pollution, .env / .git/config / id_rsa,
 *            php://filter, phar://, time-based blind (pg_sleep, waitfor delay).
 *            XSS event handler nay bat MOI on*= thay vi 9 cai hard-code
 *          - Sua 3 loi trong ham quet: (1) chi doc $_POST cap 1 nen payload trong
 *            data[user][name] lot hoan toan — nay de quy toi 6 tang, quet ca ten field;
 *            (2) gop moi field thanh 1 chuoi roi moi match → pattern khop cheo giua hai
 *            field vo hai — nay quet tung chuoi rieng; (3) khong quet cookie va header —
 *            nay co (Log4Shell va SQLi qua X-Forwarded-For rat pho bien)
 *          - Ba lop mien tru chong chan nham: bot hop le da xac minh, user co quyen
 *            edit_posts, va cac field noi dung dai (post_content, comment, custom_css)
 *          - Hieu nang 0.07ms/request. Da test ReDoS voi 8 chuoi co tinh gay backtracking
 *            (../ x2000, %25 x2000): cham nhat 4ms. Bo qua chuoi > 8KB
 *          TOI UU DATABASE SCAN — ban cu bao nham 88% noi dung DB hop le (14/16 mau),
 *          admin se mac dinh bo qua canh bao, che mat canh bao that. Ban moi 0%.
 *          - db_url_hijack bao MOI URL dang IP → site chay LAN, localhost, VPS chua tro
 *            domain deu bi bao. Nay chi bao IP PUBLIC va KHAC host site
 *          - db_script_src: whitelist mo rong 30 -> 90 domain, them nhom pho bien VN
 *            (Zalo, MoMo, VNPay, Haravan, Sapo, KiotViet, GHN, Subiz) va chat/analytics/
 *            payment quoc te (Shopify, Zendesk, Clarity, Intercom, Stripe...). Chuyen tu
 *            regex sang PARSE URL that, so khop theo nhan domain nen tiktok.com.evil.net
 *            khong the gia mao. Them filter adsdefender_db_script_whitelist cho CDN rieng
 *          - db_iframe_hidden: width=0 la binh thuong voi embed lazy-load (YouTube, Maps).
 *            Nay chi bao khi iframe VUA an VUA tro domain ngoai whitelist
 *          - db_obj_injection: ban cu bat moi O:...{...eval|system nen dinh WooCommerce
 *            (field ten "system") va ACF (field ten "eval"). Nay unserialize that voi
 *            allowed_classes=false (an toan, khong chay __wakeup) roi duyet cay, chi bao
 *            khi ten ham nguy hiem nam o KHOA mang nghia callback (cb, callback, handler)
 *          - db_rogue_admin: bo protonmail/yandex.ru (admin that hay dung), thu ve email
 *            dung-mot-lan va CHI ap dung cho admin tao trong 90 ngay
 *          - db_pharma_links yeu cau link BI AN (bai viet ve casino khong con dinh);
 *            db_eval_b64 yeu cau chuoi base64 >= 40 ky tu; db_php_tag yeu cau kem ham nguy hiem
 *          - Them 4 lop phat hien moi: eval() nhan superglobal, JS redirect domain ngoai,
 *            cloaking theo User-Agent bot, khoi van ban giau ngoai man hinh
 *          HIEU NANG DATABASE SCAN:
 *          - Sua N+1 query: vong lap users chay 2 query/user x 200 user = 400 QUERY.
 *            Nay 1 query JOIN lay dung admin + 1 query theo lo cho toan bo usermeta
 *          - Do phu options: truoc chi quet 17 TEN CO DINH nen bo lot hoan toan option
 *            cua plugin bi hijack. Nay quet MOI autoload option (malware phai autoload
 *            moi chay duoc tren frontend), bo qua transient/cache
 *          - Do phu posts: truoc lay 500 bai MOI NHAT bat ke noi dung nen malware nhet
 *            vao bai cu it ai xem thi lot. Nay loc so bo bang SQL roi quet TOAN BO bai
 *            kha nghi theo lo 200, dung keyset pagination (ID > last_id)
 *          - Bo qua gia tri > 256KB (anh base64, JSON page builder), gioi han de quy,
 *            guard chong vong lap vo han. Ket qua sap xep critical len dau
 *          - UI hien thong ke chi tiet theo tung bang thay vi chi so posts
 *  2.5.120 Sua loi CHAN NHAM BOT HOP LE — anh huong truc tiep SEO va Google Ads:
 *          - Danh sach bad UA dang chan Baiduspider, YandexBot (search engine that) va
 *            AhrefsBot/SemrushBot/MJ12bot/DotBot/BLEXBot/PetalBot/DataForSeoBot. Chan bang
 *            403 + ghi lockout lam mat index va phinh bang lockout. Da go search engine
 *            hoan toan; nhom bot SEO chuyen sang tuy chon rieng "Chan bot SEO" (mac dinh TAT,
 *            khi bat thi tra 403 nhung KHONG khoa IP)
 *          - Go cac pattern chan nham hang loat: 'curl/7.1'-'curl/7.9' la prefix match nen
 *            dang chan gan nhu toan bo curl 7.x (ke ca curl/7.81 mac dinh Ubuntu 22.04);
 *            'python-requests/2.1' chan ca 2.10-2.19; 'LWP' 3 ky tu dinh bua; 'Java/' chan
 *            nhieu webhook hop le. Don trung lap (masscan 2 lan, zmeu/ZmEu, dirb)
 *          - UA rong truoc day bi 403 + khoa IP o MOI request (cron hosting, uptime monitor
 *            deu dinh). Nay chi chan khi nham vao endpoint nhay cam (wp-login, xmlrpc,
 *            wp-admin, wp-config, .env, /wp-json/wp/v2/users)
 *          GOOGLE ADS BOT KHONG QUET DUOC TRANG DICH — nguyen nhan la Rate Limiting:
 *          - AdsBot-Google crawl landing page rat nhanh de cham diem chat luong, de vuot
 *            nguong 300 req/60s. Vuot la bi khoa IP + tra 429 → bot bao "trang dich khong
 *            truy cap duoc" → quang cao bi tu choi. 404-detector cung khoa khi bot thu vai URL
 *          - Them lop xac minh bot moi adsdefender_is_verified_bot(): KHONG tin UA suong ma
 *            dung rDNS + forward-confirm dung cach Google khuyen nghi. Phu AdsBot-Google,
 *            Mediapartners-Google, Googlebot, Google-InspectionTool, Bingbot, AdIdxBot,
 *            YandexBot, Baiduspider, DuckDuckBot, Applebot. Cache 24h, ho tro IPv4/IPv6
 *          - Noi vao rate-limit, 404-detector, honeypot, va chot chan cuoi trong
 *            adsdefender_upsert_lockout() — khong bao gio khoa bot da xac minh
 *          Mo rong danh sach bad UA: 22 -> 97 pattern. Them scanner hien dai (invicti,
 *          arachni, wapiti, whatweb, feroxbuster, dirsearch, nuclei, zaproxy, crackmapexec),
 *          payload Log4Shell nhet trong UA, cong cu brute-force, site ripper, va AI scraper
 *          phot lo robots.txt (bytespider, imagesiftbot, omgili). Tat ca pattern deu duoc
 *          test de khong dinh phan mem hop le cung ten (Canvas LMS, log4js, httpx, Hydra...)
 *  2.5.119 Sua 2 signature bao dong gia (bat oan plugin hop phap):
 *          - hidden_spam_div bat MOI <div style="display:none"> nen wpforms, litespeed-cache,
 *            freemius debug templates deu bi cham high. Div an la chuyen binh thuong (modal,
 *            template cho render). Nay chi bao khi ben trong div an co THE LINK RA NGOAI —
 *            dung dac trung SEO injection (giau backlink cho bot doc)
 *          - eval_var ha tu 'high' xuong 'medium' va bo qua ten bien ro nghia an toan
 *            ($safe_code, $code, $template...). Backdoor that gan nhu luon kem base64/gzinflate/
 *            $_POST va da co signature rieng bat; eval($var) mot minh khong du de ket luan
 *          Them TU DONG TOI UU wp-config.php (nut moi trong trang Malware Scanner):
 *          - Dat WP_DEBUG_DISPLAY=false, WP_DEBUG_LOG=true, DISALLOW_FILE_EDIT=true,
 *            WP_AUTO_UPDATE_CORE='minor'; don ky tu thua sau the dong PHP
 *          - An toan: xem truoc thay doi va hoi xac nhan, sao luu truoc khi ghi, kiem tra cu
 *            phap ban moi TRUOC khi thay the, doc lai kiem tra lan nua, hong thi khoi phuc ngay
 *          - Khong bao gio tu dong doi salt keys (se dang xuat toan bo nguoi dung)
 *  2.5.118 Malware Scanner: sua loi "Loi ket noi khi quet Plugins".
 *          - RecursiveIteratorIterator nem UnexpectedValueException tu getChildren() GIUA vong
 *            lap khi gap thu muc khong doc duoc (hay gap tren shared hosting o wp-content/plugins).
 *            Bat o ngoai foreach nen mat ca nhanh con lai, hoac thoat ra thanh trang HTML fatal.
 *            Nay dung RecursiveCallbackFilterIterator loc truoc khi di xuong, va catch Throwable
 *            thay vi Exception de giu lai phan da gom duoc
 *          - $skip_dirs (node_modules, cache, backup...) truoc day dung rewind() de bo qua nhung
 *            rewind() quay lai dau thu muc chu khong bo qua — cac thu muc nay chua bao gio duoc
 *            loai tru, lam quet cham va ton bo nho
 *          Them kiem tra CAU HINH .htaccess & wp-config.php (batch moi "config-audit"):
 *          - wp-config: salt mac dinh, WP_DEBUG hien thi loi, ky tu sau ?>, quyen file qua rong,
 *            thieu DISALLOW_FILE_EDIT, tien to bang wp_
 *          - .htaccess: auto_prepend_file (backdoor), redirect ra ten mien ngoai, AddHandler php,
 *            Options +Indexes, chua chan truy cap wp-config.php
 *          - Bo qua dong comment, im lang tren Nginx, khong bao voi .htaccess mac dinh cua WP
 *  2.5.117 Sua loi Fatal khi cai chung voi plugin dung Freemius SDK (vd File Manager Advanced).
 *          Freemius goi update_option() ngay khi plugin dang nap, action 'updated_option' ban ra
 *          luc pluggable functions cua WP chua duoc dinh nghia — callback admin-log goi
 *          is_user_logged_in() gay Fatal trang wp-admin. Them guard function_exists() cho
 *          is_user_logged_in() va wp_get_current_user().
 *          Security Headers: bo qua khi headers da duoc gui truoc (plugin khac in output som,
 *          BOM, khoang trang) thay vi de PHP sinh warning tren trang khach. Ghi lai file/dong
 *          gay ra vao option adsdefender_headers_sent_by de chan doan
 *  2.5.116 File Scanner: don ket qua cu khong con dung. Truoc day quet lai chi upsert va xoa
 *          record cua file da bi xoa — canh bao cu nam lai vinh vien du signature da sua hay
 *          file da duoc va. Nay moi lan quet se xoa cac cap file|signature khong con khop
 *          (giu nguyen muc da danh dau 'ignored'). Them nut "Kiem tra lai" chay signature
 *          tren cac file da bao ma khong can quet lai toan bo
 *  2.5.115 File Scanner: sua 2 bao dong gia.
 *          - mail_spam thieu neo dau tu nen "sanitize_email($_POST[...])" bi bat vi chu "mail"
 *            nam trong "sanitize_email". Them (?<![\w$>-]) — wp_mail/is_email/->mail() deu sach
 *          - php_in_upload bat moi <?php nen index.php "Silence is golden" cua WordPress,
 *            WPForms, iThemes Security trong uploads/ bi cham critical. Bo qua khi noi dung
 *            dung la stub vo hai (kiem tra theo NOI DUNG, malware nup ten index.php van bi bat)
 *  2.5.114 Cap nhat header Update URI va cac tham chieu con ghi track.saigon.pro sang GitHub
 *  2.5.113 Nguon cap nhat chuyen tu track.saigon.pro sang GitHub: adsdefender-update.json nam
 *          ngay trong repo, doc qua raw.githubusercontent. Khong con phai upload JSON thu cong
 *          moi lan phat hanh. Them release.sh lam tron chuoi bump -> json -> tag -> zip -> release
 *  2.5.112 UTM & Attribution: them thong ke trong ngay — bo loc "Hom nay"/"Hom qua", 4 the so
 *          lieu (sessions, chuyen doi, ty le, gio cao diem) kem so sanh voi hom qua, bieu do
 *          phan bo theo gio 0-23, va bieu do xu huong theo ngay. Moc thoi gian dung
 *          current_time() theo mui gio site thay vi gio UTC server
 *  2.5.111 DB Scanner: snippet hien du URL. Signature ket thuc bang lookahead (?!...) khong tieu
 *          thu ky tu nen $m[0] cut ngay sau "https://" — admin khong biet domain nao bi bao.
 *          Them adsdefender_db_snippet() lay them ngu canh sau vi tri khop (4 cho).
 *          Badge menu: bo class 'awaiting-mod' (cua comment cho duyet) sang 'update-plugins',
 *          rut gon so >999 thanh "999+", chuyen badge log tu menu cha/Marketing sang Bao ve,
 *          va bo badge update bi lap 2 lan (menu.php + update.php priority 999)
 *  2.5.110 Them filter upgrader_source_selection: doi ten thu muc khi giai nen ZIP tu GitHub
 *          (adsdefender-wp-plugin-x.y.z -> adsdefender). Khong co no, moi lan cap nhat tu
 *          GitHub release se tao plugin moi thay vi ghi de ban cu
 *  2.5.109 DB Scanner: them domain nhung hop le vao whitelist db_script_src (TikTok, YouTube,
 *          Facebook, Twitter/X, Vimeo, Instagram, GTM, Google Ads...) — truoc day embed.js cua
 *          TikTok trong bai viet bi bao "External script tu domain la" severity high.
 *          Kem theo va lo hong co san: whitelist thieu neo cuoi nen tiktok.com.evil.net lot luoi
 *  2.5.108 Fix cau truc HTML admin: trang Dashboard thua mot the </div> (dong 461) lam dong som
 *          #wpwrap, day #wpfooter ra ngoai container — footer admin hien thi sai vi tri
 *  2.5.107 Bo doan JS an notice "Cam on ban da khoi tao voi WordPress" tren trang AdsDefender —
 *          chi khop tieng Viet, dung NodeList.forEach, va co the an nham notice plugin khac
 *  2.5.106 Dem lai click link tel:/zalo/m.me/wa.me/t.me trong bai viet + widget + block, nhung
 *          chen onclick tu PHP (the_content/widget_text/render_block) thay vi listener JS —
 *          HTML tra ve da hoan chinh, khong co gi dung giua cu bam va hanh vi mac dinh.
 *          Bo qua link da co onclick (nut Contact Bar) de khong dem trung
 *  2.5.105 Bo hoan toan JS bat click toan site (utm.php + contact-bar.php). Nut tel: tro ve HTML
 *          thuan — khong JS nao chan duoc dieu huong nua. Conversion Contact Bar chuyen sang
 *          onclick="adcbTrack(i)" ngay tren the <a>, chay sau khi trinh duyet da dieu huong.
 *          Danh doi: khong con dem cu bam vao link tel:/zalo: nam ngoai Contact Bar
 *  2.5.104 Fix that: nut tel: van khong quay so tren mobile — con listener thu 2 o utm.php dung
 *          e.target.closest() (v2.5.103 chi va contact-bar.php). Doi sang tu leo cay DOM + try/catch;
 *          thay startsWith/includes bang indexOf va NodeList.forEach bang vong for (WebView cu)
 *  2.5.103 Contact Bar fix: nut goi khong hoat dong tren mobile (SVGElement khong co .closest()
 *          gay TypeError o capture phase, chan luon tel:); them hide_on_pages (ID/slug/path/wildcard);
 *          them current_user_can, is_array cho $_POST (fatal PHP 8); fix nut nhan ban + option phinh;
 *          sg_event nay co tac dung; esc_url_raw cho custom/messenger/maps; body padding tranh che
 *          noi dung; icon Zalo dung logo that
 *  2.5.102 Security fix: CSV/formula injection o Export Leads, hash_equals() cho Hide Login token,
 *          escape HTML o File Monitor scan result (XSS phong ngua)
 *  2.5.101 Bo tinh nang chan IP qua .htaccess — block IP chi thuc hien qua PHP
 *  2.5.100 Fix REST token toggle button (onclick quote conflict → addEventListener)
 *  2.5.99  Mask all secret key inputs (Matomo token, CAPTCHA secret, AbuseIPDB key) - type=password
 *  2.5.98  Security: fix check_ajax_referer field name, validate api_url scheme (SSRF), mask REST token
 *  2.5.97  Fix: them cs_enabled/cs_rate_limit/cs_blocklist/fm_enabled/scan_enabled vao sanitize_settings
 *  2.5.96  Comment Security: bo duplicate/link/keyword tu nhap (WP co san); chi giu rate limit +
 *          spam blocklist tu dong (splorp/wordpress-comment-blacklist 40k+ terms, cache 7 ngay)
 *  2.5.95  Database Scanner: quet posts/options/usermeta tim malware inject (11 DB signatures);
 *          Comment Security: honeypot, time check, link spam limit, rate limit, keyword blacklist
 *  2.5.94  Malware Scanner: +20 signatures (create_function chain, eval_var, decode_chain, C2 callback,
 *          WP hook inject, spam div, ob_start+b64, daemonize, mail relay, wasm miner, fake image...)
 *  2.5.93  Fix Permissions logic: .htaccess=0644 la dung (server can doc), hien lenh SSH khi PHP khong chmod duoc
 *  2.5.92  File Permissions: nut Fix tu dong chmod toi uu + Rollback 1-click khoi phuc quyen cu
 *  2.5.91  Fix File Permissions logic: 0600 OK (chi owner), 0644 canh bao (group/other doc duoc DB pass)
 *  2.5.90  File Permissions Check: kiem tra chmod wp-config/htaccess/dirs, highlight sai, huong dan fix
 *  2.5.89  Fix Scanner: tu dong reload trang sau khi scan xong de hien ket qua tu DB (khong mat sau reload)
 *  2.5.88  Sucuri SiteCheck integration: external scan blacklist+malware+SSL rating, cache 12h
 *  2.5.87  Fix chr() false positive: chi bao khi chr() chain duoc dung de goi ham (WooCommerce safe)
 *  2.5.86  Fix Scanner false positive: php_only/skip_core/uploads_only flags, bo qua .min.js
 *  2.5.85  Fix Scanner + FileMonitor: sua ten ham adsdefender_log_security_event -> adsdefender_sec_log
 *  2.5.84  Fix Scanner: bat Throwable exception + error_handler trong AJAX, hien thi loi chi tiet
 *  2.5.83  Fix Scanner timeout: wp-includes depth=1, phan trang 150 files/request, offset pagination
 *  2.5.82  Fix Scanner timeout: scan theo 6 batch nho (config/core/admin/themes/plugins/uploads), progress bar
 *  2.5.81  Fix Malware Scanner: catch RecursiveIterator exception (symlink/permission), wrap preg_match
 *  2.5.80  Fix Malware Scanner: skip AdsDefender plugin dir de tranh tu detect signature
 *  2.5.79  Malware Scanner: quet 24 signature (eval/base64, webshell, redirect, miner...), tab Site Scanner
 *  2.5.78  File Change Detection: monitor SHA-256 hash WP core + plugins + themes, alert khi co thay doi
 *  2.5.77  Fix: An notice "Cam on ban da khoi tao voi WordPress" (WP 7.0) tren trang AdsDefender
 *  2.5.76  Fix: Admin Log setting bi thieu trong tab Bao ve sau khi refactor tabs
 *  2.5.75  Settings: refactor tab UI — 5 tabs (Ket noi / Bao ve / Server & WP / Cross-site / Cong cu)
 *  2.5.74  Blocked page: redesign light mode, bo dark scary, them promo saigon.pro duoi footer
 *  2.5.73  Fix SystemTweaks: plugins/themes PHP block dung parse_url() thay str_replace(home_url()) — fix URL day du trong htaccess gay block WP admin
 *  2.5.72  TrackSG noscript: them dimension1=Jsdisable vao URL de track user tat JS
 *  2.5.71  Preview: them TrackSG noscript vao Preview script dang inject
 *  2.5.70  Fix TrackSG noscript: fallback sang wp_footer neu theme khong support wp_body_open
 *  2.5.69  Tich hop nhanh: them nut "Quet tu dong" tu dong detect GTM ID, Meta Pixel ID tu HTML trang chu;
 *          TrackSG ID tu dong lay tu site_id trong Settings; AJAX handler adsdefender_detect_tracking
 *  2.5.68  Fix .htaccess thu tu block: AdsDefender-SystemTweaks luon chen SAU AdsDefender (IP block);
 *          auto-fix khi rebuild (settings save / deactivate+activate)
 *  2.5.67  System Tweaks refactor: gom 4 RewriteRule block thanh 1 (iThemes pattern), them <files> bao ve .htaccess/readme/wp-config.php;
 *          Fix insertion order: SystemTweaks luon chen SAU "# END AdsDefender" (IP block), khong prepend len dau file
 *  2.5.66  Htaccess protection: exact-line regex, LSCACHE/WP-Defender/length-ratio validation, preg_replace limit=1,
 *          post-write verify + rollback; garbage "-SystemTweaks" auto-sanitize; system-tweaks.php tương tự
 *  2.5.65  Admin Activity Log: ghi login/logout, settings change, post/media/user actions (includes/admin-log.php);
 *          Tab He thong → Admin Log: filter theo loai/user/ngay, pagination, IP Reputation;
 *          Settings: toggle bat/tat Admin Log
 *  2.5.58  Security Event Log tập trung (adsdefender_security_log): ghi brute_force/firewall/rate_limit/flood_404;
 *          Dashboard: widget security stats 7 ngày + top IPs tấn công;
 *          Tab Bảo vệ → Security Events: filter theo loại/IP, pagination 50 dòng;
 *          IP Reputation: tra cứu AbuseIPDB inline (modal) từ mọi nơi có IP trong admin;
 *          Settings: thêm AbuseIPDB API Key field
 *  2.5.57  Fix: lock_url + redirect_url dùng esc_url_raw → mất giá trị khi nhập path tương đối (/adsdefender-blocked.html);
 *          input type="text" thay vì type="url" để browser không reject path tương đối
 *  2.5.56  WordPress background auto-update: filter auto_update_plugin + toggle UI trong Settings
 *  2.5.55  Cải tiến auto-update: thêm requires/tested/php fields, no_update transient, badge menu,
 *          redirect helper, fallback install khi upgrade fail, fix file.php missing
 *  2.5.54  Fix: adsdefender_detect_server_type() undefined → activation crash (Fatal Error);
 *          Fix: adsdefender_hl_flush_rules() undefined → activation crash (Fatal Error)
 *  2.5.52  Fix: array_column($manual) khi unserialize trả false (encoding bug note tiếng Việt);
 *          Fix: whitelist không block firewall/UA check → thêm whitelist check vào đầu mỗi check function;
 *          Fix: parse_whitelist() strip comments (#...) trước khi match IP;
 *          Fix: auto-ban note dùng ASCII thay vì tiếng Việt để tránh serialize encoding lỗi
 *  2.5.51  System Tweaks (.htaccess: chặn readme/install/wp-includes PHP/git, tắt directory listing,
 *          block PHP trong uploads/plugins/themes); WP Tweaks (DISALLOW_FILE_EDIT, xóa WP version,
 *          chặn author enum, block REST /users); Security Headers (HSTS, CSP, X-Cross-Domain, COOP)
 *  2.5.50  Thêm Cloudflare Turnstile vào CAPTCHA module; provider = google_v2|google_v3|turnstile;
 *          verify endpoint: challenges.cloudflare.com/turnstile/v0/siteverify; bỏ qua timeout-or-duplicate
 *  2.5.49  Thêm reCAPTCHA v2/v3 (includes/recaptcha.php): login, lostpassword, register, comment;
 *          Fix wp-admin chưa login → redirect /adsdefender-blocked.html thay vì 404 HTML thô
 *  2.5.48  Fix Brute Force: check lockout TRƯỚC khi block_admin để record được tích lũy đúng;
 *          sửa thứ tự authenticate hook: check → admin_block+record → wp_login_failed record
 *  2.5.47  Fix Hide Login: /wp-admin/ chưa login → 404 (không redirect sang slug, không lộ login URL)
 *  2.5.46  Fix Hide Login: khai báo global $user_login, $error, ... trước require wp-login.php
 *          để tránh PHP Warning "Undefined variable" (require trong function scope ≠ global scope)
 *  2.5.45  Fix Hide Login: không redirect (lộ slug), serve wp-login.php trực tiếp bằng
 *          fake $_SERVER + require — slug không bao giờ xuất hiện trong network tab;
 *          filter site_url để links trong HTML cũng trỏ về slug thay vì wp-login.php
 *  2.5.44  Fix double token trong Location header; flag tắt site_url filter khi build redirect URL
 *  2.5.43  Viết lại hoàn toàn Hide Login theo cơ chế Solid Security Pro: setup_theme hook,
 *          token=slug, cookie 1h, filter site_url để tự động thêm token vào links,
 *          remove wp_redirect_admin_locations, xử lý đúng logout/resetpass/lostpassword
 *  2.5.42  Fix Hide Login: cho phép wp-login.php khi có WP auth cookie hoặc reauth từ wp-admin,
 *          fix lostpassword/resetpass action, fix vào wp-admin sau khi đã login
 *  2.5.41  Fix Hide Login redirect loop: bỏ filter login_url trong wp-admin context,
 *          bỏ block /wp-admin/ (WP tự xử lý auth), chỉ block /wp-login.php GET trực tiếp
 *  2.5.40  Fix Hide Login: dùng plugins_loaded + header redirect thay vì require wp-login.php
 *          trực tiếp — tránh undefined variable $user_login/$error trong wp-login.php
 *  2.5.39  Bảo mật toàn diện: Brute Force (login attempt limit per IP/user), Request Firewall
 *          (SQLi/XSS/path traversal/shellcode/bad UA), Hide Login URL + XML-RPC disable,
 *          Rate Limiting (sliding window), Honeypot (login/comment/register), 404 Flood Detection
 *  2.5.38  Fix đọc cookie visitor: PHP tự đổi _pk_id.12.xxx → _pk_id_12_xxx (dấu chấm → underscore)
 *  2.5.37  Xóa toàn bộ IP cũ khỏi .htaccess khi migrate — thiết kế mới lockout tạm chỉ PHP,
 *          .htaccess chỉ chứa permanent ban; sync không còn ghi IPs Matomo vào .htaccess
 *  2.5.36  Auto-upgrade migration khi update (không cần deactivate/activate): tạo bảng lockouts,
 *          dọn log cũ, rebuild .htaccess sạch, reset temp whitelist, cấu hình lại cache plugins
 *  2.5.35  Lockout tạm thời + ban vĩnh viễn (học từ Solid Security Pro): bảng adsdefender_lockouts,
 *          auto-whitelist admin IP 24h, skip WP-CLI/Cron/REST/XMLRPC, DONOTCACHEPAGE cho trang blocked
 *  2.5.34  Dashboard: widget cache status + nút "Tự động sửa" Do Not Cache Cookies
 *  2.5.33  Tự động cấu hình 6 cache plugin (LiteSpeed, WP Rocket, W3TC, WP Super Cache, Cache Enabler, Breeze)
 *  2.5.32  Fix IPv6 đơn trong .htaccess (Apache 2.4 + LiteSpeed hỗ trợ, chỉ bỏ IPv6 CIDR);
 *          Cloudflare: xác minh REMOTE_ADDR thuộc dải CF trước khi tin CF-Connecting-IP
 *  2.5.29  Validate IP trước khi ghi .htaccess; case 403 PHP trả HTTP 403 thật; comment CF firewall
 *  2.5.28  Fix visitor sync bool; get_real_ip() default REMOTE_ADDR, proxy header chỉ tin khi bật
 *  2.5.27  Fix 7 lỗi: REST token, sync bool, IPv6 bits, LOCK_EX, log cleanup 1%, CF spoofing, Apache 2.4
 *  2.5.26  Fix htaccess không ghi mỗi page load
 *  2.5.25  Tắt tự động cập nhật .htaccess khi vào setting
 *  2.5.24  Fix 6 lỗi security
 *  2.5.23  Fix redirect loop — file HTML tĩnh
 *  2.5.22  Fix redirect loop whitelist
 *  2.5.21  Viết lại .htaccess format mod_litespeed
 *  2.5.20  Tự động tạo trang /adsdefender-blocked
 *  2.5.19  Fix ErrorDocument 403
 */

if (!defined('ABSPATH')) exit;

define('ADSDEFENDER_VERSION',        '2.5.121');
define('ADSDEFENDER_DIR',            plugin_dir_path(__FILE__));
define('ADSDEFENDER_OPTION_IPS',     'adsdefender_blocked_ips');
define('ADSDEFENDER_OPTION_UPDATED', 'adsdefender_last_sync');
define('ADSDEFENDER_OPTION_MANUAL',  'adsdefender_manual_ips');
define('ADSDEFENDER_OPTION_SCRIPTS', 'adsdefender_scripts');
define('ADSDEFENDER_OPTION_CONTACT', 'adsdefender_contact_bar');
define('ADSDEFENDER_OPTION_POPUP',   'adsdefender_popups');
define('ADSDEFENDER_UTM_TABLE',      'adsdefender_utm_log');
define('ADSDEFENDER_LEAD_TABLE',     'adsdefender_leads');
define('ADSDEFENDER_CRON_HOOK',      'adsdefender_sync_cron');
define('ADSDEFENDER_LOG_TABLE',      'adsdefender_block_log');
define('ADSDEFENDER_OPTION_VISITORS','adsdefender_blocked_visitors');
define('ADSDEFENDER_LOCKOUT_TABLE',  'adsdefender_lockouts');
define('ADSDEFENDER_UPDATE_URL',     'https://raw.githubusercontent.com/rubidev/adsdefender-wp-plugin/main/adsdefender-update.json');
define('ADSDEFENDER_PLUGIN_SLUG',    plugin_basename(__FILE__));

require_once ADSDEFENDER_DIR . 'includes/core.php';
require_once ADSDEFENDER_DIR . 'includes/security-log.php';
require_once ADSDEFENDER_DIR . 'includes/admin-log.php';
require_once ADSDEFENDER_DIR . 'includes/brute-force.php';
require_once ADSDEFENDER_DIR . 'includes/firewall.php';
require_once ADSDEFENDER_DIR . 'includes/hide-login.php';
require_once ADSDEFENDER_DIR . 'includes/rate-limit.php';
require_once ADSDEFENDER_DIR . 'includes/honeypot.php';
require_once ADSDEFENDER_DIR . 'includes/404-detector.php';
require_once ADSDEFENDER_DIR . 'includes/system-tweaks.php';
require_once ADSDEFENDER_DIR . 'includes/wp-tweaks.php';
require_once ADSDEFENDER_DIR . 'includes/recaptcha.php';
require_once ADSDEFENDER_DIR . 'includes/scripts.php';
require_once ADSDEFENDER_DIR . 'includes/file-monitor.php';
require_once ADSDEFENDER_DIR . 'includes/malware-scanner.php';
require_once ADSDEFENDER_DIR . 'includes/config-audit.php';
require_once ADSDEFENDER_DIR . 'includes/comment-security.php';
require_once ADSDEFENDER_DIR . 'includes/update.php';
require_once ADSDEFENDER_DIR . 'includes/utm.php';
require_once ADSDEFENDER_DIR . 'includes/contact-bar.php';
require_once ADSDEFENDER_DIR . 'includes/popup.php';
require_once ADSDEFENDER_DIR . 'admin/menu.php';

// ─── Activation / Deactivation ────────────────────────────────────────────────

register_activation_hook(__FILE__, function () {
    adsdefender_create_log_table();
    adsdefender_create_utm_table();
    adsdefender_create_lead_table();
    adsdefender_create_lockouts_table();
    adsdefender_create_bf_table();
    adsdefender_create_sec_log_table();
    adsdefender_create_admin_log_table();
    adsdefender_fm_create_table();
    adsdefender_scan_create_table();
    if (!wp_next_scheduled(ADSDEFENDER_CRON_HOOK)) {
        wp_schedule_event(time(), 'adsdefender_30min', ADSDEFENDER_CRON_HOOK);
    }
    adsdefender_configure_litespeed_cache();
    adsdefender_hl_flush_rules();
    adsdefender_st_update_htaccess();
    adsdefender_sync_now();
});

register_deactivation_hook(__FILE__, function () {
    wp_clear_scheduled_hook(ADSDEFENDER_CRON_HOOK);
    adsdefender_st_remove_htaccess();
});
