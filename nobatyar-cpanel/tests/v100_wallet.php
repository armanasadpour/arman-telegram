<?php
declare(strict_types=1);
/** Isolated business-logic tests. SQLite adapter does NOT validate MySQL locks/migrations. */
if(PHP_SAPI!=='cli')exit;
class AuthError extends RuntimeException {}
$pdo=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$pdo->sqliteCreateFunction('NOW',fn()=>date('Y-m-d H:i:s'));
function db(): PDO {return $GLOBALS['pdo'];}
function query(string $sql,array $args=[]): PDOStatement {
    $sql=str_replace(['INSERT IGNORE',' FOR UPDATE'],['INSERT OR IGNORE',''],$sql);
    $q=db()->prepare($sql);$q->execute($args);return $q;
}
function db_transaction(callable $f): mixed {db()->beginTransaction();try{$v=$f();db()->commit();return $v;}catch(Throwable $e){db()->rollBack();throw $e;}}
function require_user(): array {return ['id'=>1];}
function field(array $d,string $k,int $max=190,bool $required=true): string {$v=trim((string)($d[$k]??''));if(($required&&$v==='')||mb_strlen($v)>$max)throw new AuthError('invalid field');return $v;}
function digits(string $s): string {return strtr($s,array_combine(preg_split('//u','۰۱۲۳۴۵۶۷۸۹',-1,PREG_SPLIT_NO_EMPTY),str_split('0123456789')));}
function number_range(array $d,string $k,int $min,int $max): int {$n=filter_var($d[$k]??null,FILTER_VALIDATE_INT);if($n===false||$n<$min||$n>$max)throw new AuthError('range');return $n;}
function positive_id($n): int {if((int)$n<1)throw new AuthError('id');return (int)$n;}
function encrypt_secret(string $s): string {return base64_encode($s);}
function decrypt_secret(string $s): string {return base64_decode($s);}
function audit(...$a): void {}
function admin_event(...$a): void {}
function notify_user(...$a): void {}
function admin_require(string $p): void {if(empty($GLOBALS['isAdmin']))throw new AuthError('denied');}
function admin_current(): array {return ['id'=>1];}
function toman($n): string {return (string)$n;}
function setting(string $k,string $default=''): string {return (string)($GLOBALS['settings'][$k]??$default);}
function setting_int(string $k,int $default,int $min,int $max): int {return max($min,min($max,(int)setting($k,(string)$default)));}
function booking_row(int $id): array {return query('SELECT * FROM bookings WHERE id=?',[$id])->fetch();}
function own_booking(int $id): array {$b=booking_row($id);if((int)$b['patient_user_id']!==1)throw new AuthError('denied');return $b;}
function lock_doctor(int $id): void {}
function lock_contact(string $p): void {}
function hold_still_valid(array $b): bool {return $b['status']==='held';}
function vat_percent(): float {return 10;}
function vat_amount(int $amount,float $pct): int {return (int)round($amount*$pct/100);}
function payment_fulfil_booking(array $tx): void {if(!empty($GLOBALS['failFulfil']))throw new AuthError('expired');query("UPDATE bookings SET status='confirmed' WHERE id=?",[$tx['reference_id']]);}
function subscriptions_require_on(): void {}
function subscription_activate(int $id,string $method,?int $tx): void {query("UPDATE subscription_orders SET status='paid',method=? WHERE id=?",[$method,$id]);}
function site_name(): string {return 'نوبت‌یار';}
function app_base_url(): string {return 'https://example.invalid/';}
require __DIR__.'/../lib/wallet.php';
require __DIR__.'/../lib/sms.php';
require __DIR__.'/../lib/billing.php';
foreach([
 'CREATE TABLE wallets(id INTEGER PRIMARY KEY,owner_type TEXT,owner_id INT,balance INT DEFAULT 0,UNIQUE(owner_type,owner_id))',
 'CREATE TABLE wallet_entries(id INTEGER PRIMARY KEY,wallet_id INT,amount INT,balance_after INT,operation_key TEXT UNIQUE,description TEXT)',
 'CREATE TABLE wallet_banks(wallet_id INT PRIMARY KEY,details TEXT)',
 "CREATE TABLE wallet_payouts(id INTEGER PRIMARY KEY,wallet_id INT,amount INT,request_key TEXT UNIQUE,bank_snapshot TEXT,status TEXT DEFAULT 'pending',reference TEXT,note TEXT,handled_by INT,handled_at TEXT)",
 'CREATE TABLE wallet_booking_refunds(booking_id INT PRIMARY KEY,gross INT,penalty INT,refunded INT,rule_hours INT,penalty_percent NUMERIC)',
 'CREATE TABLE doctor_accounts(doctor_id INT,user_id INT)',
 'CREATE TABLE centers(id INT,owner_user_id INT)',
 'CREATE TABLE users(id INT)',
 'CREATE TABLE bookings(id INT PRIMARY KEY,patient_user_id INT,doctor_id INT,phone TEXT,fee_toman INT,reminder_fee_toman INT,starts_at TEXT,status TEXT,vat_percent NUMERIC,vat_toman INT)',
 "CREATE TABLE payment_transactions(id INTEGER PRIMARY KEY,purpose TEXT,reference_id INT,gateway TEXT,amount_toman INT,vat_toman INT DEFAULT 0,order_code TEXT,user_id INT,doctor_id INT,description TEXT,status TEXT,ref_num TEXT,verified_at TEXT,refund_penalty_toman INT DEFAULT 0,test_mode INT DEFAULT 0)",
 'CREATE TABLE payment_intents(id INTEGER PRIMARY KEY,booking_id INT,status TEXT)',
 'CREATE TABLE payments(id INTEGER PRIMARY KEY,booking_id INT,status TEXT)',
 'CREATE TABLE refunds(id INTEGER PRIMARY KEY,tx_id INT,purpose TEXT,reference_id INT,amount_toman INT,reason TEXT,status TEXT,method TEXT,bank_reference TEXT,handled_at TEXT,handled_by_admin INT,note TEXT,legacy_intent_id INT)',
 'CREATE TABLE subscription_orders(id INT PRIMARY KEY,doctor_id INT,status TEXT,expires_at TEXT,payable_toman INT,vat_toman INT,number TEXT,method TEXT)',
] as $ddl)db()->exec($ddl);
$checks=0;
function check(bool $ok,string $name): void {if(!$ok)throw new RuntimeException('FAIL '.$name);$GLOBALS['checks']++;echo "PASS $name\n";}
function rejects(callable $f,string $name): void {$error=false;try{$f();}catch(AuthError|LogicException $e){$error=true;}check($error,$name);}
function balance(array $w): int {return (int)query('SELECT balance FROM wallets WHERE id=?',[$w['id']])->fetchColumn();}
$w=wallet_authorize('user',1);check(balance($w)===0,'initial zero balance');rejects(fn()=>wallet_authorize('user',2),'cross-user denied');
query('INSERT INTO doctor_accounts VALUES(9,1)');$doc=wallet_authorize('doctor',9);rejects(fn()=>wallet_authorize('doctor',10),'other doctor denied');
query('INSERT INTO centers VALUES(7,1)');check(wallet_authorize('center',7)['owner_type']==='center','center owner access');rejects(fn()=>wallet_authorize('center',8),'other center denied');
rejects(fn()=>wallet_post((int)$w['id'],10000,'seed','seed'),'posting requires transaction');
db_transaction(fn()=>wallet_post((int)$w['id'],100000,'seed','seed'));db_transaction(fn()=>wallet_post((int)$w['id'],100000,'seed','seed'));check(balance($w)===100000,'ledger replay not doubled');
rejects(fn()=>db_transaction(fn()=>wallet_post((int)$w['id'],1,'seed','bad')),'replay mismatch blocked');
rejects(fn()=>db_transaction(fn()=>wallet_post((int)$w['id'],-100001,'overdraft','bad')),'overdraft blocked');check(balance($w)===100000,'overdraft rollback');
$bank=['holder'=>'شخص آزمایشی','account'=>'123456789','card'=>'4111111111111111','iban'=>'IR062960000000100324200001'];
check(bank_details_validate($bank)['holder']===$bank['holder'],'bank checksum validation');rejects(fn()=>bank_details_validate(array_replace($bank,['card'=>'4111111111111112'])),'bad card');rejects(fn()=>bank_details_validate(array_replace($bank,['iban'=>'IR002960000000100324200001'])),'bad iban');rejects(fn()=>bank_details_validate(array_replace($bank,['holder'=>''])),'missing holder');
$p=['amount'=>20000,'request_key'=>str_repeat('a',32)];rejects(fn()=>wallet_request_payout($w,$p),'payout requires bank');query('INSERT INTO wallet_banks VALUES(?,?)',[$w['id'],encrypt_secret(json_encode($bank))]);
wallet_request_payout($w,$p);wallet_request_payout($w,$p);check(balance($w)===80000,'payout funds reserved once');
rejects(fn()=>wallet_payout_handle(['id'=>1,'status'=>'rejected','note'=>'declined']),'payout requires finance permission');$isAdmin=true;
wallet_payout_handle(['id'=>1,'status'=>'rejected','note'=>'declined']);check(balance($w)===100000,'rejected payout restored');rejects(fn()=>wallet_payout_handle(['id'=>1,'status'=>'rejected','note'=>'declined']),'payout cannot be handled twice');
$p['request_key']=str_repeat('b',32);wallet_request_payout($w,$p);rejects(fn()=>wallet_payout_handle(['id'=>2,'status'=>'paid']),'paid requires reference');wallet_payout_handle(['id'=>2,'status'=>'paid','reference'=>'TEST-ONLY']);check(balance($w)===80000,'paid reservation not debited twice');
query('INSERT INTO users VALUES(1)');
function seedBooking(int $id,int $hours=48,int $test=0): void {query("INSERT INTO bookings VALUES(?,1,9,'09000000000',12000,2000,?,'confirmed',10,1200)",[$id,date('Y-m-d H:i:s',time()+$hours*3600)]);query("INSERT INTO payment_transactions(purpose,reference_id,gateway,amount_toman,vat_toman,status,test_mode) VALUES('booking',?,'bank',13200,1200,'verified',?)",[$id,$test]);}
$settings=['wallet_refund_enabled'=>'1','wallet_refund_hours'=>'24','wallet_refund_penalty'=>'10'];seedBooking(1);
db_transaction(fn()=>wallet_refund_booking(1,'cancel',false));check(balance($w)===92200,'refund gross minus penalty on base fee only');db_transaction(fn()=>wallet_refund_booking(1,'cancel',false));check(balance($w)===92200,'refund replay idempotent');
seedBooking(2,12);db_transaction(fn()=>wallet_refund_booking(2,'late',false));check(balance($w)===92200,'outside refund time excluded');db_transaction(fn()=>wallet_refund_booking(2,'doctor cancel',true));check(balance($w)===105400,'doctor cancellation full refund');
seedBooking(3,48,1);db_transaction(fn()=>wallet_refund_booking(3,'test',false));check(balance($w)===105400,'test-mode cannot create spendable money');
query("INSERT INTO bookings VALUES(4,1,9,'09000000000',10000,0,?,'held',0,0)",[date('Y-m-d H:i:s',time()+86400)]);
$failFulfil=true;rejects(fn()=>wallet_pay_booking(4),'fulfilment failure');check(balance($w)===105400 && !(bool)query('SELECT 1 FROM payment_transactions WHERE reference_id=4')->fetchColumn(),'debit and payment rolled back on failure');$failFulfil=false;
wallet_pay_booking(4);check(balance($w)===94400&&booking_row(4)['status']==='confirmed','booking wallet payment includes VAT');rejects(fn()=>wallet_pay_booking(4),'booking second payment blocked');
db_transaction(fn()=>wallet_post((int)$doc['id'],25000,'doc-seed','test'));query("INSERT INTO subscription_orders VALUES(1,9,'proforma',?,22000,2000,'TEST-1',NULL)",[date('Y-m-d H:i:s',time()+86400)]);wallet_pay_subscription(1,9);check(balance($doc)===3000&&query('SELECT method FROM subscription_orders WHERE id=1')->fetchColumn()==='wallet','subscription wallet payment');rejects(fn()=>wallet_pay_subscription(1,9),'subscription replay blocked');
check((int)query('SELECT SUM(amount) FROM wallet_entries WHERE wallet_id=?',[$w['id']])->fetchColumn()===balance($w),'ledger reconciles balance');
$text=sms_text_render(['body'=>'%token10 / %token / %site_name'],['token'=>'123456','token10'=>'دکتر نمونه']);check(str_contains($text,'دکتر نمونه / 123456 / نوبت‌یار'),'SMS tags prefix-safe replacement');
check(sms_template_ready(['enabled'=>1,'delivery_mode'=>'text','body'=>'متن','template'=>'']),'text template ready without lookup name');check(!sms_template_ready(['enabled'=>0,'delivery_mode'=>'text','body'=>'متن']),'disabled template not ready');
function center_actor(int $id,string $permission): void {}
function center_subscription_activate(int $id,string $method,?int $tx): void {query("UPDATE center_orders SET status='paid',payment_method=? WHERE id=?",[$method,$id]);}
db()->exec('CREATE TABLE center_orders(id INT PRIMARY KEY,center_id INT,status TEXT,expires_at TEXT,payable_toman INT,vat_toman INT,payment_method TEXT)');
$cw=wallet_authorize('center',7);db_transaction(fn()=>wallet_post((int)$cw['id'],30000,'center-seed','test'));query("INSERT INTO center_orders VALUES(1,7,'proforma',?,22000,2000,NULL)",[date('Y-m-d H:i:s',time()+86400)]);wallet_pay_center_subscription(1,7);check(balance($cw)===8000&&query('SELECT payment_method FROM center_orders WHERE id=1')->fetchColumn()==='wallet','center wallet payment');rejects(fn()=>wallet_pay_center_subscription(1,7),'center replay blocked');
$testTx=query('SELECT id FROM payment_transactions WHERE reference_id=3 AND purpose=\'booking\'')->fetchColumn();query("INSERT INTO refunds(tx_id,purpose,reference_id,amount_toman,status) VALUES(?,'booking',3,13200,'pending')",[$testTx]);$rid=(int)db()->lastInsertId();rejects(fn()=>refund_handle($rid,'done','wallet','',''),'manual test refund blocked');
query("INSERT INTO refunds(tx_id,purpose,reference_id,amount_toman,status) VALUES(NULL,'booking',3,13200,'pending')");$rid=(int)db()->lastInsertId();rejects(fn()=>refund_handle($rid,'done','wallet','',''),'legacy wallet conversion blocked');
db()->sqliteCreateFunction('GREATEST',fn($a,$b)=>max($a,$b));query('ALTER TABLE payment_transactions ADD COLUMN created_at TEXT');query("UPDATE payment_transactions SET verified_at=NOW(),created_at=NOW()");
$revenue=payment_revenue(date('Y-m-d'),date('Y-m-d'));$bookingRevenue=array_values(array_filter($revenue['by_purpose'],fn($r)=>$r['purpose']==='booking'))[0];check((int)$bookingRevenue['gross']-(int)$bookingRevenue['refunded']===11000,'revenue retains penalty without double refund deduction');
echo "ALL $checks CHECKS PASSED (isolated SQLite adapter, no external services).\n";
