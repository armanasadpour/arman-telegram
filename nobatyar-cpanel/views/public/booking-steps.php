<?php
/** Booking stepper: 1 sign-in, 2 patient, 3 confirm and pay. @var int $current */
$steps = [1 => 'ورود به حساب', 2 => 'اطلاعات مراجعه‌کننده', 3 => 'تأیید و پرداخت'];
?>
<ol class="stepper" aria-label="مراحل رزرو">
  <?php foreach ($steps as $n => $label): $state = $n < $current ? 'done' : ($n === $current ? 'current' : 'todo'); ?>
    <li class="step-<?= $state ?>"<?= $state === 'current' ? ' aria-current="step"' : '' ?>>
      <span class="step-dot" aria-hidden="true"><?= $state === 'done' ? icon('check') : '' ?></span>
      <span class="step-label"><?= esc($label) ?><?= $state === 'done' ? '<span class="sr-only"> (انجام شد)</span>' : '' ?></span>
    </li>
  <?php endforeach; ?>
</ol>
