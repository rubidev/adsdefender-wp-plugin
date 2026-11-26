#!/usr/bin/env python
# Mã hoá dev/firewall-rules.json → includes/firewall-rules.dat (hex của JSON gọn).
#
# Vì sao: scanner malware trên hosting (cPGuard, ImunifyAV, ClamAV…) so khớp text.
# Danh sách luật Firewall (UA scanner, pattern SQLi/XSS/LFI/RCE) chứa đúng những
# chuỗi có trong webshell nên file PHP chứa chúng bị cách ly ({CPG}.php.IndoXploit).
# Để dạng hex trong file dữ liệu thì không còn chuỗi nào để so khớp.
#
# Chạy: python dev/build-rules.py   (release.sh tự gọi trước khi commit)
import io, json, os

root = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
src = os.path.join(root, 'dev', 'firewall-rules.json')
dst = os.path.join(root, 'includes', 'firewall-rules.dat')

data = json.load(io.open(src, encoding='utf-8'))
data.pop('_note', None)
raw = json.dumps(data, ensure_ascii=False, separators=(',', ':')).encode('utf-8')
h = raw.hex()
# Ngắt dòng 128 ký tự cho dễ diff; loader bỏ khoảng trắng trước khi giải mã
lines = [h[i:i + 128] for i in range(0, len(h), 128)]
io.open(dst, 'w', encoding='ascii', newline='\n').write('\n'.join(lines) + '\n')
print('firewall-rules.dat: %d luat, %d bytes' % (sum(len(v) for v in data.values()), len(raw)))
