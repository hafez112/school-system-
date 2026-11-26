# School System - Monorepo

مشروع إدارة نظام المدارس الأهلية مبني باستخدام PHP وMySQL، مع Docker للتشغيل المتكامل.

## البنية

```text
.
├── apps/
│   ├── api/
│   └── web/
├── packages/
│   ├── shared/
│   └── database/
├── docker/php/
├── storage/logs/
├── docker-compose.yml
├── .env.example
└── Makefile
```

## التشغيل

```bash
cp .env.example .env
docker compose up -d --build
curl http://localhost:8080/health
```

## أوامر مفيدة

```bash
make up
make down
make logs
make shell
make test
```
