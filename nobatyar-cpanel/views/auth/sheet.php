<?php
/**
 * Sign-in sheet for guests: slides up from the bottom on phones, a centered dialog on wider screens.
 * Opened by links marked data-auth-open / data-auth-required (assets/shell.js). Each link still works
 * without JavaScript by going to login.php.
 *
 * @var bool $available @var ?array $signup
 */
?>
<div class="sheet-backdrop" data-auth-backdrop hidden></div>
<div class="auth-sheet" role="dialog" aria-modal="true" aria-labelledby="as-title" data-auth-sheet hidden>
  <span class="sheet-grip" aria-hidden="true"></span>
  <button type="button" class="sheet-close" data-auth-close aria-label="بستن"><?= icon('x') ?></button>
  <?php render('auth/form', [
      'step' => $signup ? 'profile' : 'phone',
      'mobile' => (string) ($signup['mobile'] ?? ''),
      'error' => '',
      'resendIn' => 0,
      'available' => $available,
      'next' => '',
      'idp' => 'as-',
  ]); ?>
  <p class="sheet-status" data-auth-status role="status" aria-live="polite"></p>
</div>
