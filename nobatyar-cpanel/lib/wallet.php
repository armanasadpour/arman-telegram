<?php
declare(strict_types=1);
/** All amounts are integer toman. Writes require a transaction; ledger rows are never updated/deleted. */
function wallet_account(string $type, int $owner): array {
    if (!in_array($type,['user','doctor','center'],true) || $owner<1) throw new AuthError('کیف پول نامعتبر است.');
    query('INSERT IGNORE INTO wallets(owner_type,owner_id) VALUES(?,?)',[$type,$owner]);
    return query('SELECT * FROM wallets WHERE owner_type=? AND owner_id=?',[$type,$owner])->fetch();
}
function wallet_authorize(string $type,int $owner): array {
    $u=require_user(); $ok=false;
    if($type==='user') $ok=$owner===(int)$u['id'];
    if($type==='doctor') $ok=(bool)query('SELECT 1 FROM doctor_accounts WHERE doctor_id=? AND user_id=?',[$owner,$u['id']])->fetch();
    if($type==='center') $ok=(bool)query('SELECT 1 FROM centers WHERE id=? AND owner_user_id=?',[$owner,$u['id']])->fetch();
    if(!$ok) throw new AuthError('به این کیف پول دسترسی ندارید.',403);
    return wallet_account($type,$owner);
}
function wallet_post(int $wallet,int $amount,string $key,string $description): int {
    if(!db()->inTransaction()) throw new LogicException('Wallet posting requires a transaction.');
    if($amount===0 || strlen($key)>120) throw new LogicException('Invalid wallet entry.');
    $w=query('SELECT * FROM wallets WHERE id=? FOR UPDATE',[$wallet])->fetch();
    if(!$w) throw new AuthError('کیف پول یافت نشد.');
    $existing=query('SELECT * FROM wallet_entries WHERE operation_key=?',[$key])->fetch();
    if($existing) {
        if((int)$existing['wallet_id']!==$wallet || (int)$existing['amount']!==$amount) throw new LogicException('Wallet idempotency mismatch.');
        return (int)$existing['id'];
    }
    $balance=(int)$w['balance']+$amount;
    if($balance<0) throw new AuthError('موجودی کیف پول کافی نیست. برای پرداخت، گزینه کیف پول را خاموش کنید.');
    if($balance>1000000000000) throw new AuthError('سقف موجودی مجاز نیست.');
    query('UPDATE wallets SET balance=? WHERE id=?',[$balance,$wallet]);
    query('INSERT INTO wallet_entries(wallet_id,amount,balance_after,operation_key,description) VALUES(?,?,?,?,?)',[$wallet,$amount,$balance,$key,mb_substr($description,0,255)]);
    return (int)db()->lastInsertId();
}
function wallet_bank(int $wallet): ?array {
    $row=query('SELECT details FROM wallet_banks WHERE wallet_id=?',[$wallet])->fetchColumn();
    return $row ? json_decode(decrypt_secret($row),true,512,JSON_THROW_ON_ERROR) : null;
}
function bank_details_validate(array $data): array {
    $holder=field($data,'holder',190);$account=digits(field($data,'account',30));$card=digits(field($data,'card',16));
    $iban=strtoupper(preg_replace('/\s+/u','',digits(field($data,'iban',34))));
    if(!preg_match('/^[0-9]{5,30}$/',$account) || !preg_match('/^[0-9]{16}$/',$card) || count(array_unique(str_split($card)))<2 || !preg_match('/^IR[0-9]{24}$/',$iban)) throw new AuthError('شماره حساب، کارت ۱۶ رقمی و شبای IR با ۲۴ رقم را کامل وارد کنید.');
    $sum=0;for($i=0;$i<16;$i++){ $n=(int)$card[$i]*($i%2===0?2:1);$sum+=$n>9?$n-9:$n; }
    $rem=0;foreach(str_split(substr($iban,4).'1827'.substr($iban,2,2)) as $c)$rem=($rem*10+(int)$c)%97;
    if($sum%10!==0 || $rem!==1) throw new AuthError('شماره کارت یا شبا از نظر ساختار معتبر نیست.');
    return compact('holder','account','card','iban');
}
function wallet_save_bank(array $w,array $data): void {
    $bank=bank_details_validate($data);
    query('INSERT INTO wallet_banks(wallet_id,details) VALUES(?,?) ON DUPLICATE KEY UPDATE details=VALUES(details),updated_at=NOW()',[$w['id'],encrypt_secret(json_encode($bank,JSON_UNESCAPED_UNICODE))]);
    audit('wallet.bank',(int)$w['id']);
}
function wallet_request_payout(array $w,array $data): void {
    $amount=number_range($data,'amount',1000,1000000000);$key=field($data,'request_key',80);
    if(!preg_match('/^[a-f0-9]{32}$/',$key))throw new AuthError('فرم نامعتبر است؛ صفحه را تازه کنید.');
    db_transaction(function()use($w,$amount,$key){
        query('SELECT id FROM wallets WHERE id=? FOR UPDATE',[$w['id']]);
        $bank=wallet_bank((int)$w['id']);if(!$bank)throw new AuthError('ابتدا مشخصات کامل بانکی خود را ثبت کنید.');bank_details_validate($bank);
        $old=query('SELECT * FROM wallet_payouts WHERE request_key=?',[$key])->fetch();
        if($old){if((int)$old['wallet_id']!==(int)$w['id'] || (int)$old['amount']!==$amount)throw new AuthError('درخواست تکراری نامعتبر است.');return;}
        query('INSERT INTO wallet_payouts(wallet_id,amount,request_key,bank_snapshot) VALUES(?,?,?,?)',[$w['id'],$amount,$key,encrypt_secret(json_encode($bank,JSON_UNESCAPED_UNICODE))]);
        $id=(int)db()->lastInsertId();wallet_post((int)$w['id'],-$amount,'payout:'.$id,'رزرو وجه درخواست واریز شماره '.$id);
        audit('wallet.payout_requested',$id); admin_event('payout','درخواست واریز جدید',toman($amount).' برای بررسی ثبت شد.','payout:'.$id,'admin.php?tab=wallets');
    });
}
function wallet_payout_handle(array $data): string {
    admin_require('finance');$id=positive_id($data['id']??0);$status=field($data,'status',16);$reference=field($data,'reference',120,false);$note=field($data,'note',500,false);
    if(!in_array($status,['paid','rejected'],true) || ($status==='paid'&&$reference==='') || ($status==='rejected'&&mb_strlen($note)<3))throw new AuthError('برای پرداخت مرجع بانکی و برای رد علت را وارد کنید.');
    db_transaction(function()use($id,$status,$reference,$note){
        $p=query('SELECT * FROM wallet_payouts WHERE id=? FOR UPDATE',[$id])->fetch();if(!$p||$p['status']!=='pending')throw new AuthError('درخواست قبلاً رسیدگی شده است.',409);
        if($status==='rejected')wallet_post((int)$p['wallet_id'],(int)$p['amount'],'payout-reject:'.$id,'بازگشت درخواست واریز ردشده');
        query('UPDATE wallet_payouts SET status=?,reference=?,note=?,handled_by=?,handled_at=NOW() WHERE id=?',[$status,$reference,$note,admin_current()['id'],$id]);
        audit('wallet.payout_'.$status,$id);
    });return 'وضعیت درخواست واریز ثبت شد.';
}
function wallet_toggle(string $type,int $owner): void {
    $w=wallet_account($type,$owner);
    echo '<label class="wallet-toggle"><input type="checkbox" name="use_wallet" value="1" data-wallet-toggle role="switch"><span>پرداخت با کیف پول<br><small>موجودی: '.esc(toman($w['balance'])).' · پرداخت کامل از موجودی</small></span></label>';
}
function wallet_transaction(string $purpose,int $ref,int $amount,int $vat,int $user,?int $doctor): array {
    $code='W'.bin2hex(random_bytes(10));
    query("INSERT INTO payment_transactions(purpose,reference_id,gateway,amount_toman,vat_toman,order_code,user_id,doctor_id,description,status,ref_num,verified_at) VALUES(?,?,'wallet',?,?,?,?,?,'پرداخت از کیف پول','verified',?,NOW())",[$purpose,$ref,$amount,$vat,$code,$user,$doctor,$code]);
    return query('SELECT * FROM payment_transactions WHERE id=?',[(int)db()->lastInsertId()])->fetch();
}
function wallet_pay_booking(int $id): void {
    $old=own_booking($id);
    db_transaction(function()use($old,$id){
        lock_doctor((int)$old['doctor_id']);lock_contact($old['phone']);query('SELECT id FROM users WHERE id=? FOR UPDATE',[$old['patient_user_id']]);
        $b=own_booking($id);if(!hold_still_valid($b))throw new AuthError('مهلت رزرو تمام شده یا نوبت قبلاً ثبت شده است.');
        wallet_no_bank_payment('booking',$id);
        $percent=vat_percent();$vat=vat_amount((int)$b['fee_toman'],$percent);$amount=(int)$b['fee_toman']+$vat;
        if($amount<1)throw new AuthError('این رزرو رایگان است؛ از تأیید رزرو استفاده کنید.');
        $w=wallet_authorize('user',(int)$b['patient_user_id']);
        wallet_post((int)$w['id'],-$amount,'booking-pay:'.$id,'پرداخت کارمزد نوبت شماره '.$id);
        query('UPDATE bookings SET vat_percent=?,vat_toman=? WHERE id=?',[$percent,$vat,$id]);
        $tx=wallet_transaction('booking',$id,$amount,$vat,(int)$b['patient_user_id'],(int)$b['doctor_id']);payment_fulfil_booking($tx);
    });
}
function wallet_no_bank_payment(string $purpose,int $id): void {
    if(query("SELECT id FROM payment_transactions WHERE purpose=? AND reference_id=? AND status IN ('initiated','redirected','verified','refund_due','refunded') LIMIT 1 FOR UPDATE",[$purpose,$id])->fetch())throw new AuthError('برای این مورد پرداخت ثبت شده یا در جریان است؛ ابتدا نتیجه آن را مشخص کنید.');
    if($purpose==='booking' && query("SELECT id FROM payment_intents WHERE booking_id=? AND status NOT IN ('failed','cancelled') LIMIT 1",[$id])->fetch())throw new AuthError('نتیجه پرداخت قبلی نوبت باید ابتدا مشخص شود.');
}
function wallet_pay_subscription(int $id,int $doctor): void {
    subscriptions_require_on();$w=wallet_authorize('doctor',$doctor);
    db_transaction(function()use($id,$doctor,$w){
        lock_doctor($doctor);$o=query('SELECT * FROM subscription_orders WHERE id=? FOR UPDATE',[$id])->fetch();
        if(!$o||(int)$o['doctor_id']!==$doctor||$o['status']!=='proforma'||$o['expires_at']<=date('Y-m-d H:i:s'))throw new AuthError('پیش‌فاکتور قابل پرداخت نیست.');
        wallet_no_bank_payment('subscription',$id);$amount=(int)$o['payable_toman'];
        if($amount>0){wallet_post((int)$w['id'],-$amount,'subscription-pay:'.$id,'پرداخت اشتراک پرو '.$o['number']);$tx=wallet_transaction('subscription',$id,$amount,(int)$o['vat_toman'],(int)require_user()['id'],$doctor);}
        subscription_activate($id,$amount>0?'wallet':'free',$tx['id']??null);
    });
}
/** Called while cancelling under the booking's doctor/user locks. */
function wallet_refund_booking(int $id,string $reason,bool $force): bool {
    if(!db()->inTransaction())throw new LogicException('Wallet refunds require a transaction.');
    $b=booking_row($id);$policy=setting('wallet_refund_enabled','0')==='1';
    $walletPaid=(bool)query("SELECT id FROM payment_transactions WHERE purpose='booking' AND reference_id=? AND gateway='wallet' AND status='verified'",[$id])->fetch();
    if(!$policy && !$walletPaid)return false;
    if(query('SELECT booking_id FROM wallet_booking_refunds WHERE booking_id=?',[$id])->fetch())return true;
    $hours=setting_int('wallet_refund_hours',24,0,720);$pct=(float)setting('wallet_refund_penalty','0');
    if(!$policy){$pct=0;$hours=0;if(!$force && setting('booking_fee_refund_on_cancel','1')!=='1')return true;}
    if(!$force && strtotime($b['starts_at'])-time()<$hours*3600)return true;
    $txs=query("SELECT * FROM payment_transactions WHERE purpose='booking' AND reference_id=? AND status='verified' AND test_mode=0 FOR UPDATE",[$id])->fetchAll();
    // Legacy bank payments stay in the existing manual refund workflow.
    if(!$txs)return false;
    $gross=0;foreach($txs as $tx)$gross+=(int)$tx['amount_toman'];
    $penalty=$force?0:(int)round(max(0,(int)$b['fee_toman']-(int)$b['reminder_fee_toman'])*$pct/100);
    $penalty=min($gross,$penalty);$refund=$gross-$penalty;
    $w=wallet_account('user',(int)$b['patient_user_id']);
    query('INSERT INTO wallet_booking_refunds(booking_id,gross,penalty,refunded,rule_hours,penalty_percent) VALUES(?,?,?,?,?,?)',[$id,$gross,$penalty,$refund,$hours,$force?0:$pct]);
    if($refund>0)wallet_post((int)$w['id'],$refund,'booking-refund:'.$id,'عودت کارمزد لغو نوبت '.$id.'؛ جریمه '.toman($penalty));
    // The deduction belongs to one original transaction; all others are returned in full.
    $remainingPenalty=$penalty;
    foreach($txs as $tx){$cut=min((int)$tx['amount_toman'],$remainingPenalty);$remainingPenalty-=$cut;
        query("INSERT INTO refunds(tx_id,purpose,reference_id,amount_toman,reason,status,method,bank_reference,handled_at) VALUES(?,'booking',?,?,?,'done','wallet',?,NOW())",[$tx['id'],$id,(int)$tx['amount_toman']-$cut,mb_substr($reason,0,255),'WREF-'.$id]);
        query("UPDATE payment_transactions SET status='refunded',refund_penalty_toman=? WHERE id=?",[$cut,$tx['id']]);
    }
    query("UPDATE payments SET status='refunded' WHERE booking_id=? AND status='verified'",[$id]);
    notify_user((int)$b['patient_user_id'],'بازگشت وجه به کیف پول',toman($refund).' بابت لغو نوبت به کیف پول شما اضافه شد. جریمه: '.toman($penalty),$id,'general','wallet.php');
    return true;
}
function wallet_refund_settings(array $data): string {
    admin_require('finance');
    settings_save(['wallet_refund_enabled'=>!empty($data['enabled'])?'1':'0','wallet_refund_hours'=>(string)number_range($data,'hours',0,720),'wallet_refund_penalty'=>(string)number_range($data,'penalty',0,100)]);
    audit('wallet.refund_policy');return 'قانون عودت وجه ذخیره شد.';
}

function wallet_pay_center_subscription(int $id,int $center): void {
    subscriptions_require_on();center_actor($center,'billing');$w=wallet_authorize('center',$center);
    db_transaction(function()use($id,$center,$w){
        $o=query('SELECT * FROM center_orders WHERE id=? FOR UPDATE',[$id])->fetch();
        if(!$o||(int)$o['center_id']!==$center||$o['status']!=='proforma'||$o['expires_at']<=date('Y-m-d H:i:s'))throw new AuthError('پیش‌فاکتور قابل پرداخت نیست.');
        wallet_no_bank_payment('center_subscription',$id);$amount=(int)$o['payable_toman'];
        if($amount>0){wallet_post((int)$w['id'],-$amount,'center-subscription-pay:'.$id,'پرداخت اشتراک مرکز شماره '.$id);$tx=wallet_transaction('center_subscription',$id,$amount,(int)$o['vat_toman'],(int)require_user()['id'],null);}
        center_subscription_activate($id,$amount>0?'wallet':'free',$tx['id']??null);
    });
}
