'use strict';
(async()=>{
 const data=JSON.parse(document.getElementById('poster-data').textContent),canvas=document.getElementById('doctor-poster'),c=canvas.getContext('2d'),status=document.getElementById('poster-status');
 const W=2480,H=3508,blue='#087dff',ink='#09264d';
 const image=src=>new Promise((resolve,reject)=>{const i=new Image();i.onload=()=>resolve(i);i.onerror=()=>reject(Error('بارگذاری عکس یا لوگو ممکن نشد؛ دوباره تلاش کنید.'));i.src=src;});
 const round=(x,y,w,h,r,fill)=>{c.beginPath();c.roundRect(x,y,w,h,r);c.fillStyle=fill;c.fill();};
 const text=(s,x,y,size,color=ink,weight=700,max=2200)=>{c.direction='rtl';c.textAlign='center';c.fillStyle=color;while(size>24){c.font=`${weight} ${size}px IRANSans, sans-serif`;if(c.measureText(s).width<=max)break;size-=2;}c.fillText(s,x,y);};
 const circle=(x,y,r,color)=>{c.beginPath();c.arc(x,y,r,0,Math.PI*2);c.fillStyle=color;c.fill();};
 const fit=(im,x,y,w,h)=>{const s=Math.min(w/im.width,h/im.height);c.drawImage(im,x+(w-im.width*s)/2,y+(h-im.height*s)/2,im.width*s,im.height*s);};
 function mark(x,y,s){c.save();c.translate(x,y);c.scale(s,s);c.lineCap='round';c.lineJoin='round';c.strokeStyle='#0569d8';c.lineWidth=22;c.beginPath();c.moveTo(-28,4);c.lineTo(-4,28);c.lineTo(38,-18);c.stroke();circle(-4,-39,13,'#2bc3ae');c.restore();}
 function glyph(kind,x,y,color){c.save();c.translate(x,y);c.strokeStyle=color;c.lineWidth=9;c.lineCap='round';c.lineJoin='round';c.beginPath();if(kind==='clock'){c.arc(0,0,40,0,7);c.moveTo(0,-24);c.lineTo(0,0);c.lineTo(20,13);}else if(kind==='calendar'){c.roundRect(-38,-32,76,70,8);c.moveTo(-38,-10);c.lineTo(38,-10);c.moveTo(-19,-44);c.lineTo(-19,-23);c.moveTo(19,-44);c.lineTo(19,-23);}else if(kind==='pin'){c.moveTo(0,47);c.bezierCurveTo(-77,-25,-20,-66,17,-39);c.bezierCurveTo(59,-10,16,24,0,47);c.moveTo(14,-14);c.arc(0,-14,14,0,7);}else{c.roundRect(-26,-46,52,90,9);c.moveTo(-5,27);c.lineTo(5,27);}c.stroke();c.restore();}
 try{
 await Promise.all([document.fonts.load('700 120px IRANSans'),document.fonts.load('400 64px IRANSans')]);
 const [photo,logo]=await Promise.all([data.photo?image(data.photo):null,data.logo?image(data.logo):null]);
 c.fillStyle='#fbfdff';c.fillRect(0,0,W,H);
 const g=c.createLinearGradient(0,0,W,H);g.addColorStop(0,'#c5e3ff');g.addColorStop(1,'#eaf5ff');
 circle(-170,-200,820,g);circle(W+310,160,620,'#e7f3ff');circle(W+640,2030,1110,'#edf6ff');circle(-280,2480,680,'#e4f1ff');
 c.fillStyle='#d5eaff';c.beginPath();c.moveTo(0,3160);c.quadraticCurveTo(1000,3900,W,3230);c.lineTo(W,H);c.lineTo(0,H);c.fill();
 // 1.0.24: an uploaded logo already carries the slogan; it is drawn only under the built-in mark.
 if(logo)fit(logo,640,60,1200,340);else{mark(1590,235,2.25);text(data.site,1130,290,170,ink,700,1000);text('ساده‌تر، سریع‌تر، مطمئن‌تر',W/2,430,62,'#737c8d',400);}
 c.save();c.shadowColor='#339cff44';c.shadowBlur=55;circle(W/2,865,396,blue);c.restore();circle(W/2,865,374,'white');
 c.save();c.beginPath();c.arc(W/2,865,350,0,Math.PI*2);c.clip();if(photo){const scale=Math.max(700/photo.width,700/photo.height);c.drawImage(photo,W/2-photo.width*scale/2,865-photo.height*scale/2,photo.width*scale,photo.height*scale);}else{c.fillStyle='#e7f2ff';c.fillRect(0,0,W,H);circle(W/2,790,100,'#93bce5');circle(W/2,1110,230,'#93bce5');}c.restore();
 text(data.name,W/2,1440,140,ink,700,1900);text(data.specialty,W/2,1565,80,'#626c7d',400,2130);
 c.save();c.strokeStyle='#5b9ef6';c.lineWidth=23;c.lineCap='round';for(const side of [-1,1]){c.beginPath();c.moveTo(W/2+side*1040,1360);c.lineTo(W/2+side*1110,1280);c.moveTo(W/2+side*1080,1420);c.lineTo(W/2+side*1180,1400);c.stroke();}c.restore();
 c.save();c.shadowColor='#467fa82a';c.shadowBlur=65;round(785,1640,910,910,85,'white');c.restore();
 const qr=window.qrcode(0,'H');qr.addData(new URL(data.url).href,'Byte');qr.make();const count=qr.getModuleCount(),cell=Math.floor(760/(count+8)),size=cell*(count+8),left=Math.round((W-size)/2),top=1700;
 c.fillStyle='#fff';c.fillRect(left,top,size,size);c.fillStyle='#061429';for(let row=0;row<count;row++)for(let col=0;col<count;col++)if(qr.isDark(row,col))c.fillRect(left+(col+4)*cell,top+(row+4)*cell,cell,cell);
 const b=c.createLinearGradient(780,0,1700,0);b.addColorStop(0,'#2494ff');b.addColorStop(1,'#0069ed');round(760,2490,960,228,65,b);text('برای دریافت نوبت',W/2,2585,78,'white');text('اسکن کنید',W/2,2675,78,'white');
 round(830,2755,820,136,68,'#e3f0ff');c.direction='ltr';text(data.domain,W/2,2844,68,'#0649a6',700,750);
 round(105,2960,2270,337,90,'white');const blocks=[['clock','صرفه‌جویی','در زمان','#3788ef'],['calendar','دریافت نوبت','آنلاین','#9c65ed'],['pin','در هر زمان','و از هر مکان','#25bba6'],['phone','با موبایل','و کامپیوتر','#df518c']];
 blocks.forEach(([kind,l1,l2,color],i)=>{const x=390+i*565;circle(x,3055,75,color+'20');glyph(kind,x,3055,color);text(l1,x,3186,54,ink,700,510);text(l2,x,3259,54,ink,700,510);if(i<3){c.strokeStyle='#dce7f3';c.lineWidth=3;c.beginPath();c.moveTo(x+280,3020);c.lineTo(x+280,3240);c.stroke();}});
 text('نوبت بعدی شما، فقط چند ثانیه فاصله دارد!',W/2,3420,70,'#102d65',700,2200);
 const url=canvas.toDataURL('image/png'),output=document.getElementById('poster-image');output.src=url;await output.decode();output.hidden=false;canvas.hidden=true;
 document.getElementById('poster-download').disabled=false;document.getElementById('poster-print').disabled=false;status.textContent='آماده دانلود و چاپ A۴ • ۲۴۸۰ × ۳۵۰۸ پیکسل';
 document.getElementById('poster-download').onclick=()=>{const a=document.createElement('a');a.href=url;a.download='doctor-poster-A4.png';a.click();};document.getElementById('poster-print').onclick=()=>window.print();
 }catch(e){status.textContent=e.message||'ساخت پوستر ممکن نشد؛ صفحه را تازه کنید.';}
})();
