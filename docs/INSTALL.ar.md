# طريقة التركيب

دليل كامل لتركيب netcontrol على pfSense.

---

## قبل ما تبدأ

**اللي محتاجه:**

- pfSense نسخة 2.7 أو أحدث (اتبنى واتجرب على 2.8.1)
- وصول للسيرفر، سواء SSH أو من الواجهة عن طريق
  **Diagnostics → Command Prompt**
- صلاحية admin

**مش محتاج تنزّل أي باكدج.** كل حاجة PHP عادي بيشتغل على مكتبات pfSense نفسها.

**مميزات اختيارية** بتشتغل لو الخدمة دي مفعّلة عندك:

| الميزة | محتاجة |
|---|---|
| سجل الدخول، استثناء المسجّلين من السياسة | Captive Portal شغال على الـLAN |
| حظر المواقع، فرض الـDNS | DNS Resolver (unbound) — وده الافتراضي |
| أسماء الأجهزة | DHCP Server على الـLAN |

لو واحدة منهم مش مفعّلة، الباقي بيشتغل عادي والميزة دي بس هتبقى فاضية.

---

## الطريقة الأولى: المثبّت التلقائي (الأسهل)

لو عندك SSH أو شِل:

```sh
git clone https://github.com/Topx25/pfsense.git
cd pfsense
sh install.sh
```

المثبّت بيعمل الآتي بالترتيب:

1. **ياخد نسخة احتياطية** من أي ملف هيستبدله، وكمان من `config.xml`،
   في `/root/netcontrol-backup-<التاريخ>`
2. ينسخ الملفات أماكنها
3. يتأكد إن كل ملف PHP سليم قبل ما يكمل
4. يسجّل مهمة الـcron في `config.xml` (مش في crontab العادي —
   علشان متتمسحش لما تغيّر أي إعداد)
5. يركّب قواعد الفايروول
6. يشغّل الفحص الذاتي ويعرضلك النتيجة

لو أي خطوة فشلت، بيقف ويقولك فين المشكلة.

**للتحديث:** شغّله تاني. بيحدّث الملفات وبيسيب إعداداتك زي ما هي.

**للإزالة:**

```sh
sh install.sh remove
```

بيشيل الملفات والقواعد ومهمة الـcron، وبيسيب القواعد المحفوظة في
`/var/db/netcontrol.json` لو حبيت ترجّعه بعدين.

---

## الطريقة الثانية: من الواجهة (من غير SSH)

لو الـSSH مقفول، تقدر تعمل كل حاجة من المتصفح.

### 1. انسخ الملفات

روح **Diagnostics → Edit File**، والصق كل ملف في مكانه:

| الملف في المشروع | مكانه على السيرفر |
|---|---|
| `src/netcontrol.inc` | `/usr/local/pkg/netcontrol.inc` |
| `src/netcontrol_api.php` | `/usr/local/www/netcontrol_api.php` |
| `src/services_netcontrol.php` | `/usr/local/www/services_netcontrol.php` |
| `src/netcontrol_tick.php` | `/usr/local/sbin/netcontrol_tick.php` |
| `src/netcontrol_selftest.php` | `/usr/local/sbin/netcontrol_selftest.php` |
| `src/netcontrol_audit.php` | `/usr/local/sbin/netcontrol_audit.php` |
| `src/netcontrol.xml` | `/usr/local/pkg/netcontrol.xml` |
| `src/netcontrol.priv.inc` | `/etc/inc/priv/netcontrol.priv.inc` |
| `src/netcontrol.conf.php.example` | `/usr/local/pkg/netcontrol.conf.php` |

> **مهم:** الملف الأخير اسمه بيتغيّر — بيتحفظ باسم
> `netcontrol.conf.php` من غير `.example`.

### 2. اتأكد إن الملفات سليمة

من **Diagnostics → Command Prompt**:

```sh
php -l /usr/local/pkg/netcontrol.inc
php -l /usr/local/www/netcontrol_api.php
php -l /usr/local/www/services_netcontrol.php
```

لازم كلهم يقولوا `No syntax errors detected`. **لو واحد فيهم طلّع
خطأ، قف هنا** — يعني النسخ اتقطع في النص.

### 3. اظبط العناوين المحمية

ده أهم إعداد. افتح `/usr/local/pkg/netcontrol.conf.php` وحط
عناوين الأجهزة اللي **ماينفعش تتحظر أبدًا**:

```php
define('NC_OVERRIDE_PROTECTED', '192.168.1.20,192.168.1.250');
```

حط فيها السويتش، الأكسس بوينت، السيرفرات، الهايبرفايزر، الطابعات —
أي حاجة لو اتقطع عنها النت هتعمل مشكلة.

> عنوان pfSense نفسه محمي تلقائيًا، مش محتاج تكتبه.

### 4. ركّب القواعد

```sh
php -r 'require_once("/usr/local/pkg/netcontrol.inc"); nc_install_anchor(); nc_apply();'
```

### 5. سجّل مهمة الـcron

دي اللي بتخلي النظام يتابع تغيّر الـIP وينهي القواعد المؤقتة
ويصلّح نفسه. من غيرها الصفحة هتشتغل بس من غير أي أتمتة.

