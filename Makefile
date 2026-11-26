up:
	docker compose up -d --build

down:
	docker compose down

logs:
	docker compose logs -f

shell:
	docker compose exec api bash

test:
	docker compose exec api php public/index.php

ps:
	docker compose ps
