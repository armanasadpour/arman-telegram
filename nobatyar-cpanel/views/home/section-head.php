<?php /** @var string $title @var string $subtitle @var string $more @var string $moreLabel @var string $id */ ?>
<div class="sec-head">
  <div>
    <h2 id="<?= esc($id) ?>"><?= esc($title) ?></h2>
    <?php if ($subtitle !== ''): ?><p><?= esc($subtitle) ?></p><?php endif; ?>
  </div>
  <?php if ($more !== ''): ?><a class="sec-more" href="<?= esc($more) ?>"><?= esc($moreLabel) ?><?= icon('chevron-left') ?></a><?php endif; ?>
</div>
