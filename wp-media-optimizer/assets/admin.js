(function () {
	'use strict';
	var root = document.querySelector('.wpmo');
	if (!root) return;

	var stopped = false;
	var lists = {};          // kind -> { ids: [], items: [] }
	var BATCH = { scan: 20, convert: 1, approve: 10, revert: 3, quarantine: 10, restore: 10, purge: 10 };
	var LABEL = { scan: 'بررسی', convert: 'تبدیل', approve: 'تایید', revert: 'بازگردانی', quarantine: 'قرنطینه', restore: 'بازگردانی', purge: 'حذف دائمی' };

	/* ---------------- helpers ---------------- */
	function post(data) {
		var body = new FormData();
		body.append('nonce', WPMO.nonce);
		Object.keys(data).forEach(function (k) {
			var v = data[k];
			if (Array.isArray(v)) v.forEach(function (x) { body.append(k + '[]', x); });
			else body.append(k, v);
		});
		return fetch(WPMO.ajax, { method: 'POST', body: body, credentials: 'same-origin' })
			.then(function (r) { return r.json(); })
			.then(function (r) { if (!r.success) throw new Error(r.data || 'خطا'); return r.data; });
	}
	function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }
	function tab(name) { return document.getElementById('wpmo-tab-' + name); }
	function ui(section) {
		return {
			bar: section.querySelector('.wpmo-progress'),
			status: section.querySelector('.wpmo-status'),
			log: section.querySelector('.wpmo-log')
		};
	}
	function logLine(u, t) { if (!u.log) return; u.log.style.display = 'block'; u.log.textContent += t + '\n'; u.log.scrollTop = u.log.scrollHeight; }
	function busy(on) {
		root.querySelectorAll('[data-run],[data-op]').forEach(function (b) { b.disabled = on; });
		root.querySelectorAll('[data-stop]').forEach(function (b) { b.disabled = !on; });
	}

	/** Process ids in batches with a progress bar. */
	function runBatches(op, ids, section, onResult) {
		var u = ui(section), i = 0, ok = 0, fail = 0, size = BATCH[op] || 5;
		stopped = false;
		busy(true);
		if (u.log) u.log.textContent = '';
		u.bar.style.display = 'block';
		u.bar.firstElementChild.style.width = '0';
		return new Promise(function (resolve) {
			function next() {
				if (stopped || i >= ids.length) {
					u.status.textContent = (stopped ? 'متوقف شد. ' : 'تمام شد. ') + LABEL[op] + ' — موفق: ' + ok + ' | ناموفق: ' + fail;
					busy(false);
					return resolve({ ok: ok, fail: fail });
				}
				var chunk = ids.slice(i, i + size);
				i += chunk.length;
				u.status.textContent = LABEL[op] + ': ' + i + ' از ' + ids.length + '...';
				post({ action: 'wpmo_batch', op: op, ids: chunk }).then(function (d) {
					d.results.forEach(function (r) {
						if (r.ok) ok++; else fail++;
						if (onResult) onResult(r, u);
						else logLine(u, (r.ok ? '✔ ' : '✘ ') + '#' + r.id + ' ' + (r.name || '') + (r.ok ? '' : ' — ' + r.msg));
					});
				}).catch(function (e) {
					fail += chunk.length;
					logLine(u, '✘ ' + chunk.join(',') + ' — ' + e.message);
				}).then(function () {
					u.bar.firstElementChild.style.width = Math.round(i / ids.length * 100) + '%';
					next();
				});
			}
			if (!ids.length) { u.status.textContent = 'موردی وجود ندارد.'; busy(false); return resolve({ ok: 0, fail: 0 }); }
			next();
		});
	}

	function refreshStats() {
		return post({ action: 'wpmo_stats' }).then(function (s) {
			var map = { saved_h: s.saved_h, unused: s.unused, convert: s.images + s.videos, approve: s.pending + s.quarantine, images: s.images, videos: s.videos, errors: s.errors };
			Object.keys(map).forEach(function (k) {
				root.querySelectorAll('[data-stat="' + k + '"]').forEach(function (el) { el.textContent = map[k]; });
			});
		}).catch(function () {});
	}

	/* ---------------- tables ---------------- */
	function thumb(it) {
		if (it.thumb) return '<img src="' + esc(it.thumb) + '" alt="" loading="lazy">';
		return '<div class="wpmo-ph">' + esc((it.mime || '').split('/')[0] === 'video' ? 'ویدیو' : (it.mime || '').split('/')[1] || '') + '</div>';
	}

	function renderList(kind) {
		var box = root.querySelector('[data-list="' + kind + '"]');
		if (!box) return;
		var data = lists[kind], t = box.querySelector('.wpmo-table');
		box.querySelector('.wpmo-count').textContent = '(' + data.total + (data.bytes_h ? ' — ' + data.bytes_h : '') + ')';
		box.querySelectorAll('[data-op]').forEach(function (b) { b.style.display = data.total ? '' : 'none'; });
		if (!data.total) { t.innerHTML = '<p class="wpmo-empty">موردی وجود ندارد.</p>'; return; }

		var head, rows;
		if (kind === 'unused') {
			head = '<th></th><th>فایل</th><th>نوع</th><th>حجم</th><th>تاریخ</th>';
			rows = data.items.map(function (it) {
				return '<td>' + thumb(it) + '</td><td><a href="' + esc(it.url) + '" target="_blank">' + esc(it.name) + '</a><br><small>#' + it.id + ' — ' + esc(it.title) + '</small></td>' +
					'<td>' + esc(it.mime) + '</td><td>' + esc(it.size_h) + ' <small>(' + it.files + ' فایل)</small></td><td>' + esc(it.date) + '</td>';
			});
		} else if (kind === 'pending') {
			head = '<th></th><th>فایل جدید</th><th>فایل اصلی</th><th>حجم قبل ← بعد</th><th>صرفه‌جویی</th>';
			rows = data.items.map(function (it) {
				return '<td>' + thumb(it) + '</td><td><a href="' + esc(it.url) + '" target="_blank">' + esc(it.name) + '</a><br><small>#' + it.id + (it.source === 'upload' ? ' — هنگام آپلود' : '') + '</small></td>' +
					'<td><a href="' + esc(it.orig_url) + '" target="_blank">' + esc(it.orig) + '</a></td>' +
					'<td dir="ltr" style="text-align:right">' + esc(it.old_h) + ' → ' + esc(it.new_h) + '</td><td class="wpmo-good">' + it.pct + '٪</td>';
			});
		} else {
			head = '<th></th><th>فایل</th><th>حجم</th><th>زمان انتقال</th>';
			rows = data.items.map(function (it) {
				return '<td>' + thumb(it) + '</td><td><a href="' + esc(it.orig_url) + '" target="_blank">' + esc(it.name) + '</a><br><small>#' + it.id + ' — ' + esc(it.title) + '</small></td>' +
					'<td>' + esc(it.size_h) + ' <small>(' + it.files + ' فایل)</small></td><td>' + esc(it.when) + '</td>';
			});
		}
		t.innerHTML = '<table class="widefat striped"><thead><tr><td class="check-column"><input type="checkbox" data-all></td>' + head + '</tr></thead><tbody>' +
			data.items.map(function (it, n) { return '<tr><th class="check-column"><input type="checkbox" value="' + it.id + '"></th>' + rows[n] + '</tr>'; }).join('') +
			'</tbody></table>' + (data.total > data.items.length ? '<p class="wpmo-more">' + data.items.length + ' مورد از ' + data.total + ' نمایش داده شده؛ دکمه‌های «همه» روی همه موارد اعمال می‌شوند.</p>' : '');
		t.querySelector('[data-all]').addEventListener('change', function (e) {
			t.querySelectorAll('tbody input').forEach(function (c) { c.checked = e.target.checked; });
		});
	}

	function loadList(kind) {
		var box = root.querySelector('[data-list="' + kind + '"]');
		if (!box) return Promise.resolve();
		box.querySelector('.wpmo-table').innerHTML = '<p class="wpmo-empty">در حال بارگذاری...</p>';
		return post({ action: 'wpmo_list', kind: kind }).then(function (d) {
			lists[kind] = d;
			renderList(kind);
		}).catch(function (e) { box.querySelector('.wpmo-table').innerHTML = '<p class="wpmo-empty">خطا: ' + esc(e.message) + '</p>'; });
	}

	/* ---------------- tabs ---------------- */
	function showTab(name) {
		if (!tab(name)) name = 'scan';
		root.querySelectorAll('.wpmo-steps a').forEach(function (a) { a.classList.toggle('active', a.dataset.tab === name); });
		root.querySelectorAll('.wpmo-tab').forEach(function (s) { s.classList.toggle('active', s.id === 'wpmo-tab-' + name); });
		if (name === 'scan') loadList('unused');
		if (name === 'approve') { loadList('pending'); loadList('quarantine'); }
	}
	root.querySelectorAll('.wpmo-steps a').forEach(function (a) {
		a.addEventListener('click', function (e) { e.preventDefault(); history.replaceState(null, '', '#' + a.dataset.tab); showTab(a.dataset.tab); });
	});

	/* ---------------- step runners ---------------- */
	root.querySelectorAll('[data-stop]').forEach(function (b) {
		b.disabled = true;
		b.addEventListener('click', function () { stopped = true; b.disabled = true; });
	});

	root.querySelector('[data-run="scan"]').addEventListener('click', function () {
		var section = tab('scan'), unused = 0;
		post({ action: 'wpmo_ids', kind: 'scan' }).then(function (d) {
			return runBatches('scan', d.ids, section, function (r) { if (r.ok && !r.used) unused++; });
		}).then(function (res) {
			ui(section).status.textContent += ' — بدون استفاده: ' + unused;
			refreshStats();
			loadList('unused');
		}).catch(function (e) { ui(section).status.textContent = 'خطا: ' + e.message; busy(false); });
	});

	root.querySelector('[data-run="convert"]').addEventListener('click', function () {
		var section = tab('convert');
		post({
			action: 'wpmo_ids', kind: 'convert',
			type: document.getElementById('wpmo-type').value,
			skip_unused: document.getElementById('wpmo-skip-unused').checked ? 1 : '',
			retry: document.getElementById('wpmo-retry').checked ? 1 : ''
		}).then(function (d) {
			return runBatches('convert', d.ids, section, function (r, u) {
				if (r.ok) logLine(u, '✔ #' + r.id + ' ' + r.name + '  ' + r.old_h + ' → ' + r.new_h + (r.skipped ? '  (already optimal, kept)' : ''));
				else logLine(u, '✘ #' + r.id + ' ' + (r.name || '') + ' — ' + r.msg);
			});
		}).then(function (res) {
			refreshStats();
			if (res.ok) ui(section).status.innerHTML += ' — <a href="#approve" data-goto="approve">رفتن به مرحله ۳ برای تایید یا بازگردانی ←</a>';
		}).catch(function (e) { ui(section).status.textContent = 'خطا: ' + e.message; busy(false); });
	});

	root.addEventListener('click', function (e) {
		var g = e.target.closest('[data-goto]');
		if (g) { e.preventDefault(); history.replaceState(null, '', '#' + g.dataset.goto); showTab(g.dataset.goto); }
	});

	var CONFIRM = {
		quarantine: 'فایل‌ها از سایت برداشته و به قرنطینه منتقل می‌شوند (قابل بازگشت). ادامه می‌دهید؟',
		approve: 'فایل‌های اصلی برای همیشه حذف می‌شوند. ادامه می‌دهید؟',
		revert: 'فایل‌های اصلی و لینک‌ها به حالت قبل برمی‌گردند و فایل‌های تبدیل‌شده حذف می‌شوند. ادامه می‌دهید؟',
		restore: 'فایل‌ها به سایت و کتابخانه رسانه بازگردانده می‌شوند. ادامه می‌دهید؟',
		purge: 'فایل‌ها و رکوردهای رسانه برای همیشه حذف می‌شوند و قابل بازگشت نیستند. مطمئن هستید؟'
	};

	root.querySelectorAll('[data-op]').forEach(function (btn) {
		btn.addEventListener('click', function () {
			var op = btn.dataset.op, box = btn.closest('[data-list]'), kind = box.dataset.list, ids;
			if (btn.dataset.scope === 'all') ids = (lists[kind] && lists[kind].ids) || [];
			else ids = Array.prototype.map.call(box.querySelectorAll('tbody input:checked'), function (c) { return +c.value; });
			if (!ids.length) { alert('موردی انتخاب نشده است.'); return; }
			if (!confirm(CONFIRM[op] + '\n\n(' + ids.length + ' مورد)')) return;
			if (op === 'purge' && btn.dataset.scope === 'all' && !confirm('تایید نهایی: حذف دائمی ' + ids.length + ' فایل؟')) return;
			var section = btn.closest('.wpmo-tab');
			runBatches(op, ids, section).then(function () {
				refreshStats();
				if (kind === 'unused') loadList('unused');
				else { loadList('pending'); loadList('quarantine'); }
			});
		});
	});

	showTab((location.hash || '#scan').slice(1));
})();
