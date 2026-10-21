'use strict';
/*
 * 1.0.20 A5 appointment receipt: drawn on a 1748×2480 canvas (A5 at 300 dpi) in a 1055-wide design grid, then
 * offered as PDF (one A5 page wrapping the JPEG, built here without a library), JPG, or printed on A5.
 * Grey tones so it prints well on any printer.
 */
(async () => {
  const data = JSON.parse(document.getElementById('ticket-data').textContent);
  const canvas = document.getElementById('ticket-canvas');
  const c = canvas.getContext('2d');
  const status = document.getElementById('ticket-status');
  const W = 1055, S = canvas.width / W;
  const ink = '#1d1d1f', muted = '#4b4b52', soft = '#77777d', line = '#d2d2d6', pill = '#ececee';

  const image = (src) => new Promise((ok, fail) => { const i = new Image(); i.onload = () => ok(i); i.onerror = () => fail(new Error('img')); i.src = src; });
  const text = (s, x, y, size, { weight = 400, color = ink, align = 'right', max = 0, dir = 'rtl' } = {}) => {
    c.direction = dir; // numbers in groups (phone, tracking code) are drawn left to right so the groups keep their order
    c.textAlign = align;
    c.fillStyle = color;
    let px = size;
    do {
      c.font = `${weight} ${px}px IRANSans, Tahoma, sans-serif`;
      if (!max || c.measureText(s).width <= max) break;
      px -= 0.5;
    } while (px > size * 0.6);
    c.fillText(s, x, y);
  };
  const wrap = (s, x, y, size, max, lh, opts = {}) => {
    c.font = `${opts.weight || 400} ${size}px IRANSans, Tahoma, sans-serif`;
    const words = String(s).split(' ');
    let lineText = '', lines = [];
    for (const w of words) {
      const t = lineText ? lineText + ' ' + w : w;
      if (c.measureText(t).width > max && lineText) { lines.push(lineText); lineText = w; } else lineText = t;
    }
    if (lineText) lines.push(lineText);
    lines = lines.slice(0, 2);
    lines.forEach((l, i) => text(l, x, y + i * lh, size, { ...opts, max }));
    return lines.length;
  };
  const box = (x, y, w, h, r, { fill = null, stroke = null, width = 1.6, dash = null } = {}) => {
    c.beginPath();
    c.roundRect(x, y, w, h, r);
    if (fill) { c.fillStyle = fill; c.fill(); }
    if (stroke) { c.save(); c.strokeStyle = stroke; c.lineWidth = width; if (dash) c.setLineDash(dash); c.stroke(); c.restore(); }
  };
  const hline = (x1, x2, y, color = line, dash = null, width = 1.4) => {
    c.save(); c.strokeStyle = color; c.lineWidth = width; if (dash) c.setLineDash(dash);
    c.beginPath(); c.moveTo(x1, y); c.lineTo(x2, y); c.stroke(); c.restore();
  };
  const circle = (x, y, r, fill) => { c.beginPath(); c.arc(x, y, r, 0, Math.PI * 2); c.fillStyle = fill; c.fill(); };
  const fit = (im, x, y, w, h) => { const k = Math.min(w / im.width, h / im.height); c.drawImage(im, x + (w - im.width * k) / 2, y + (h - im.height * k) / 2, im.width * k, im.height * k); };

  // Line icons in a 24-unit box centred on (x, y).
  const glyph = (kind, x, y, size = 30, color = ink) => {
    c.save();
    c.translate(x, y);
    c.scale(size / 24, size / 24);
    c.translate(-12, -12);
    c.strokeStyle = color; c.fillStyle = color; c.lineWidth = 1.9; c.lineCap = 'round'; c.lineJoin = 'round';
    const p = new Path2D({
      user: 'M12 12a4.5 4.5 0 1 0 0-9 4.5 4.5 0 0 0 0 9zM4 21c0-4.4 3.6-7 8-7s8 2.6 8 7',
      calendar: 'M4 5h16v16H4zM4 10h16M8 3v4M16 3v4M8 14h.01M12 14h.01M16 14h.01M8 17.5h.01M12 17.5h.01',
      clock: 'M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18zM12 7v5l3 2',
      hash: 'M5 9h15M4 15h15M10 3 8 21M16 3l-2 18',
      doc: 'M6 3h9l4 4v14H6zM9 11h7M9 15h7M9 7h3',
      pin: 'M12 21s7-6.1 7-11.5A7 7 0 0 0 5 9.5C5 14.9 12 21 12 21zM12 12a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5z',
      phone: 'M5 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2',
      building: 'M5 21V4h9v17M14 9h5v12M3 21h18M8 8h3M8 12h3M8 16h3',
      megaphone: 'M3 10v4h3l7 5V5L6 10zM16 9a4 4 0 0 1 0 6M19 6a8 8 0 0 1 0 12',
      globe: 'M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18zM3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18',
    }[kind] || '');
    c.stroke(p);
    c.restore();
  };
  const brand = (right, y, scale) => {
    // The site's default logo (no image uploaded): the calendar-plus mark in its tinted box and the site name,
    // exactly as in the site header.
    const k = scale, box0 = 64 * k;
    c.save();
    c.translate(right - box0, y - box0 / 2 - 6 * k);
    c.beginPath(); c.roundRect(0, 0, box0, box0, 16 * k); c.fillStyle = '#eff6ff'; c.fill();
    c.translate(box0 / 2, box0 / 2); c.scale(1.9 * k, 1.9 * k); c.translate(-12, -12);
    c.strokeStyle = '#2563eb'; c.lineWidth = 1.8; c.lineCap = 'round'; c.lineJoin = 'round';
    c.stroke(new Path2D('M7 4h10a4 4 0 0 1 4 4v9a4 4 0 0 1-4 4H7a4 4 0 0 1-4-4V8a4 4 0 0 1 4-4zM3 9h18M8 2.5v3.5M16 2.5v3.5M12 12v6M9 15h6'));
    c.restore();
    text(data.site, right - box0 - 16 * k, y + 4 * k, 46 * k, { weight: 700, max: 260 * k });
    text('سامانه نوبت‌دهی پزشکان', right - box0 - 16 * k, y + 36 * k, 19 * k, { color: soft, max: 260 * k });
  };

  try {
    await Promise.all([document.fonts.load('700 40px IRANSans'), document.fonts.load('400 20px IRANSans'), document.fonts.load('300 20px IRANSans')]);
    const [photo, logo] = await Promise.all([data.photo ? image(data.photo).catch(() => null) : null, data.logo ? image(data.logo).catch(() => null) : null]);
    c.setTransform(S, 0, 0, S, 0, 0);
    c.fillStyle = '#fff'; c.fillRect(0, 0, W, canvas.height / S);
    box(38, 32, 980, 1436, 2, { stroke: '#56565c', width: 1.6 });

    // Header
    text('رسید نوبت پزشک', 982, 120, 40, { weight: 700 });
    text('سامانه نوبت‌دهی آنلاین پزشکان', 982, 162, 21, { color: muted });
    text('تاریخ صدور: ' + data.issued + '   |   ساعت: ' + data.issuedTime, 982, 210, 17.5, { color: muted });
    if (logo) fit(logo, 72, 60, 400, 170);
    else {
      brand(460, 140, 1.25);
    }
    hline(70, 985, 243, '#3a3a3e', null, 1.6);

    // Doctor
    box(78, 278, 904, 214, 14, { stroke: line });
    circle(200, 385, 88, '#e7e7ea');
    if (photo) {
      c.save(); c.beginPath(); c.arc(200, 385, 84, 0, Math.PI * 2); c.clip();
      const k = Math.max(168 / photo.width, 168 / photo.height);
      c.drawImage(photo, 200 - photo.width * k / 2, 385 - photo.height * k / 2, photo.width * k, photo.height * k);
      c.restore();
    } else glyph('user', 200, 380, 100, '#3e3e44');
    text(data.doctor, 590, 360, 50, { weight: 700, align: 'center', max: 600 });
    if (data.specialty) text(data.specialty, 590, 415, 26, { color: muted, align: 'center', max: 600 });
    if (data.code) text('شماره نظام پزشکی:  ' + data.code, 590, 466, 22, { color: ink, align: 'center' });

    // Appointment rows
    box(78, 515, 904, 402, 14, { stroke: line });
    const rows = [
      ['user', 'نام بیمار', data.patient, 557],
      ['calendar', 'تاریخ مراجعه', data.date, 632],
      ['clock', 'ساعت نوبت', data.time, 707],
      ['hash', 'کد رهگیری', data.tracking, 787],
      ['doc', 'وضعیت نوبت', data.status, 870],
    ];
    rows.forEach(([ic, label, value, y], i) => {
      glyph(ic, 932, y - 2, 36);
      text(label, 872, y + 8, 22, { color: muted });
      if (i === 3) {
        box(113, y - 32, 202, 62, 10, { fill: pill });
        text(value, 214, y + 12, 30, { weight: 700, align: 'center', max: 185, dir: 'ltr' });
      } else if (i === 4) {
        box(113, y - 30, 230, 60, 12, { fill: pill });
        circle(156, y, 17, ink);
        c.save(); c.strokeStyle = '#fff'; c.lineWidth = 3.4; c.lineCap = 'round'; c.lineJoin = 'round';
        c.beginPath(); c.moveTo(148, y); c.lineTo(154, y + 6); c.lineTo(165, y - 6); c.stroke(); c.restore();
        text(value, 305, y + 10, 27, { weight: 700, max: 125 });
      } else {
        text(value, 120, y + 10, i === 0 ? 30 : 32, { weight: 700, align: 'left', max: 560 });
      }
      if (i < rows.length - 1) hline(100, 882, y + (i === 2 ? 38 : i === 3 ? 41 : 38), line, [5, 5]);
    });

    // Clinic
    box(78, 940, 455, 207, 14, { stroke: line });
    text('اطلاعات مطب', 508, 985, 24, { weight: 700 });
    glyph('pin', 113, 1027, 26);
    wrap(data.address, 508, 1035, 19, 350, 27, { color: muted });
    let ly = 1080;
    if (data.phone) { glyph('phone', 113, ly - 6, 24); text(data.phone, 145, ly, 20, { color: muted, align: 'left', dir: 'ltr' }); ly += 40; }
    if (data.extra) { glyph('building', 113, ly - 6, 24); wrap(data.extra, 508, ly, 18, 350, 25, { color: muted }); }

    // QR
    box(553, 940, 429, 207, 14, { stroke: line });
    const qr = window.qrcode(0, 'M');
    qr.addData(new URL(data.url, location.href).href, 'Byte');
    qr.make();
    const n = qr.getModuleCount(), cell = 155 / n;
    c.fillStyle = ink;
    for (let r = 0; r < n; r++) for (let q = 0; q < n; q++) if (qr.isDark(r, q)) c.fillRect(810 + q * cell, 962 + r * cell, cell + 0.4, cell + 0.4);
    text('برای مشاهده و', 780, 992, 19.5, { color: muted });
    text('پیگیری نوبت', 780, 1024, 19.5, { color: muted });
    text('کد QR را اسکن کنید.', 780, 1056, 19.5, { color: muted });
    box(575, 1088, 222, 42, 9, { fill: pill });
    c.direction = 'ltr'; c.textAlign = 'center'; c.fillStyle = muted; c.font = '400 19px IRANSans, Tahoma, sans-serif';
    c.fillText(data.domain, 686, 1116, 205);

    // Notes
    box(78, 1168, 904, 175, 14, { fill: '#f0f0f2' });
    glyph('megaphone', 928, 1214, 38);
    text('نکات مهم', 955, 1268, 22, { weight: 700 });
    data.notes.slice(0, 4).forEach((s, i) => {
      const y = 1208 + i * 37;
      circle(818, y - 6, 3.6, muted);
      text(s, 802, y, 17.5, { color: ink, max: 690 });
    });

    // Footer
    hline(70, 985, 1373, '#3a3a3e', null, 1.4);
    glyph('globe', 966, 1416, 36);
    c.direction = 'ltr'; c.textAlign = 'right'; c.fillStyle = ink; c.font = '700 19px IRANSans, Tahoma, sans-serif';
    c.fillText(data.domain, 934, 1412);
    text('مرجع نوبت‌دهی آنلاین پزشکان', 934, 1442, 15.5, { color: muted });
    if (logo) fit(logo, 72, 1385, 200, 70);
    else brand(268, 1418, 0.6);

    // Outputs
    const jpeg = canvas.toDataURL('image/jpeg', 0.92);
    const out = document.getElementById('ticket-image');
    out.src = jpeg;
    await out.decode();
    out.hidden = false;
    canvas.hidden = true;
    const save = (href, name) => { const a = document.createElement('a'); a.href = href; a.download = name; document.body.appendChild(a); a.click(); a.remove(); };
    const pdfUrl = URL.createObjectURL(a5Pdf(jpeg, canvas.width, canvas.height));
    document.getElementById('ticket-pdf').onclick = () => save(pdfUrl, data.file + '.pdf');
    document.getElementById('ticket-jpg').onclick = () => save(jpeg, data.file + '.jpg');
    document.getElementById('ticket-print').onclick = () => window.print();
    ['ticket-pdf', 'ticket-jpg', 'ticket-print'].forEach((k) => (document.getElementById(k).disabled = false));
    status.textContent = 'رسید آماده است • اندازه A5';
  } catch (e) {
    status.textContent = 'آماده‌سازی رسید ممکن نشد؛ صفحه را دوباره باز کنید.';
  }

  /** One-page A5 PDF (419.53 × 595.28 pt) showing the JPEG full-page. */
  function a5Pdf(dataUrl, w, h) {
    const bin = atob(dataUrl.split(',')[1]);
    const img = new Uint8Array(bin.length);
    for (let i = 0; i < bin.length; i++) img[i] = bin.charCodeAt(i);
    const enc = new TextEncoder();
    const parts = [], offsets = [];
    let size = 0;
    const push = (x) => { const b = typeof x === 'string' ? enc.encode(x) : x; parts.push(b); size += b.length; };
    const pw = 419.53, ph = 595.28;
    const content = `q ${pw} 0 0 ${ph} 0 0 cm /Im0 Do Q`;
    push('%PDF-1.4\n%\xE2\xE3\xCF\xD3\n');
    const obj = (n, body) => { offsets[n] = size; push(`${n} 0 obj\n`); body(); push('\nendobj\n'); };
    obj(1, () => push('<< /Type /Catalog /Pages 2 0 R >>'));
    obj(2, () => push('<< /Type /Pages /Kids [3 0 R] /Count 1 >>'));
    obj(3, () => push(`<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ${pw} ${ph}] /Resources << /XObject << /Im0 5 0 R >> >> /Contents 4 0 R >>`));
    obj(4, () => push(`<< /Length ${content.length} >>\nstream\n${content}\nendstream`));
    obj(5, () => { push(`<< /Type /XObject /Subtype /Image /Width ${w} /Height ${h} /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length ${img.length} >>\nstream\n`); push(img); push('\nendstream'); });
    const xref = size;
    push('xref\n0 6\n0000000000 65535 f \n' + offsets.slice(1).map((o) => String(o).padStart(10, '0') + ' 00000 n \n').join(''));
    push(`trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n${xref}\n%%EOF`);
    return new Blob(parts, { type: 'application/pdf' });
  }
})();
