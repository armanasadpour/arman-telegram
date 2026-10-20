'use strict';
// Bank redirect page: submits the prepared POST form once. The visible button works without JavaScript.
document.addEventListener('DOMContentLoaded', () => {
  const form = document.querySelector('form[data-autosubmit]');
  if (form) form.submit();
});
