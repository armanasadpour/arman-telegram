(function () {
	var start = document.getElementById('wpmo-start');
	if (!start) return;
	var stopBtn = document.getElementById('wpmo-stop');
	var bar = document.getElementById('wpmo-bar');
	var status = document.getElementById('wpmo-status');
	var log = document.getElementById('wpmo-log');
	var stopped = false;

	function post(data) {
		var body = new FormData();
		body.append('nonce', WPMO.nonce);
		Object.keys(data).forEach(function (k) { body.append(k, data[k]); });
		return fetch(WPMO.ajax, { method: 'POST', body: body, credentials: 'same-origin' })
			.then(function (r) { return r.json(); });
	}
	function line(t) {
		log.style.display = 'block';
		log.textContent += t + '\n';
		log.scrollTop = log.scrollHeight;
	}

	start.addEventListener('click', function () {
		if (!confirm('فایل‌های اصلی حذف خواهند شد. از سایت بکاپ گرفته‌اید؟')) return;
		stopped = false;
		start.disabled = true;
		stopBtn.disabled = false;
		log.textContent = '';
		status.textContent = 'در حال آماده‌سازی صف...';

		post({
			action: 'wpmo_queue',
			type: document.getElementById('wpmo-type').value,
			retry: document.getElementById('wpmo-retry').checked ? 1 : ''
		}).then(function (res) {
			if (!res.success) throw new Error(res.data || 'خطا');
			var ids = res.data.ids, i = 0, ok = 0, fail = 0;
			if (!ids.length) { status.textContent = 'موردی برای بهینه‌سازی وجود ندارد.'; return done(); }

			function next() {
				if (stopped || i >= ids.length) {
					status.textContent = (stopped ? 'متوقف شد. ' : 'تمام شد! ') + 'موفق: ' + ok + ' | خطا: ' + fail;
					return done();
				}
				var id = ids[i++];
				status.textContent = 'در حال پردازش ' + i + ' از ' + ids.length + ' (شناسه ' + id + ')...';
				post({ action: 'wpmo_process', id: id }).then(function (r) {
					if (r.success) {
						ok++;
						var d = r.data;
						line('✔ #' + d.id + ' ' + d.file + '  ' + d.old_h + ' → ' + d.new_h + (d.skipped ? '  (already optimal, kept)' : ''));
					} else {
						fail++;
						line('✘ ' + r.data);
					}
				}).catch(function (e) {
					fail++;
					line('✘ #' + id + ' ' + e.message);
				}).then(function () {
					bar.style.width = Math.round(i / ids.length * 100) + '%';
					next();
				});
			}
			next();
		}).catch(function (e) {
			status.textContent = 'خطا: ' + e.message;
			done();
		});
	});

	stopBtn.addEventListener('click', function () { stopped = true; stopBtn.disabled = true; });
	function done() { start.disabled = false; stopBtn.disabled = true; }
})();
