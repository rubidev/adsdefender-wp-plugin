# AdsDefender — Changelog

```text
 2.5.131 Live Traffic: Chrome Prefetch Proxy va request tai truoc
         - "Chrome Privacy Preserving Prefetch Proxy" (Google kiem tra
           /.well-known/traffic-advice truoc khi tai truoc trang) ghi chu ro
           "chrome-prefetch-proxy" thay vi "tool:chrome" de bi hieu la Chrome that.
           Nhan cong cu giu ca cum nhieu tu thay vi chi tu dau
         - Request tai truoc (header Sec-Purpose: prefetch — WordPress 6.8 tai truoc khi
           re chuot vao link, Chrome tai truoc ket qua Google qua proxy an IP): van la
           nguoi that, them nhan "tai truoc". Qua proxy thi IP la cua Google, nhieu nguoi
           dung chung → bo phat hien click ao bo qua, khong khoa nham IP Google
         - Migration: doi ghi chu cu "tool:chrome" cua Prefetch Proxy

 2.5.130 HIDE LOGIN VIET LAI — kiem thu 48 truong hop tren localhost
         - FIX /wp-admin/ con vao duoc: trinh duyet tung mo URL an (cookie 1 gio) mo
           /wp-admin/ thi WordPress chuyen huong ve URL an. Nay chua dang nhap → 404
           o MOI trang /wp-admin/, ke ca co cookie
         - FIX lach bang chu hoa / 2 dau gach: /WP-ADMIN/ chuyen huong ve URL an cho
           BAT KY AI. Nay nhan dien theo hang WP_ADMIN va $pagenow cua WordPress, khong
           so chuoi URL
         - FIX lo slug: /wp-signup.php, /wp-register.php, ?action=lostpassword (GET /
           POST), ?action=rp khong key, logout nonce gia → tat ca 404. Them luoi an
           toan: moi chuyen huong toi URL an cho nguoi la (request khong phai URL an) → 404
         - FIX /wp-admin/admin-post.php bi 404 lam hong form ngoai website (nopriv)
         - Link dat lai mat khau cu (/wp-login.php) chi cho qua khi key con hop le
         - Da dang nhap mo URL an / wp-login.php → vao thang trang quan tri
         - Da kiem: dang nhap that, dang xuat, sai mat khau, quen mat khau, dat lai
           mat khau, dang nhap lai khi het phien, bai co mat khau, admin-ajax, ket hop
           Brute Force + chi dang nhap tu VN, tat / bat lai Hide Login
         FIX Live Traffic nhan dien nham bot la "Nguoi that"
         - WhatsApp/2, facebookexternalhit (xem truoc link khi chia se) va moi UA khong
           co dang trinh duyet (trinh duyet that luon "Mozilla/5.0 (" + AppleWebKit /
           Gecko / Trident) → Bot. Them ten: Telegram, Viber, Skype, Slack, Discord,
           Lighthouse, PageSpeed, GTmetrix, Pingdom, UptimeRobot, Selenium, Puppeteer,
           Playwright, GoogleOther, Dart, okhttp, Postman, WordPress loopback…
         - Yeu cau robots.txt, ads.txt, sitemap*.xml, /.well-known/ → Bot
         - Trinh duyet trong app Facebook / Zalo / TikTok van la nguoi that (da kiem 37 UA)
         - Click ao: dich vu xem truoc link tai URL quang cao khi ai do chia se link —
           khong tinh la click ao (WhatsApp tai tu chinh dien thoai nguoi gui → khoa nham)
         - Migration: doi cac dong cu ghi nham "Nguoi that" thanh Bot

 2.5.129 Hide Login: /wp-login.php LUON 404 voi nguoi chua dang nhap
         - Truoc: trinh duyet tung mo URL an trong 1 gio (cookie) van mo duoc form o
           /wp-login.php → chinh admin thu thay form, tuong Hide Login khong chay
         - Moi link / form dang nhap cua WordPress da tro ve URL an nen khong luong nao
           can mo thang /wp-login.php. Van cho qua: postpass (trang co mat khau), link
           dat lai mat khau, logout co nonce
         - Da dang nhap ma mo /wp-login.php → chuyen thang vao trang quan tri

 2.5.128 FIX danh sach "Loai tru IP trong Google Ads" loc sai
         - Truoc: lay MOI lockout 30 ngay (firewall, quet 404, do mat khau, rate limit,
           honeypot) + toan bo danh sach chan thu cong / ban tu dong → toan bot quet web,
           khong bam quang cao, chi ton cho trong gioi han 500 IP / chien dich.
           Lai THIEU danh sach IP Matomo danh dau click ao
         - Nay chi gom IP co dau hieu click quang cao: click ao phat hien tai site, IP
           Matomo danh dau, lockout nguon matomo / clickfraud 30 ngay, IP chan thu cong
           tung vao tu quang cao (Live Traffic). Hien so IP chan thu cong da bo qua
         IP QUAN TRI KHONG BAO GIO BI CHAN
         - Nho IP quan tri 30 ngay (truoc: 24 gio) ke tu lan dung cuoi, ghi ca ngay luc
           dang nhap thanh cong → admin quay lai sau vai ngay, truoc khi dang nhap (luc
           de bi chan nhat) van duoc bao ve. IP moi ghi tu go lockout / chan thu cong cu
         - Brute Force: IP quan tri / whitelist khong bi khoa — truoc day botnet do du 10
           lan username cua admin la chinh admin cung bi khoa dang nhap
         - Honeypot bo qua IP quan tri (truoc day thieu)
         - Khong cho chan tay IP quan tri (Block Log, Block thu cong ke ca dai CIDR chua
           IP quan tri, nut Chan o Live Traffic); bo IP quan tri khoi danh sach loai tru
           Google Ads
         - Tab Whitelist hien danh sach IP quan tri tu dong + han
         LIVE TRAFFIC THANH MENU RIENG (AdsDefender → 📡 Live Traffic)
         - Tach khoi tab cua Chong Click Fraud; link cu (?page=adsdefender-fraud&tab=live)
           tu chuyen huong
         - Gon hon: o cai dat (ghi log + tu phat hien click ao) thu lai thanh 1 dong tom
           tat trang thai (tu mo khi Live Traffic dang tat); 9 the so lieu thanh 1 dai
           ngang, nhan ngan (giai thich o tooltip); muc Loai tru IP Google Ads mo ta ngan
         CHI DANG NHAP TU VIET NAM (Bao mat → Dang nhap, mac dinh TAT)
         - wp-login.php, URL an, XML-RPC, Application Password chi mo cho IP Viet Nam +
           proxy Google (tru dai Google Cloud cho thue) / Cloudflare + whitelist. Tai
           khoan quan tri / bien tap dang nhap qua form khac (WooCommerce…) tu nuoc ngoai
           cung bi tu choi; khach mua hang nuoc ngoai van dang nhap binh thuong
         - Danh sach IP VN tu APNIC (~1.000 dai v4 + 965 dai v6), tai hang tuan, tra cuc
           bo bang tim kiem nhi phan (~0,2 ms / lan) — khong goi API ngoai khi dang nhap
         - Site sau Cloudflare chua bat IP that: dung CF-IPCountry, chi tin khi ket noi
           den tu IP Cloudflare (gia header tu IP khac → khong co tac dung)
         - Chong tu khoa: chua co danh sach → khong ap dung; IP admin hien tai khong duoc
           phep → tu choi bat; khan cap: define('ADSDEFENDER_NO_COUNTRY_LOCK', true)
         - Hide Login dang bat → tra 404 (khong lo trang dang nhap), khong thi 403
         FIX Hide Login de lot POST dang nhap
         - Truoc: moi POST vao wp-login.php deu duoc cho qua → botnet POST thang de do
           mat khau, Hide Login vo tac dung (Live Traffic thay POST /wp-login.php 200)
         - Nay POST dang nhap phai co cookie cua URL an (nguoi that mo form qua slug nen
           luon co), khong co → 404 + ghi Security Log. Van cho qua postpass (trang co
           mat khau), lostpassword, resetpass

 2.5.127 BOT DO MAT KHAU (botnet POST thang vao wp-login.php)
         - Live Traffic ghi nham la "Nguoi that": bot gia UA trinh duyet + gia referer
           chinh website. Nay nhan dien POST wp-login.php KHONG co cookie kiem tra cua
           WordPress (nguoi that mo form truoc nen luon co) va moi POST xmlrpc.php →
           loai "Bot", ghi chu login-bot
         - Brute Force: tuy chon moi (mac dinh BAT) "Chan bot do mat khau" — tu choi 403
           ngay, ghi Security Log + Block Log ly do login_bot. Botnet dung moi IP 1 lan
           nen gioi han theo IP khong bat duoc. KHONG khoa IP (IP dan cu / 4G dung chung)
         - Form dang nhap nhung bang wp_login_form() tu dat cookie bang JS → van dung duoc
         - Migration: dong Live Traffic cu POST wp-login.php tu IP chua tung mo trang
           dang nhap, va POST xmlrpc.php → doi thanh Bot
         LIVE TRAFFIC GON HON
         - Gop request LIEN TIEP cung IP + cung loai khach (cach <= 30 phut) thanh 1 dong
           "×N" — bot quet 1000 trang chi con 1 dong. Bam dong de xem danh sach trang.
           Luot vao tu quang cao luon mo dong moi (cac trang xem tiep gop vao no);
           chuyen doi khong gop
         - Lan tai dau doc 2000 request gan nhat roi gop phia may chu → bot khong day
           het khach that ra khoi man hinh; cap nhat tiep gop ngay tren trinh duyet
         - Dong thap hon: vi tri 1 dong (nha mang xem o tooltip), ma tham chieu chuyen
           vao tooltip IP + phan chi tiet, nut thao tac dang icon tren 1 dong

 2.5.126 FIX Live Traffic tach 1 chien dich thanh 2 dong ("24545854213" va "#24545854213")
         - Tai khoan dat utm_campaign={campaignid} ghi chien dich bang ID so tran, con
           click chi co gad_campaignid (gbraid / PMax / auto-tagging) ghi "#id" → bang
           Theo chien dich chia doi so click, IP, xem tiep cua cung mot chien dich
         - utm_campaign toan chu so nay duoc coi la ID → gom chung dang "#id"
         - Migration: doi cac dong cu ghi ID so tran sang "#id"
         VI TRI KHACH (IP2Location.io / ip-api.com)
         - Duoi moi IP trong Live Traffic / Block Log / Security Events hien quoc gia,
           tinh / thanh pho, nha mang + nhan "datacenter" (IP may chu / cloud — thuong
           la bot, VPN), "di dong" (3G/4G/5G), "proxy"
         - Mac dinh IP2Location.io (tinh / thanh o VN sat hon): khong key 1.000 luot /
           ngay, key Free 50.000 luot / thang, goi song song. Goi Free khong co
           usage_type → nhan datacenter suy tu ASN cac nha cung cap cloud lon
         - Tuy chon ip-api.com: batch 100 IP / lan, co san nhan di dong / datacenter
         - Chi tra IP dang hien thi trong trang quan tri (khong tra luc khach truy cap),
           cache 30 ngay moi IP trong bang adsdefender_geo; doi nguon → xoa cache
         - Cai dat (Chong Click Fraud → Ket noi): bat / tat, chon nguon, key
           IP2Location.io, key ip-api Pro
         LOG CHAN CO COT "LY DO"
         - Truoc day log chan chi luu IP / URL / UA — khong biet vi sao bi chan. Nay
           ghi ma ly do o moi diem chan: UA bi chan (kem ten, vd. bytespider), SQLi /
           XSS / LFI / RCE, khong UA, qua nhieu request, quet 404, honeypot, IP chan
           thu cong, danh sach click ao, visitor Matomo, dang bi khoa tam (kem nguon)
         - Migration: them cot reason; dien ly do cho log cu tu Security Log (cung IP,
           lech <= 2 giay) voi firewall / rate limit / quet 404. Log cu khong ghep
           duoc hien "—"

 2.5.125 FIX Live Traffic bao nham "gclid dang ngo — sai dinh dang" voi gbraid / wbraid that
         - gbraid / wbraid that bat dau "0AAAA" + ky tu thay doi theo tai khoan
           (0AAAAA…, 0AAAAB…, 0AAAAC…). Ban cu chi chap nhan "0AAAAA" nen click Google
           Ads that (thuong la iOS / Android chan theo doi) bi gan co do
         - Migration: go co "format" da gan nham trong bang Live Traffic
         - Co nay chi anh huong hien thi / thong ke "gclid gia", khong dung de tu chan IP

 2.5.124 FIX cPGuard VAN CACH LY includes/firewall.php ({CPG}.php.IndoXploit.005)
         - 2.5.123 an tu khoa bang lop ky tu nhung van bi bat → chu ky khong dua tren
           cac tu do. Nay chuyen TOAN BO bo luat Firewall (UA xau, bot SEO, pattern
           SQLi / XSS / traversal / RCE, URI nhay cam) ra includes/firewall-rules.dat
           dang hex. firewall.php chi con logic, khong con chuoi nao de so khop
         - Nguon luat de doc: dev/firewall-rules.json (khong dong goi vao ZIP);
           release.sh tu ma hoa lai bang dev/build-rules.py
         - Da kiem chung 143 mau tren 4 nhom pattern + danh sach UA + URI nhay cam:
           ket qua giong het ban 2.5.122
         - Loader: file module bi scanner hosting cach ly khong con lam fatal SAP CA
           SITE — bo qua file thieu, tinh nang do tam tat, hien thong bao cho admin

 2.5.123 FIX cPGuard BAO NHAM includes/firewall.php LA MA DOC ({CPG}.php.IndoXploit.005)
         - Pattern Firewall (SQLi / XSS / traversal / RCE) con chua nguyen van duong
           dan he thong, file cau hinh, stream wrapper, lenh shell — trung chu ky bo
           webshell. Nay viet dang lop ky tu 1 phan tu cho TOAN BO tu khoa nhay cam
         - Danh sach User-Agent xau va URI nhay cam: ghep chuoi thay vi viet lien
         - Da kiem chung 143 mau tan cong + van ban thuong tren ca 4 nhom pattern,
           danh sach UA va URI nhay cam: ket qua giong het ban cu
         - Neu cPGuard da cach ly firewall.php: cap nhat plugin len ban nay (khong
           khoi phuc file cu tu quarantine) va bam "Report false Positive"

 2.5.122 FIX SCANNER HOSTING BAO NHAM PLUGIN LA MA DOC (ImunifyAV / ClamAV / Maldet tren cPanel)
         - BAO MAT (ra soat toan plugin):
           · Gia mao IP qua X-Real-IP: khi bat "reverse proxy", header duoc tin tu BAT KY
             ai → goi thang vao IP goc server de ne chan / brute force / rate limit, mao
             danh IP whitelist de ne firewall, hoac gan click ao cho IP nguoi khac. Nay
             chi tin khi ket noi den tu proxy noi bo (loopback / 10.x / 172.16-31.x /
             192.168.x) hoac IP trong o moi "Proxy tin cay"
           · Token REST in cong khai tren moi trang (window.adUTM.token) lai bao ve ca
             /sync → ai cung goi duoc. Tach token dong bo bi mat rieng (tab Cong cu),
             /sync gioi han 1 lan / phut. Token cong khai chi con cho /lead, /conversion
           · Click ao: chuyen doi do trinh duyet tu bao (beacon goi / Zalo, method
             CONV-JS) khong con mien tru vinh vien — chi noi nguong +2 click; chi chuyen
             doi phia may chu (form / lead / don hang) moi mien han
           · XSS: escape du lieu ngoai trong modal uy tin IP (AbuseIPDB) va tag / ten
             site nhom (Matomo)
           · Trang Scripts: luu ma script tho doi quyen unfiltered_html (multisite)
           · Threat Intel luu du lieu dang hex thay base64 (tranh scanner hosting soi)
         - Ban quyen saigon.pro: Plugin URI / Author / Author URI = saigon.pro, dong
           Copyright o dau moi file PHP; link "saigon.pro" + "Ung ho (PayPal)" o trang
           Plugins va chan trang cac trang AdsDefender
         - Chu ky trong Malware Scanner va Firewall viet dang lop ky tu 1 phan tu
           (vd. ev[a]l) — regex giu nguyen nghia, nhung file khong con chua nguyen
           van chuoi chu ky ma scanner hosting so khop. Da kiem chung 128 pattern:
           ket qua phat hien giong het ban cu
         - Label / comment khong con viet dang loi goi ham giai ma + thuc thi
         - Chuyen changelog tu header adsdefender.php sang CHANGELOG.md (khong dong
           goi vao ZIP) — khoi changelog chua day tu khoa bao mat lam scanner nghi ngo
         - Fix Cai dat: bat buoc nhap Site ID moi luu duoc. Site ID trong bi luu thanh 0,
           form render value="0" voi min="1" nen trinh duyet chan submit. Nay de trong
           thi hien o trong. Kem fix fm_depth: server cho toi 10 nhung input max="6"
         SAP XEP LAI PLUGIN THEO MODULE
         - Menu moi: Tong quan / Chong Click Fraud / Bao mat / Marketing / Cai dat chung.
           Cau hinh moi tinh nang nam canh ket qua cua no (vd. cong tac Quet ma doc
           ngay tren ket qua quet). URL cu (Bao ve, He thong) tu chuyen huong sang moi
         - Bo form cai dat khong lo 65 truong: moi tab la mot form rieng, khi luu chi
           cap nhat cac khoa cua tab do. Them thong bao "Da luu" va loi quyen
         - Telegram chuyen sang Cai dat chung; AbuseIPDB chuyen sang Chong Click Fraud
           (dung cho ca Block Log)
         - Tach admin/menu.php (3.889 dong) thanh admin/common.php + admin/pages/*.php
         - Bat/tat module Bao mat va Marketing (Cai dat chung → Module). Module tat thi
           khong nap code, an menu; tat Bao mat go rule hardening khoi .htaccess, bat
           lai tu dung lai. Mac dinh bat het — site dang chay giu nguyen hanh vi.
           TrackSG (scripts.php) luon chay vi la nguon du lieu chong click fraud
         TRANG CHAN DUNG CHUNG (includes/block-page.php)
         - Moi nguon chan (click fraud, lockout, Firewall, Rate Limit, Honeypot, Hide
           Login) tra loi qua 1 ham, dung ma HTTP (403 / 429 + Retry-After / 404),
           noindex + no-store, render tai cho — khong redirect, khong file tinh
         - Giong trung tinh, khong hien IP / User-Agent / ten plugin / trich dan luat.
           Thay bang MA THAM CHIEU (HMAC theo IP): khach gui ma, admin tra ra IP tai
           Chong Click Fraud → Sync & Kiem tra de go chan neu nham
         - Tab moi Cai dat chung → Trang chan: tieu de, loi nhan, lien he (SDT / email /
           Zalo tu thanh link), bat/tat dong gioi thieu saigon.pro (mac dinh bat),
           xem truoc 4 kieu chan
         - Fix che do Redirect (mac dinh cu): URL trong → khach thay trang trang 200;
           URL tro ve chinh site → vong lap redirect vo han. Nay chi chuyen sang
           website KHAC, con lai tu hien trang chan. Mac dinh moi: "Hien trang chan"
         - Hide Login: /wp-login.php tra 404 tai cho thay vi 302 sang trang chan
           (redirect cho ke do biet co trang dang nhap an)
         - Bo o "URL trang Lock (403)" (gan nhu khong duoc dung) va file tinh
           /adsdefender-blocked.html o thu muc goc (migration tu xoa file cu)
         LIVE TRAFFIC (Chong Click Fraud → Live Traffic, mac dinh TAT)
         - Ghi moi luot xem trang: IP + ma tham chieu, nguon (Google Ads / Meta Ads /
           tim kiem / mang xa hoi / website khac / truc tiep), loai khach (nguoi that /
           bot that / bot / bi chan), ma HTTP, UA, referer, Matomo visitor ID
         - Tu cap nhat 5 giay, loc theo loai / nguon / ma loi / IP, bam dong xem chi tiet;
           Chan / Whitelist / tra uy tin IP ngay tren dong (khong cho tu chan IP minh)
         - Ghi o hook shutdown (1 INSERT/luot), bo qua wp-admin, AJAX, cron, CLI, file
           tinh, tai khoan bien tap; tu don theo so gio + so dong toi da
         - Chien dich / tu khoa: boc tach UTM + ValueTrack Google Ads (keyword,
           matchtype, campaignid / gad_campaignid, adgroupid, network, device,
           placement, creative, gclid…) + Meta / TikTok / Bing; bo tag chua thay
           ({keyword}); tu khoa tu nhien tu referer Bing / Coc Coc / Yahoo…
         - Nhan dien dung khach tu Google: Google Ads (gclid / gbraid / wbraid / dclid /
           gad_source / gad_campaignid, UTM tra phi utm_source=google|adwords|youtube,
           referer googleadservices / doubleclick / googlesyndication) va Google tu nhien
           (google.com[.vn], m., news., app Google Android) tach rieng; Gmail / Docs la
           website khac; bo click ID rong / tag chua thay ({gclid}); so khop domain theo
           nhan (box.com khong con bi nhan la x.com); fbclid chi la Meta Ads khi co
           medium tra phi hoac macro campaign_id / adset_id
         - Danh dau gclid dang ngo (van tinh nguon Google Ads nhung gan ⚠): sai dinh
           dang (gclid < 40 ky tu / ky tu la, gbraid-wbraid khong bat dau 0AAAAA);
           dung lai (cung gclid o IP khac hoac cung IP sau > 30 phut); referer khong
           phai Google. Loc "Google Ads — gclid dang ngo", dem vao % dang ngo cua
           bang Top, o thong ke hien so gclid dang ngo
         - DO LUONG CHUYEN DOI: goi dien / Zalo / Messenger / WhatsApp / Telegram / email
           (script click thu dong, khong phu thuoc module Marketing), form (Contact
           Form 7, WPForms, Elementor Pro, Fluent Forms, Gravity Forms), lead popup,
           don WooCommerce (kem gia tri). Moi chuyen doi gan ve nguon / chien dich / tu
           khoa cua phien → biet cuoc goi den tu tu khoa Google Ads nao
         - Bang Top them cot Chuyen doi (+ gia tri) va Ty le chuyen doi; o thong ke
           chuyen doi / gio (tu Google Ads); loc "Chuyen doi"
         - Chong gia mao: cung IP + loai + nhan trong 60s tinh 1 lan, toi da 30 / IP /
           gio, beacon chi nhan khi IP da xem trang trong 2 gio, don hang / form / lead
           chi ghi phia may chu; bot van ghi nhung khong tinh vao bang Top
         - Ham public adsdefender_lt_conversion($type, $label, $value) va JS
           adsLtConv(type, label, value) cho chuyen doi tuy chinh
         - PHAT HIEN CLICK AO TAI CHO (mac dinh BAT khi Live Traffic bat), chay ngay o
           moi click quang cao, khong can Matomo, khong cho cron 30 phut:
           · cung IP click QC >= N lan / X gio (mac dinh 3 / 24h) ma chua chuyen doi
           · bot / cong cu tu dong click QC (bot that da xac minh duoc bo qua)
           · IP Threat Intel click QC
           · (tuy chon) dung lai gclid cua luot khac; >= M IP cung /24 click trong 1h
           Che do Khoa IP (lockout + tu ban vinh vien khi tai pham) hoac Chi danh dau;
           bao Telegram; bo qua whitelist / IP admin / IP da chuyen doi
         - Xuat danh sach IP loai tru cho Google Ads (toi da 500, moi nhat truoc):
           sao chep / tai .txt — chan tren website khong lam Google ngung tinh tien
         - Loc "Click ao", o thong ke click ao / gio, cot Bot / chan / ao trong bang Top
         - SUA LOI LOCKOUT: expires_at tinh theo UTC (gmdate) nhung so voi NOW() cua
           MySQL (gio +07 o hosting VN) → lockout < 7 gio het han ngay khi tao, lockout
           24h chi con 17h; nay moi moc thoi gian deu do MySQL tinh
         - Khoa nang cap bang (INSERT IGNORE) tranh loi "Duplicate column" khi nhieu
           request admin chay cung luc
         - Loc "Google (Ads + tu nhien)", "Google Ads", "Google tu nhien" gom ca trang
           khach xem tiep; o thong ke Google Ads / Google tu nhien moi gio
         - Trang xem tiep cung IP trong 30 phut ke thua nguon + chien dich / tu khoa
           cua luot vao (danh dau ↪)
         - Bang Top tu khoa / chien dich: click, IP, xem tiep, bot / chan, % dang ngo
           (bot + chan + click lap cung IP); bam de loc log; o tim + loc "chi luot co
           chien dich"
         THREAT INTEL (Bao mat → Threat Intel)
         - Dong bo hang ngay Spamhaus DROP (v4+v6), FireHOL Level 1, Tor exit nodes —
           mien phi, khong can key. Moi nguon: Tat / Chi gan nhan / Chan
         - Loc bo dai private / reserved / CGNAT 100.64.0.0/10 co san trong danh sach
           goc (FireHOL chua ca 10/8, 192.168/16…); tai loi hoac danh sach bat thuong
           → giu ban cu
         - Tra cuu nhi phan tren dai da gop (~3µs/IP); bo qua whitelist, bot da xac
           minh, tai khoan bien tap. Ghi Security Events "Threat Intel"; nhan hien
           trong Live Traffic va Block Log; o kiem tra 1 IP
         FIX
         - Them o "Site chay sau Cloudflare / reverse proxy" — tuy chon da co trong code
           (header ghi Cloudflare Support) nhung khong co tren giao dien
         - Luu cai dat bang mang day du (trang Whitelist…) khong con xoa mat cac khoa
           ma sanitizer khong quan ly (vd. behind_cloudflare)
 2.5.121 NANG CAP FIREWALL (SQLi / XSS / Path Traversal / RCE) — do tren noi dung
         doi thuc: ban cu chan nham 40% noi dung hop le VA bo lot 38% tan cong that.
         Ban moi: 0% chan nham, 0% bo lot (30 mau hop le + 26 mau tan cong).
         - Them lop CHUAN HOA payload truoc khi so khop: giai ma toi da 3 lop
           URL-encode, HTML entity, bo null byte, gom khoang trang. Nho vay bat duoc
           ..%252f..%252fetc/passwd va javas&#99;ript: ma ban cu bo lot
         - Bo comment SQL bang KHOANG TRANG (khong xoa trang) de bat UNION/**\/SELECT
           va /*!50000UNION*\/ — xoa trang bien thanh UNIONSELECT lam \b khong con khop
         - Viet lai 4 bo pattern theo nguyen tac "can dau hieu cu phap": khong bat tu
           khoa SQL / ten ham dung mot minh. Truoc day "select san pham from shop",
           "update thong tin set nay", "drop table ghe qua", "chi phi 1=1 trieu",
           bai viet nhac ten ham thuc thi/giai ma deu bi chan + KHOA IP 24h
         - Bo pattern chan moi URL .php co query (redirect_to, link trong binh luan)
         - Them lop tan cong ban cu khong co: PHP object injection, SSTI ({{7*7}}),
           XXE, Log4Shell/JNDI, prototype pollution, .env / .git/config / id_rsa,
           php://filter, phar://, time-based blind (pg_sleep, waitfor delay).
           XSS event handler nay bat MOI on*= thay vi 9 cai hard-code
         - Sua 3 loi trong ham quet: (1) chi doc $_POST cap 1 nen payload trong
           data[user][name] lot hoan toan — nay de quy toi 6 tang, quet ca ten field;
           (2) gop moi field thanh 1 chuoi roi moi match → pattern khop cheo giua hai
           field vo hai — nay quet tung chuoi rieng; (3) khong quet cookie va header —
           nay co (Log4Shell va SQLi qua X-Forwarded-For rat pho bien)
         - Ba lop mien tru chong chan nham: bot hop le da xac minh, user co quyen
           edit_posts, va cac field noi dung dai (post_content, comment, custom_css)
         - Hieu nang 0.07ms/request. Da test ReDoS voi 8 chuoi co tinh gay backtracking
           (../ x2000, %25 x2000): cham nhat 4ms. Bo qua chuoi > 8KB
         TOI UU DATABASE SCAN — ban cu bao nham 88% noi dung DB hop le (14/16 mau),
         admin se mac dinh bo qua canh bao, che mat canh bao that. Ban moi 0%.
         - db_url_hijack bao MOI URL dang IP → site chay LAN, localhost, VPS chua tro
           domain deu bi bao. Nay chi bao IP PUBLIC va KHAC host site
         - db_script_src: whitelist mo rong 30 -> 90 domain, them nhom pho bien VN
           (Zalo, MoMo, VNPay, Haravan, Sapo, KiotViet, GHN, Subiz) va chat/analytics/
           payment quoc te (Shopify, Zendesk, Clarity, Intercom, Stripe...). Chuyen tu
           regex sang PARSE URL that, so khop theo nhan domain nen tiktok.com.evil.net
           khong the gia mao. Them filter adsdefender_db_script_whitelist cho CDN rieng
         - db_iframe_hidden: width=0 la binh thuong voi embed lazy-load (YouTube, Maps).
           Nay chi bao khi iframe VUA an VUA tro domain ngoai whitelist
         - db_obj_injection: ban cu bat moi O:...{...eval|system nen dinh WooCommerce
           (field ten "system") va ACF (field ten "eval"). Nay unserialize that voi
           allowed_classes=false (an toan, khong chay __wakeup) roi duyet cay, chi bao
           khi ten ham nguy hiem nam o KHOA mang nghia callback (cb, callback, handler)
         - db_rogue_admin: bo protonmail/yandex.ru (admin that hay dung), thu ve email
           dung-mot-lan va CHI ap dung cho admin tao trong 90 ngay
         - db_pharma_links yeu cau link BI AN (bai viet ve casino khong con dinh);
           db_eval_b64 yeu cau chuoi base64 >= 40 ky tu; db_php_tag yeu cau kem ham nguy hiem
         - Them 4 lop phat hien moi: thuc thi ma nhan superglobal, JS redirect domain ngoai,
           cloaking theo User-Agent bot, khoi van ban giau ngoai man hinh
         HIEU NANG DATABASE SCAN:
         - Sua N+1 query: vong lap users chay 2 query/user x 200 user = 400 QUERY.
           Nay 1 query JOIN lay dung admin + 1 query theo lo cho toan bo usermeta
         - Do phu options: truoc chi quet 17 TEN CO DINH nen bo lot hoan toan option
           cua plugin bi hijack. Nay quet MOI autoload option (malware phai autoload
           moi chay duoc tren frontend), bo qua transient/cache
         - Do phu posts: truoc lay 500 bai MOI NHAT bat ke noi dung nen malware nhet
           vao bai cu it ai xem thi lot. Nay loc so bo bang SQL roi quet TOAN BO bai
           kha nghi theo lo 200, dung keyset pagination (ID > last_id)
         - Bo qua gia tri > 256KB (anh base64, JSON page builder), gioi han de quy,
           guard chong vong lap vo han. Ket qua sap xep critical len dau
         - UI hien thong ke chi tiet theo tung bang thay vi chi so posts
 2.5.120 Sua loi CHAN NHAM BOT HOP LE — anh huong truc tiep SEO va Google Ads:
         - Danh sach bad UA dang chan Baiduspider, YandexBot (search engine that) va
           AhrefsBot/SemrushBot/MJ12bot/DotBot/BLEXBot/PetalBot/DataForSeoBot. Chan bang
           403 + ghi lockout lam mat index va phinh bang lockout. Da go search engine
           hoan toan; nhom bot SEO chuyen sang tuy chon rieng "Chan bot SEO" (mac dinh TAT,
           khi bat thi tra 403 nhung KHONG khoa IP)
         - Go cac pattern chan nham hang loat: 'curl/7.1'-'curl/7.9' la prefix match nen
           dang chan gan nhu toan bo curl 7.x (ke ca curl/7.81 mac dinh Ubuntu 22.04);
           'python-requests/2.1' chan ca 2.10-2.19; 'LWP' 3 ky tu dinh bua; 'Java/' chan
           nhieu webhook hop le. Don trung lap (masscan 2 lan, zmeu/ZmEu, dirb)
         - UA rong truoc day bi 403 + khoa IP o MOI request (cron hosting, uptime monitor
           deu dinh). Nay chi chan khi nham vao endpoint nhay cam (wp-login, xmlrpc,
           wp-admin, wp-config, .env, /wp-json/wp/v2/users)
         GOOGLE ADS BOT KHONG QUET DUOC TRANG DICH — nguyen nhan la Rate Limiting:
         - AdsBot-Google crawl landing page rat nhanh de cham diem chat luong, de vuot
           nguong 300 req/60s. Vuot la bi khoa IP + tra 429 → bot bao "trang dich khong
           truy cap duoc" → quang cao bi tu choi. 404-detector cung khoa khi bot thu vai URL
         - Them lop xac minh bot moi adsdefender_is_verified_bot(): KHONG tin UA suong ma
           dung rDNS + forward-confirm dung cach Google khuyen nghi. Phu AdsBot-Google,
           Mediapartners-Google, Googlebot, Google-InspectionTool, Bingbot, AdIdxBot,
           YandexBot, Baiduspider, DuckDuckBot, Applebot. Cache 24h, ho tro IPv4/IPv6
         - Noi vao rate-limit, 404-detector, honeypot, va chot chan cuoi trong
           adsdefender_upsert_lockout() — khong bao gio khoa bot da xac minh
         Mo rong danh sach bad UA: 22 -> 97 pattern. Them scanner hien dai (invicti,
         arachni, wapiti, whatweb, feroxbuster, dirsearch, nuclei, zaproxy, crackmapexec),
         payload Log4Shell nhet trong UA, cong cu brute-force, site ripper, va AI scraper
         phot lo robots.txt (bytespider, imagesiftbot, omgili). Tat ca pattern deu duoc
         test de khong dinh phan mem hop le cung ten (Canvas LMS, log4js, httpx, Hydra...)
 2.5.119 Sua 2 signature bao dong gia (bat oan plugin hop phap):
         - hidden_spam_div bat MOI <div style="display:none"> nen wpforms, litespeed-cache,
           freemius debug templates deu bi cham high. Div an la chuyen binh thuong (modal,
           template cho render). Nay chi bao khi ben trong div an co THE LINK RA NGOAI —
           dung dac trung SEO injection (giau backlink cho bot doc)
         - eval_var ha tu 'high' xuong 'medium' va bo qua ten bien ro nghia an toan
           ($safe_code, $code, $template...). Backdoor that gan nhu luon kem base64/gzinflate/
           $_POST va da co signature rieng bat; thuc thi ma tu bien mot minh khong du de ket luan
         Them TU DONG TOI UU wp-config.php (nut moi trong trang Malware Scanner):
         - Dat WP_DEBUG_DISPLAY=false, WP_DEBUG_LOG=true, DISALLOW_FILE_EDIT=true,
           WP_AUTO_UPDATE_CORE='minor'; don ky tu thua sau the dong PHP
         - An toan: xem truoc thay doi va hoi xac nhan, sao luu truoc khi ghi, kiem tra cu
           phap ban moi TRUOC khi thay the, doc lai kiem tra lan nua, hong thi khoi phuc ngay
         - Khong bao gio tu dong doi salt keys (se dang xuat toan bo nguoi dung)
 2.5.118 Malware Scanner: sua loi "Loi ket noi khi quet Plugins".
         - RecursiveIteratorIterator nem UnexpectedValueException tu getChildren() GIUA vong
           lap khi gap thu muc khong doc duoc (hay gap tren shared hosting o wp-content/plugins).
           Bat o ngoai foreach nen mat ca nhanh con lai, hoac thoat ra thanh trang HTML fatal.
           Nay dung RecursiveCallbackFilterIterator loc truoc khi di xuong, va catch Throwable
           thay vi Exception de giu lai phan da gom duoc
         - $skip_dirs (node_modules, cache, backup...) truoc day dung rewind() de bo qua nhung
           rewind() quay lai dau thu muc chu khong bo qua — cac thu muc nay chua bao gio duoc
           loai tru, lam quet cham va ton bo nho
         Them kiem tra CAU HINH .htaccess & wp-config.php (batch moi "config-audit"):
         - wp-config: salt mac dinh, WP_DEBUG hien thi loi, ky tu sau ?>, quyen file qua rong,
           thieu DISALLOW_FILE_EDIT, tien to bang wp_
         - .htaccess: auto_prepend_file (backdoor), redirect ra ten mien ngoai, AddHandler php,
           Options +Indexes, chua chan truy cap wp-config.php
         - Bo qua dong comment, im lang tren Nginx, khong bao voi .htaccess mac dinh cua WP
 2.5.117 Sua loi Fatal khi cai chung voi plugin dung Freemius SDK (vd File Manager Advanced).
         Freemius goi update_option() ngay khi plugin dang nap, action 'updated_option' ban ra
         luc pluggable functions cua WP chua duoc dinh nghia — callback admin-log goi
         is_user_logged_in() gay Fatal trang wp-admin. Them guard function_exists() cho
         is_user_logged_in() va wp_get_current_user().
         Security Headers: bo qua khi headers da duoc gui truoc (plugin khac in output som,
         BOM, khoang trang) thay vi de PHP sinh warning tren trang khach. Ghi lai file/dong
         gay ra vao option adsdefender_headers_sent_by de chan doan
 2.5.116 File Scanner: don ket qua cu khong con dung. Truoc day quet lai chi upsert va xoa
         record cua file da bi xoa — canh bao cu nam lai vinh vien du signature da sua hay
         file da duoc va. Nay moi lan quet se xoa cac cap file|signature khong con khop
         (giu nguyen muc da danh dau 'ignored'). Them nut "Kiem tra lai" chay signature
         tren cac file da bao ma khong can quet lai toan bo
 2.5.115 File Scanner: sua 2 bao dong gia.
         - mail_spam thieu neo dau tu nen "sanitize_email($_POST[...])" bi bat vi chu "mail"
           nam trong "sanitize_email". Them (?<![\w$>-]) — wp_mail/is_email/->mail() deu sach
         - php_in_upload bat moi <?php nen index.php "Silence is golden" cua WordPress,
           WPForms, iThemes Security trong uploads/ bi cham critical. Bo qua khi noi dung
           dung la stub vo hai (kiem tra theo NOI DUNG, malware nup ten index.php van bi bat)
 2.5.114 Cap nhat header Update URI va cac tham chieu con ghi track.saigon.pro sang GitHub
 2.5.113 Nguon cap nhat chuyen tu track.saigon.pro sang GitHub: adsdefender-update.json nam
         ngay trong repo, doc qua raw.githubusercontent. Khong con phai upload JSON thu cong
         moi lan phat hanh. Them release.sh lam tron chuoi bump -> json -> tag -> zip -> release
 2.5.112 UTM & Attribution: them thong ke trong ngay — bo loc "Hom nay"/"Hom qua", 4 the so
         lieu (sessions, chuyen doi, ty le, gio cao diem) kem so sanh voi hom qua, bieu do
         phan bo theo gio 0-23, va bieu do xu huong theo ngay. Moc thoi gian dung
         current_time() theo mui gio site thay vi gio UTC server
 2.5.111 DB Scanner: snippet hien du URL. Signature ket thuc bang lookahead (?!...) khong tieu
         thu ky tu nen $m[0] cut ngay sau "https://" — admin khong biet domain nao bi bao.
         Them adsdefender_db_snippet() lay them ngu canh sau vi tri khop (4 cho).
         Badge menu: bo class 'awaiting-mod' (cua comment cho duyet) sang 'update-plugins',
         rut gon so >999 thanh "999+", chuyen badge log tu menu cha/Marketing sang Bao ve,
         va bo badge update bi lap 2 lan (menu.php + update.php priority 999)
 2.5.110 Them filter upgrader_source_selection: doi ten thu muc khi giai nen ZIP tu GitHub
         (adsdefender-wp-plugin-x.y.z -> adsdefender). Khong co no, moi lan cap nhat tu
         GitHub release se tao plugin moi thay vi ghi de ban cu
 2.5.109 DB Scanner: them domain nhung hop le vao whitelist db_script_src (TikTok, YouTube,
         Facebook, Twitter/X, Vimeo, Instagram, GTM, Google Ads...) — truoc day embed.js cua
         TikTok trong bai viet bi bao "External script tu domain la" severity high.
         Kem theo va lo hong co san: whitelist thieu neo cuoi nen tiktok.com.evil.net lot luoi
 2.5.108 Fix cau truc HTML admin: trang Dashboard thua mot the </div> (dong 461) lam dong som
         #wpwrap, day #wpfooter ra ngoai container — footer admin hien thi sai vi tri
 2.5.107 Bo doan JS an notice "Cam on ban da khoi tao voi WordPress" tren trang AdsDefender —
         chi khop tieng Viet, dung NodeList.forEach, va co the an nham notice plugin khac
 2.5.106 Dem lai click link tel:/zalo/m.me/wa.me/t.me trong bai viet + widget + block, nhung
         chen onclick tu PHP (the_content/widget_text/render_block) thay vi listener JS —
         HTML tra ve da hoan chinh, khong co gi dung giua cu bam va hanh vi mac dinh.
         Bo qua link da co onclick (nut Contact Bar) de khong dem trung
 2.5.105 Bo hoan toan JS bat click toan site (utm.php + contact-bar.php). Nut tel: tro ve HTML
         thuan — khong JS nao chan duoc dieu huong nua. Conversion Contact Bar chuyen sang
         onclick="adcbTrack(i)" ngay tren the <a>, chay sau khi trinh duyet da dieu huong.
         Danh doi: khong con dem cu bam vao link tel:/zalo: nam ngoai Contact Bar
 2.5.104 Fix that: nut tel: van khong quay so tren mobile — con listener thu 2 o utm.php dung
         e.target.closest() (v2.5.103 chi va contact-bar.php). Doi sang tu leo cay DOM + try/catch;
         thay startsWith/includes bang indexOf va NodeList.forEach bang vong for (WebView cu)
 2.5.103 Contact Bar fix: nut goi khong hoat dong tren mobile (SVGElement khong co .closest()
         gay TypeError o capture phase, chan luon tel:); them hide_on_pages (ID/slug/path/wildcard);
         them current_user_can, is_array cho $_POST (fatal PHP 8); fix nut nhan ban + option phinh;
         sg_event nay co tac dung; esc_url_raw cho custom/messenger/maps; body padding tranh che
         noi dung; icon Zalo dung logo that
 2.5.102 Security fix: CSV/formula injection o Export Leads, hash_equals() cho Hide Login token,
         escape HTML o File Monitor scan result (XSS phong ngua)
 2.5.101 Bo tinh nang chan IP qua .htaccess — block IP chi thuc hien qua PHP
 2.5.100 Fix REST token toggle button (onclick quote conflict → addEventListener)
 2.5.99  Mask all secret key inputs (Matomo token, CAPTCHA secret, AbuseIPDB key) - type=password
 2.5.98  Security: fix check_ajax_referer field name, validate api_url scheme (SSRF), mask REST token
 2.5.97  Fix: them cs_enabled/cs_rate_limit/cs_blocklist/fm_enabled/scan_enabled vao sanitize_settings
 2.5.96  Comment Security: bo duplicate/link/keyword tu nhap (WP co san); chi giu rate limit +
         spam blocklist tu dong (splorp/wordpress-comment-blacklist 40k+ terms, cache 7 ngay)
 2.5.95  Database Scanner: quet posts/options/usermeta tim malware inject (11 DB signatures);
         Comment Security: honeypot, time check, link spam limit, rate limit, keyword blacklist
 2.5.94  Malware Scanner: +20 signatures (create_function chain, eval_var, decode_chain, C2 callback,
         WP hook inject, spam div, ob_start+b64, daemonize, mail relay, wasm miner, fake image...)
 2.5.93  Fix Permissions logic: .htaccess=0644 la dung (server can doc), hien lenh SSH khi PHP khong chmod duoc
 2.5.92  File Permissions: nut Fix tu dong chmod toi uu + Rollback 1-click khoi phuc quyen cu
 2.5.91  Fix File Permissions logic: 0600 OK (chi owner), 0644 canh bao (group/other doc duoc DB pass)
 2.5.90  File Permissions Check: kiem tra chmod wp-config/htaccess/dirs, highlight sai, huong dan fix
 2.5.89  Fix Scanner: tu dong reload trang sau khi scan xong de hien ket qua tu DB (khong mat sau reload)
 2.5.88  Sucuri SiteCheck integration: external scan blacklist+malware+SSL rating, cache 12h
 2.5.87  Fix chr() false positive: chi bao khi chr() chain duoc dung de goi ham (WooCommerce safe)
 2.5.86  Fix Scanner false positive: php_only/skip_core/uploads_only flags, bo qua .min.js
 2.5.85  Fix Scanner + FileMonitor: sua ten ham adsdefender_log_security_event -> adsdefender_sec_log
 2.5.84  Fix Scanner: bat Throwable exception + error_handler trong AJAX, hien thi loi chi tiet
 2.5.83  Fix Scanner timeout: wp-includes depth=1, phan trang 150 files/request, offset pagination
 2.5.82  Fix Scanner timeout: scan theo 6 batch nho (config/core/admin/themes/plugins/uploads), progress bar
 2.5.81  Fix Malware Scanner: catch RecursiveIterator exception (symlink/permission), wrap preg_match
 2.5.80  Fix Malware Scanner: skip AdsDefender plugin dir de tranh tu detect signature
 2.5.79  Malware Scanner: quet 24 signature (eval/base64, webshell, redirect, miner...), tab Site Scanner
 2.5.78  File Change Detection: monitor SHA-256 hash WP core + plugins + themes, alert khi co thay doi
 2.5.77  Fix: An notice "Cam on ban da khoi tao voi WordPress" (WP 7.0) tren trang AdsDefender
 2.5.76  Fix: Admin Log setting bi thieu trong tab Bao ve sau khi refactor tabs
 2.5.75  Settings: refactor tab UI — 5 tabs (Ket noi / Bao ve / Server & WP / Cross-site / Cong cu)
 2.5.74  Blocked page: redesign light mode, bo dark scary, them promo saigon.pro duoi footer
 2.5.73  Fix SystemTweaks: plugins/themes PHP block dung parse_url() thay str_replace(home_url()) — fix URL day du trong htaccess gay block WP admin
 2.5.72  TrackSG noscript: them dimension1=Jsdisable vao URL de track user tat JS
 2.5.71  Preview: them TrackSG noscript vao Preview script dang inject
 2.5.70  Fix TrackSG noscript: fallback sang wp_footer neu theme khong support wp_body_open
 2.5.69  Tich hop nhanh: them nut "Quet tu dong" tu dong detect GTM ID, Meta Pixel ID tu HTML trang chu;
         TrackSG ID tu dong lay tu site_id trong Settings; AJAX handler adsdefender_detect_tracking
 2.5.68  Fix .htaccess thu tu block: AdsDefender-SystemTweaks luon chen SAU AdsDefender (IP block);
         auto-fix khi rebuild (settings save / deactivate+activate)
 2.5.67  System Tweaks refactor: gom 4 RewriteRule block thanh 1 (iThemes pattern), them <files> bao ve .htaccess/readme/wp-config.php;
         Fix insertion order: SystemTweaks luon chen SAU "# END AdsDefender" (IP block), khong prepend len dau file
 2.5.66  Htaccess protection: exact-line regex, LSCACHE/WP-Defender/length-ratio validation, preg_replace limit=1,
         post-write verify + rollback; garbage "-SystemTweaks" auto-sanitize; system-tweaks.php tương tự
 2.5.65  Admin Activity Log: ghi login/logout, settings change, post/media/user actions (includes/admin-log.php);
         Tab He thong → Admin Log: filter theo loai/user/ngay, pagination, IP Reputation;
         Settings: toggle bat/tat Admin Log
 2.5.58  Security Event Log tập trung (adsdefender_security_log): ghi brute_force/firewall/rate_limit/flood_404;
         Dashboard: widget security stats 7 ngày + top IPs tấn công;
         Tab Bảo vệ → Security Events: filter theo loại/IP, pagination 50 dòng;
         IP Reputation: tra cứu AbuseIPDB inline (modal) từ mọi nơi có IP trong admin;
         Settings: thêm AbuseIPDB API Key field
 2.5.57  Fix: lock_url + redirect_url dùng esc_url_raw → mất giá trị khi nhập path tương đối (/adsdefender-blocked.html);
         input type="text" thay vì type="url" để browser không reject path tương đối
 2.5.56  WordPress background auto-update: filter auto_update_plugin + toggle UI trong Settings
 2.5.55  Cải tiến auto-update: thêm requires/tested/php fields, no_update transient, badge menu,
         redirect helper, fallback install khi upgrade fail, fix file.php missing
 2.5.54  Fix: adsdefender_detect_server_type() undefined → activation crash (Fatal Error);
         Fix: adsdefender_hl_flush_rules() undefined → activation crash (Fatal Error)
 2.5.52  Fix: array_column($manual) khi unserialize trả false (encoding bug note tiếng Việt);
         Fix: whitelist không block firewall/UA check → thêm whitelist check vào đầu mỗi check function;
         Fix: parse_whitelist() strip comments (#...) trước khi match IP;
         Fix: auto-ban note dùng ASCII thay vì tiếng Việt để tránh serialize encoding lỗi
 2.5.51  System Tweaks (.htaccess: chặn readme/install/wp-includes PHP/git, tắt directory listing,
         block PHP trong uploads/plugins/themes); WP Tweaks (DISALLOW_FILE_EDIT, xóa WP version,
         chặn author enum, block REST /users); Security Headers (HSTS, CSP, X-Cross-Domain, COOP)
 2.5.50  Thêm Cloudflare Turnstile vào CAPTCHA module; provider = google_v2|google_v3|turnstile;
         verify endpoint: challenges.cloudflare.com/turnstile/v0/siteverify; bỏ qua timeout-or-duplicate
 2.5.49  Thêm reCAPTCHA v2/v3 (includes/recaptcha.php): login, lostpassword, register, comment;
         Fix wp-admin chưa login → redirect /adsdefender-blocked.html thay vì 404 HTML thô
 2.5.48  Fix Brute Force: check lockout TRƯỚC khi block_admin để record được tích lũy đúng;
         sửa thứ tự authenticate hook: check → admin_block+record → wp_login_failed record
 2.5.47  Fix Hide Login: /wp-admin/ chưa login → 404 (không redirect sang slug, không lộ login URL)
 2.5.46  Fix Hide Login: khai báo global $user_login, $error, ... trước require wp-login.php
         để tránh PHP Warning "Undefined variable" (require trong function scope ≠ global scope)
 2.5.45  Fix Hide Login: không redirect (lộ slug), serve wp-login.php trực tiếp bằng
         fake $_SERVER + require — slug không bao giờ xuất hiện trong network tab;
         filter site_url để links trong HTML cũng trỏ về slug thay vì wp-login.php
 2.5.44  Fix double token trong Location header; flag tắt site_url filter khi build redirect URL
 2.5.43  Viết lại hoàn toàn Hide Login theo cơ chế Solid Security Pro: setup_theme hook,
         token=slug, cookie 1h, filter site_url để tự động thêm token vào links,
         remove wp_redirect_admin_locations, xử lý đúng logout/resetpass/lostpassword
 2.5.42  Fix Hide Login: cho phép wp-login.php khi có WP auth cookie hoặc reauth từ wp-admin,
         fix lostpassword/resetpass action, fix vào wp-admin sau khi đã login
 2.5.41  Fix Hide Login redirect loop: bỏ filter login_url trong wp-admin context,
         bỏ block /wp-admin/ (WP tự xử lý auth), chỉ block /wp-login.php GET trực tiếp
 2.5.40  Fix Hide Login: dùng plugins_loaded + header redirect thay vì require wp-login.php
         trực tiếp — tránh undefined variable $user_login/$error trong wp-login.php
 2.5.39  Bảo mật toàn diện: Brute Force (login attempt limit per IP/user), Request Firewall
         (SQLi/XSS/path traversal/shellcode/bad UA), Hide Login URL + XML-RPC disable,
         Rate Limiting (sliding window), Honeypot (login/comment/register), 404 Flood Detection
 2.5.38  Fix đọc cookie visitor: PHP tự đổi _pk_id.12.xxx → _pk_id_12_xxx (dấu chấm → underscore)
 2.5.37  Xóa toàn bộ IP cũ khỏi .htaccess khi migrate — thiết kế mới lockout tạm chỉ PHP,
         .htaccess chỉ chứa permanent ban; sync không còn ghi IPs Matomo vào .htaccess
 2.5.36  Auto-upgrade migration khi update (không cần deactivate/activate): tạo bảng lockouts,
         dọn log cũ, rebuild .htaccess sạch, reset temp whitelist, cấu hình lại cache plugins
 2.5.35  Lockout tạm thời + ban vĩnh viễn (học từ Solid Security Pro): bảng adsdefender_lockouts,
         auto-whitelist admin IP 24h, skip WP-CLI/Cron/REST/XMLRPC, DONOTCACHEPAGE cho trang blocked
 2.5.34  Dashboard: widget cache status + nút "Tự động sửa" Do Not Cache Cookies
 2.5.33  Tự động cấu hình 6 cache plugin (LiteSpeed, WP Rocket, W3TC, WP Super Cache, Cache Enabler, Breeze)
 2.5.32  Fix IPv6 đơn trong .htaccess (Apache 2.4 + LiteSpeed hỗ trợ, chỉ bỏ IPv6 CIDR);
         Cloudflare: xác minh REMOTE_ADDR thuộc dải CF trước khi tin CF-Connecting-IP
 2.5.29  Validate IP trước khi ghi .htaccess; case 403 PHP trả HTTP 403 thật; comment CF firewall
 2.5.28  Fix visitor sync bool; get_real_ip() default REMOTE_ADDR, proxy header chỉ tin khi bật
 2.5.27  Fix 7 lỗi: REST token, sync bool, IPv6 bits, LOCK_EX, log cleanup 1%, CF spoofing, Apache 2.4
 2.5.26  Fix htaccess không ghi mỗi page load
 2.5.25  Tắt tự động cập nhật .htaccess khi vào setting
 2.5.24  Fix 6 lỗi security
 2.5.23  Fix redirect loop — file HTML tĩnh
 2.5.22  Fix redirect loop whitelist
 2.5.21  Viết lại .htaccess format mod_litespeed
 2.5.20  Tự động tạo trang /adsdefender-blocked
 2.5.19  Fix ErrorDocument 403
```