```sh
php -r 'require_once("/etc/inc/config.inc");
$i = config_get_path("cron/item", []);
$i[] = ["minute"=>"*","hour"=>"*","mday"=>"*","month"=>"*","wday"=>"*",
        "who"=>"root","command"=>"/usr/local/sbin/netcontrol_tick.php"];
config_set_path("cron/item", $i);
write_config("netcontrol cron");
configure_cron();
echo "تم\n";'
```

### 6. اتأكد إن كل حاجة شغالة

```sh
php -f /usr/local/sbin/netcontrol_selftest.php
```

المفروض تشوف `RESULT: all enforced checks passed`.

---

## بعد التركيب

افتح **Services → Network Control** من قايمة pfSense.

لو التبويب مش ظاهر، pfSense بيخزّن القايمة مؤقتًا — اعمل logout
وlogin تاني، أو:

```sh
rm -f /tmp/config.cache
```

---

## الإعدادات

كل حاجة ممكن تغيّرها في ملف واحد:
`/usr/local/pkg/netcontrol.conf.php`

النظام بيقرا الواجهات والسبنت وزون الكابتيف بورتال **من إعدادات
pfSense نفسها**، فالتركيب العادي مش محتاج أي إعداد.

```php
/* العناوين المحمية — مهم جدًا تظبطها */
define('NC_OVERRIDE_PROTECTED', '192.168.1.20,192.168.1.250');

/* الباقي دول بس لو الكشف التلقائي غلط عندك */
// define('NC_OVERRIDE_LAN_IF',     'em1');
// define('NC_OVERRIDE_WAN_IF',     'em2');
// define('NC_OVERRIDE_LAN_IP',     '192.168.1.1');
// define('NC_OVERRIDE_CP_ZONE',    'guest');
```

**امتى تحتاج تغيّر الواجهة؟** لو عندك أكتر من خط إنترنت. النظام
بيحدد الـWAN من **مسار الافتراضي** مش من اسمها، علشان في كتير من
التركيبات الواجهة المسماة "wan" بتكون احتياطية ومالهاش عنوان.
لو عايز تثبّت خط معيّن استخدم `NC_OVERRIDE_WAN_IF`.

---

## لو حاجة مش شغالة

### الصفحة بيضا أو مش بتفتح

```sh
php -l /usr/local/www/services_netcontrol.php
tail -20 /var/log/nginx/error.log
```

### الصفحة بتفتح بس مافيش بيانات

غالبًا الـAPI بيرجّع خطأ. افتح الصفحة واضغط F12 → Console.
لو شفت `Unexpected token '<'` يبقى الجلسة انتهت — اعمل F5.

### الحظر مش بيتنفّذ

```sh
php -f /usr/local/sbin/netcontrol_audit.php
```

بيقارن اللي مسجّل باللي متنفّذ فعلًا في الكيرنل، ويقولك بالظبط
إيه الناقص.

### حظر المواقع مش شغال

```sh
grep -c netcontrol.conf /var/unbound/unbound.conf
```

لازم يطلع `1`. لو طلع `0` يبقى pfSense مسح السطر — النظام بيرجّعه
لوحده خلال دقيقة، أو شغّل:

```sh
php -r 'require_once("/usr/local/pkg/netcontrol.inc"); nc_apply();'
```

### جهاز مهم اتحظر بالغلط

```sh
php -r 'require_once("/usr/local/pkg/netcontrol.inc");
$s = nc_store_load();
foreach ($s["devices"] as $m => &$d) { $d["blocked"] = false; }
nc_store_save($s); nc_apply(); echo "اترفع الحظر عن الكل\n";'
```

وبعدين حط عنوانه في `NC_OVERRIDE_PROTECTED` علشان مايتكررش.

---

## الرجوع لأي نسخة سابقة

المثبّت بياخد نسخة قبل كل تركيب:

```sh
ls -lt /root/netcontrol-backup-*
```

للرجوع:

```sh
cp /root/netcontrol-backup-<التاريخ>/netcontrol.inc /usr/local/pkg/
cp /root/netcontrol-backup-<التاريخ>/services_netcontrol.php /usr/local/www/
php -r 'require_once("/usr/local/pkg/netcontrol.inc"); nc_apply();'
```

---

## حاجات لازم تعرفها

**شبكة واحدة بس.** النسخة دي بتشتغل على واجهة الـLAN الواحدة.
لو عندك VLANs أو شبكات تانية، الأجهزة اللي عليها مش هتظهر.

**الحظر بياخد وقت لحظة.** القاعدة بتمنع الاتصالات الجديدة، والنظام
بيقطع الاتصالات المفتوحة — بس البث الشغال ممكن ياخد ثانيتين لحد
ما يقف فعلًا.

**الماك العشوائي.** الموبايلات الحديثة بتغيّر الماك كل اتصال.
النظام بيتابعها باسم الهوست لما الاسم يكون مميّز كفاية، والباقي
بيتعلّم عليه في الصفحة إنه ضعيف. الحل الجذري إن المستخدمين يقفلوا
الميزة دي لشبكتك.

**فرض الـDNS بيغيّر سلوك الشبكة.** بيحوّل أي طلب DNS للفايروول
حتى لو الجهاز كاتب 8.8.8.8. الأجهزة هتفضل شغالة عادي، بس هتاخد
إجاباتك إنت — وده المقصود، علشان حظر المواقع يشتغل.
