# Installing by hand

Use `install.sh` if you can. These are the same steps, for when you only have
**Diagnostics → Command Prompt** and no shell.

## 1. Copy the files

| From | To |
|---|---|
| `src/netcontrol.inc` | `/usr/local/pkg/netcontrol.inc` |
| `src/netcontrol_api.php` | `/usr/local/www/netcontrol_api.php` |
| `src/services_netcontrol.php` | `/usr/local/www/services_netcontrol.php` |
| `src/netcontrol_tick.php` | `/usr/local/sbin/netcontrol_tick.php` |
| `src/netcontrol_selftest.php` | `/usr/local/sbin/netcontrol_selftest.php` |
| `src/netcontrol.xml` | `/usr/local/pkg/netcontrol.xml` |
| `src/netcontrol.priv.inc` | `/etc/inc/priv/netcontrol.priv.inc` |
| `src/netcontrol.conf.php.example` | `/usr/local/pkg/netcontrol.conf.php` |

Through the web UI only, the practical route is **Diagnostics → Edit File**:
paste each file and save it to its path.

## 2. Check they parse

```sh
php -l /usr/local/pkg/netcontrol.inc
php -l /usr/local/www/netcontrol_api.php
php -l /usr/local/www/services_netcontrol.php
```

Stop here if any of them reports an error.

## 3. Set the protected addresses

Edit `/usr/local/pkg/netcontrol.conf.php` and list anything that must never
be blocked — switches, access points, hypervisors, servers:

```php
define('NC_OVERRIDE_PROTECTED', '192.168.1.20,192.168.1.250');
```

## 4. Install the rules

```sh
php -r 'require_once("/usr/local/pkg/netcontrol.inc"); nc_install_anchor(); nc_apply();'
```

## 5. Add the cron job

```sh
(crontab -l 2>/dev/null; echo "* * * * * /usr/local/bin/php -f /usr/local/sbin/netcontrol_tick.php >/dev/null 2>&1") | crontab -
```

## 6. Verify

```sh
php -f /usr/local/sbin/netcontrol_selftest.php
```

Everything should pass. Then open **Services → Network Control**.

## If the menu entry does not appear

pfSense caches the menu. Log out and back in, or:

```sh
rm -f /tmp/config.cache
```
