'use strict';
(() => {
  const form = document.querySelector('[data-doctor-wizard]');
  if (!form) return;
  const root = form.closest('.dr-wizard'), steps = [...form.querySelectorAll('[data-step]')];
  const prev = form.querySelector('[data-prev]'), next = form.querySelector('[data-next]'), submit = form.querySelector('[data-submit]');
  const error = form.querySelector('[data-wizard-error]'), review = form.querySelector('[data-review]');
  const fa = n => new Intl.NumberFormat('fa-IR', {useGrouping: false}).format(n);
  const isCenter = form.dataset.registrationKind === 'center';
  let current = 0, previewUrl;
  const field = name => form.elements.namedItem(name);
  const value = name => field(name)?.value?.trim() || '';
  const selected = name => {const f = field(name); return f?.value ? f.selectedOptions?.[0]?.textContent || f.value : 'ثبت نشده';};
  const validateExtra = () => {
    if (isCenter) return;
    const name = field('name'), other = field('specialty_other');
    name.setCustomValidity(/^\s*دکتر(?:\s|‌|$)/u.test(name.value) ? 'نام را بدون «دکتر» وارد کنید؛ عنوان به صورت خودکار اضافه می‌شود.' : '');
    other.setCustomValidity(!value('specialty_id') && !value('specialty_other') ? 'تخصص را انتخاب کنید یا نام آن را بنویسید.' : '');
  };
  const updateReview = () => {
    review.replaceChildren(); review.hidden = false;
    const rows = isCenter ? [['مرکز',value('center_name')],['نوع',selected('kind')],['تلفن',value('phone')],['شهر',selected('city_id')],['نشانی',value('address')],['مسئول',value('manager_name')],['پروانه',value('license_number')]] : [['پزشک', 'دکتر ' + value('name')], ['تخصص', value('specialty_other') || selected('specialty_id')], ['نظام پزشکی', value('medical_code')], ['شماره همراه', value('mobile')], ['مطب', value('clinic_name')], ['شهر', selected('city_id')], ['آدرس', ['street_main', 'street_side', 'address_extra'].map(value).filter(Boolean).join('، ')], ['نسخه انتخابی', value('plan_choice') === 'pro' ? 'پرو؛ فعال‌سازی پس از تأیید' : 'رایگان']];
    rows.forEach(([label, text]) => {const row = document.createElement('div'), key = document.createElement('span'), val = document.createElement('strong');row.className='dr-review-row';key.textContent=label;val.textContent=text;row.append(key,val);review.append(row);});
  };
  const show = (index, focus = true) => {
    current = index; steps.forEach((step, i) => {step.hidden = i !== current;});
    prev.hidden = current === 0; next.hidden = current === steps.length - 1; submit.hidden = !next.hidden;
    const bar = root.querySelector('[role=progressbar]');bar.setAttribute('aria-valuenow',String(current+1));bar.firstElementChild.style.width = ((current+1)/steps.length*100)+'%';
    root.querySelector('[data-step-caption]').textContent = 'مرحله '+fa(current+1)+' از '+fa(steps.length)+' · '+steps[current].dataset.step;
    error.hidden = true;
    if (next.hidden) updateReview();
    if (focus) {steps[current].querySelector('h1,h2').focus({preventScroll:true});root.scrollIntoView({block:'start',behavior:'instant'});}
  };
  const validStep = index => {
    validateExtra();
    const busyCity = steps[index].querySelector('select[name=city_id]:disabled');
    if(busyCity){show(index);error.textContent='لطفاً تا دریافت فهرست شهرها صبر کنید.';error.hidden=false;return false;}
    const invalid = [...steps[index].querySelectorAll('input,select,textarea')].find(f => f.willValidate && !f.validity.valid);
    if(invalid){show(index);invalid.focus();invalid.reportValidity();return false;}return true;
  };
  next.addEventListener('click', () => {if(validStep(current)) show(current+1);});
  prev.addEventListener('click', () => show(current-1));
  form.addEventListener('input', validateExtra);
  form.addEventListener('change', validateExtra);
  field('photo')?.addEventListener('change', () => {
    const input=field('photo'), file=input.files[0], image=root.querySelector('[data-photo-preview]');
    input.setCustomValidity('');image.hidden=true;if(previewUrl) URL.revokeObjectURL(previewUrl);
    if(!file)return;
    if(!['image/jpeg','image/png'].includes(file.type) || file.size > 3*1024*1024){input.setCustomValidity('تصویر باید JPG یا PNG و حداکثر ۳ مگابایت باشد.');input.reportValidity();return;}
    previewUrl=URL.createObjectURL(file);image.src=previewUrl;image.hidden=false;
  });
  form.addEventListener('submit', e => {
    if(current < steps.length-1){e.preventDefault();if(validStep(current))show(current+1);return;}
    for(let i=0;i<steps.length;i++) if(!validStep(i)){e.preventDefault();return;}
    submit.disabled=true;submit.textContent='در حال ثبت درخواست…';
  });
  window.addEventListener('pageshow', () => {submit.disabled=false;submit.textContent='ثبت درخواست بررسی';});
  // Keep the full list accessible without JavaScript; compact it only after enhancement.
  form.querySelectorAll('.plan-list').forEach(list => {const details=document.createElement('details'), summary=document.createElement('summary');summary.textContent='مشاهده امکانات نسخه';list.before(details);details.append(summary,list);});
  form.querySelectorAll('[data-center-image]').forEach(input => {
    let url;
    input.addEventListener('change',()=>{const file=input.files[0],image=input.closest('label').querySelector('[data-center-preview]'),max=input.name==='logo'?2:4;input.setCustomValidity('');image.hidden=true;if(url)URL.revokeObjectURL(url);if(!file)return;if(!['image/jpeg','image/png','image/webp'].includes(file.type)||file.size>max*1024*1024){input.setCustomValidity('تصویر باید JPG، PNG یا WebP و حداکثر '+fa(max)+' مگابایت باشد.');input.reportValidity();return;}url=URL.createObjectURL(file);image.src=url;image.hidden=false;});
  });
  form.noValidate=true;root.classList.add('dr-enhanced');root.querySelector('[data-progress]').hidden=false;
  show(0,false);
})();
