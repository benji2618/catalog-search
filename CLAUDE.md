# Catalog Search — project context

Smart search over a 1,000-product catalog (Hebrew / English / mixed queries, semantic search, RTL UI).

## Stack
- PHP 8.3+, no framework, PDO. Composer only for PSR-4 autoload (App\ → src/) and phpdotenv.
- MySQL 8 (FULLTEXT ngram parser, utf8mb4). Not MariaDB: ngram is required for Hebrew.
- Front: static public/index.html + vanilla JS + CSS, no build step.
- Embeddings: Voyage AI (multilingual). Catalog enrichment: Claude API (one-off script).
- Production: AWS Lightsail, deployed with git pull.

## Architecture
- public/index.php routes GET /api/search?q= → Controllers/SearchController
- Controller → Services/SearchService → Models/ProductRepository (all SQL lives here)
- Search = lexical (MATCH AGAINST, top 50, name weighted higher) + semantic (dot product, top 50) fused with RRF 1/(60+rank), top 24. Lexical fallback if embedding API fails.

## Rules
- Keep it simple: ~1,000 lines total, few files, readable and explainable code.
- Write PHP 8.3-compatible code (no 8.4+ features like property hooks or the pipe operator): production version may be older than local 8.5.
- strict_types everywhere, prepared statements only, no secrets in code.
- Small commits, one step at a time, messages in English (conventional style: feat:, fix:, chore:).
- Ask before adding a dependency or a new file not listed above.
