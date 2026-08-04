# Plain PHP integration

1. Copy `config.example.php` to `config.php` and provide the environment variables.
2. Apply `../../php/database/mysql.sql`.
3. Expose `collect.php` publicly and keep `admin-api.php`/`admin.html` behind the host application's admin authentication.
4. Run retention daily:

```php
$config = require 'config.php';
$config['analytics']->retention->purge();
```

The bearer-token check is only a runnable example. In a real application, replace it with the existing authenticated admin session and CSRF policy. Do not expose the reporting endpoints with only the public project token.
