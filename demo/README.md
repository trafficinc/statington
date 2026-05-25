# Statington Demo

Terminal 1:

```bash
php -S localhost:8123 server/router.php
```

Terminal 2:

```bash
php -S localhost:8080 demo/public/index.php
```

Open:

- http://localhost:8080/users
- http://localhost:8080/db-impact
- http://localhost:8080/db-select
- http://localhost:8080/login-fail
- http://localhost:8080/redacted?token=demo-token&api_key=demo-key&safe=visible
- http://localhost:8080/slow
- http://localhost:8080/error
- http://localhost:8080/fatal

For header redaction, call:

```bash
curl -H "Authorization: Bearer demo-token" \
  -H "Cookie: session=demo-cookie" \
  "http://localhost:8080/redacted?token=demo-token&api_key=demo-key"
```

Then view dashboard:

http://localhost:8123
