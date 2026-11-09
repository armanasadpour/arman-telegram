<?php use function PM\Support\e; $title = $name; $nav = 'home'; $bodyWorker = $asset('e2ee-worker.js'); include __DIR__ . '/../_head.php'; ?>
<?php include __DIR__ . '/_nav.php'; ?>
<div id="app" data-ready="0"><div class="chat-status"><div class="panel"><p>در حال آماده‌سازی رمزنگاری روی همین دستگاه…</p></div></div></div>
<noscript><div class="page"><div class="alert error">برای رمزنگاری سرتاسری، JavaScript باید فعال باشد.</div></div></noscript>
<script type="module" src="<?= e($asset('chat.js')) ?>"></script>
</body></html>
