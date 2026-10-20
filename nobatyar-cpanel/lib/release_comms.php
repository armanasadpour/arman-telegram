<?php
declare(strict_types=1);
function admin_manual_sms(array $data): string {
    admin_require('communications');$recipient=field($data,'recipient',60);$body=field($data,'message',1000);$key=field($data,'request_key',40);
    if(!preg_match('/^[a-f0-9]{32}$/',$key)||!preg_match('/^(user|patient|doctor|center):([0-9]+)$/',$recipient,$m))throw new AuthError('گیرنده را از نتایج جستجو انتخاب کنید.');
    $row=sms_recipient($m[1],(int)$m[2]);if(!$row)throw new AuthError('گیرنده فعال پیدا نشد.');
    $tpl=sms_template('manual_message');if(!$tpl||!$tpl['enabled']||$tpl['delivery_mode']!=='text')throw new AuthError('الگوی «پیام دستی مدیر» را در الگوهای پیامک با روش متن دلخواه فعال کنید.');
    if(!sms_enabled()&&!sms_dev_mode())throw new AuthError('سرویس پیامک غیرفعال است.');
    throttle('admin_manual_sms',50,3600);
    $ok=sms_enqueue('manual_message',$row['mobile'],['token10'=>$row['name'],'token20'=>$body],'manual:'.admin_current()['id'].':'.$key);
    audit('sms.manual',(int)$m[2],$m[1]);return $ok?'پیام در صف ارسال قرار گرفت؛ نتیجه در گزارش پیامک قابل مشاهده است.':'پیام قبلاً در صف ثبت شده یا شماره گیرنده معتبر نیست.';
}
function sms_recipient(string $kind,int $id): ?array {
    $sql=match($kind){'doctor'=>'SELECT name,mobile FROM doctors WHERE id=?', 'center'=>'SELECT c.name,u.mobile FROM centers c JOIN users u ON u.id=c.owner_user_id WHERE c.id=? AND u.active=1', 'patient'=>"SELECT u.name,u.mobile FROM users u WHERE u.id=? AND u.active=1 AND EXISTS(SELECT 1 FROM user_roles r WHERE r.user_id=u.id AND r.role='patient')",default=>'SELECT name,mobile FROM users WHERE id=? AND active=1'};
    return query($sql,[$id])->fetch()?:null;
}
function admin_notice_events(): array {return ['registration'=>'درخواست عضویت پزشک یا کلینیک','booking'=>'ثبت و تغییر نوبت','payment'=>'پرداخت موفق','payout'=>'درخواست واریز','daily'=>'گزارش کارکرد روزانه'];}
function admin_notice_prefs(int $admin): array {
    $json=query('SELECT preferences FROM admin_notice_preferences WHERE admin_id=?',[$admin])->fetchColumn();
    return (is_string($json)?json_decode($json,true):[]) ?: array_fill_keys(array_keys(admin_notice_events()),1);
}
function admin_event(string $event,string $title,string $body,string $key,string $url='admin.php'): void {
    if(!push_available())return;
    foreach(query("SELECT id FROM admins WHERE active=1 AND role IN ('owner','manager')")->fetchAll(PDO::FETCH_COLUMN) as $aid){
        if(empty(admin_notice_prefs((int)$aid)[$event]))continue;
        foreach(query('SELECT id FROM admin_push_devices WHERE admin_id=? AND active=1',[$aid])->fetchAll(PDO::FETCH_COLUMN) as $sid){
            $payload=json_encode(['title'=>$title,'body'=>mb_substr($body,0,160),'url'=>$url,'tag'=>$key],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            query('INSERT IGNORE INTO admin_push_queue(device_id,event_key,payload) VALUES(?,?,?)',[$sid,mb_substr($event.':'.$key,0,120),$payload]);
        }
    }
}
function admin_push_dispatch(): string {
    if(!push_available())return 'اعلان مرورگر غیرفعال است.';
    $count=0;
    query("UPDATE admin_push_queue SET status=IF(attempts<3,'queued','failed') WHERE status='sending' AND next_at<NOW()");
    foreach(query("SELECT id FROM admin_push_queue WHERE status='queued' AND next_at<=NOW() ORDER BY id LIMIT 100")->fetchAll(PDO::FETCH_COLUMN) as $id){
        if(!query("UPDATE admin_push_queue SET status='sending',attempts=attempts+1,next_at=DATE_ADD(NOW(),INTERVAL 10 MINUTE) WHERE id=? AND status='queued'",[$id])->rowCount())continue;
        $m=query('SELECT q.*,d.endpoint,d.p256dh,d.auth,d.active,d.admin_id,a.role,a.active admin_active FROM admin_push_queue q JOIN admin_push_devices d ON d.id=q.device_id JOIN admins a ON a.id=d.admin_id WHERE q.id=?',[$id])->fetch();
        if(!$m||!$m['active']||!$m['admin_active']||!in_array($m['role'],['owner','manager'],true)){query("UPDATE admin_push_queue SET status='cancelled' WHERE id=?",[$id]);continue;}
        if(empty(admin_notice_prefs((int)$m['admin_id'])[explode(':',$m['event_key'],2)[0]])){query("UPDATE admin_push_queue SET status='cancelled' WHERE id=?",[$id]);continue;}
        try{$http=webpush_send($m,$m['payload']);}catch(Throwable $e){$http=0;}
        if($http>=200&&$http<300){query("UPDATE admin_push_queue SET status='sent' WHERE id=?",[$id]);$count++;}
        elseif(in_array($http,[404,410],true)){query('UPDATE admin_push_devices SET active=0 WHERE id=?',[$m['device_id']]);query("UPDATE admin_push_queue SET status='failed' WHERE id=?",[$id]);}
        elseif($m['attempts']<3 && ($http===0||$http===429||$http>=500))query("UPDATE admin_push_queue SET status='queued',next_at=DATE_ADD(NOW(),INTERVAL 5 MINUTE) WHERE id=?",[$id]);
        else query("UPDATE admin_push_queue SET status='failed' WHERE id=?",[$id]);
    }return fa_number($count).' اعلان مدیریتی ارسال شد.';
}
function platform_daily_summary(): string {
    if((int)date('H')<setting_int('platform_summary_hour',21,0,23))return 'هنوز زمان گزارش نشده است.';
    $day=date('Y-m-d');$next=date('Y-m-d',strtotime('+1 day'));
    $stats=query("SELECT COUNT(*) n,COALESCE(SUM(CASE WHEN status='verified' THEN amount_toman-vat_toman ELSE refund_penalty_toman END),0) amount FROM payment_transactions WHERE verified_at>=? AND verified_at<? AND test_mode=0 AND purpose<>'gateway_test' AND status IN ('verified','refunded')",[$day,$next])->fetch();
    $n=(int)query("SELECT COUNT(*) FROM bookings WHERE created_at>=? AND created_at<? AND status IN ('confirmed','completed','no_show')",[$day,$next])->fetchColumn();
    admin_event('daily','گزارش روزانه نوبت‌یار',fa_number($n).' نوبت قطعی · '.fa_number($stats['n']).' پرداخت موفق · درآمد خالص از مالیات '.toman($stats['amount']).' (تا ساعت '.date('H:i').')','daily:'.$day);
    return 'گزارش روزانه برای دستگاه‌های فعال در صف قرار گرفت.';
}
function panel_release_links(): void {
    $page=basename((string)($_SERVER['SCRIPT_NAME']??''));$u=user_now_safe();
    if(!$u||!in_array($page,['panel.php','patient.php','doctor-panel.php','center.php','wallet.php'],true))return;
    $url='wallet.php';
    if($page==='doctor-panel.php'){$id=(int)($_GET['doctor']??0);$d=$id?query('SELECT doctor_id id FROM doctor_accounts WHERE doctor_id=? AND user_id=?',[$id,$u['id']])->fetch():my_doctor((int)$u['id']);if($d)$url='wallet.php?type=doctor&owner='.(int)$d['id'];}
    if($page==='center.php'){$id=(int)($_GET['center']??0);$c=$id?query('SELECT id FROM centers WHERE id=? AND owner_user_id=?',[$id,$u['id']])->fetch():query('SELECT id FROM centers WHERE owner_user_id=? ORDER BY id LIMIT 1',[$u['id']])->fetch();if($c)$url='wallet.php?type=center&owner='.(int)$c['id'];}
    echo '<div class="panel-finance-links"><a class="button secondary small" href="'.esc($url).'">'.icon('wallet').' کیف پول و حساب بانکی</a> <a class="button secondary small" href="notifications.php">'.icon('bell').' تنظیم اعلان‌ها</a></div>';
    foreach(query("SELECT id,title,body,url FROM notifications WHERE user_id=? AND category='announcement' AND banner_dismissed=0 ORDER BY id DESC LIMIT 5",[$u['id']])->fetchAll() as $n){
        echo '<aside class="panel-announcement" role="status"><span class="pa-icon">'.icon('megaphone').'</span><div class="pa-body"><span class="pa-kicker">اطلاعیه</span><strong class="pa-title">'.esc($n['title']).'</strong><p>'.nl2br(esc($n['body'])).'</p>'.($n['url']?'<a class="pa-link" href="'.esc(safe_link($n['url'])).'">مشاهده جزئیات '.icon('arrow-left').'</a>':'').'</div><form class="pa-dismiss" method="post" action="notice-dismiss.php">';form_token('dismiss');echo '<input type="hidden" name="id" value="'.(int)$n['id'].'"><input type="hidden" name="back" value="'.esc($page.'?'.http_build_query(array_intersect_key($_GET,array_flip(['doctor','center','tab','type','owner'])))).'"><button class="pa-close" aria-label="بستن اطلاعیه" title="بستن">'.icon('x').'</button></form></aside>';
    }
}
