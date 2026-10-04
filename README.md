# Catalog Search

Hybrid (lexical + semantic) search over a 1,000-product catalog, for Hebrew, English and mixed queries, with an RTL web UI.

- Live: https://13-50-9-19.sslip.io
- Repo: https://github.com/benji2618/catalog-search

## Running locally

Requirements: PHP 8.3+ (with curl, mbstring, PDO MySQL; APCu optional), MySQL 8 (not MariaDB: the ngram FULLTEXT parser is required for Hebrew), Composer.

```bash
composer install
cp .env.example .env            # then set VOYAGE_API_KEY and the DB_* values
mysql -u root -p -e "CREATE DATABASE catalog CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci"
mysql -u root -p catalog < database/schema.sql
php scripts/ingest.php          # loads the catalog and embeds it with Voyage
php -S localhost:8000 -t public public/index.php
```

`scripts/ingest.php` is safe to re-run: it upserts products and only embeds rows that have no embedding yet. `--reembed` clears all embeddings first.

`data/products_enriched.json` is committed, so the Claude enrichment step (`scripts/enrich.php`) does not need to be rerun, and `ANTHROPIC_API_KEY` is not needed to run the project.

API: `GET /api/search?q=...` returns `{query, results[], semantic, took_ms}`; `semantic` is `false` when the lexical-only fallback was used.

## Key decisions

- **Plain PHP, no framework.** Small scope; every line can be explained. Composer is used only for autoloading and phpdotenv.
- **MySQL 8 FULLTEXT with the ngram parser** for Hebrew. Queries use BOOLEAN MODE with quoted terms: natural language mode with ngram matched almost anything in testing.
- **Hybrid search.** Lexical (name weighted 2x) and semantic (Voyage `voyage-4` embeddings) candidates are fused with Reciprocal Rank Fusion; products containing all query terms are ranked first. If Voyage fails, the lexical results are returned.
- **One-time catalog enrichment with Claude:** English names, transliterations, Hebrew/English use cases, and prices in ILS (the CSV has none, so they are estimates). The output is committed and was reviewed.
- **Vectors in APCu.** 1,000 x 1,024 floats fit in memory, and a brute-force dot product is fast at this size. Without APCu they are read from MySQL on each request.
- **Deployment on AWS Lightsail** (Ubuntu, Apache, MySQL on localhost, HTTPS). Details and the deploy script are in [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md).

## How I tested it

`scripts/eval.php` runs the 17 queries in `data/eval_queries.json` against the real search service, with expected product ids written by hand: 5 from the brief, 6 used while tuning, 6 never seen during tuning (labelled `held-out` in the file).

```bash
php scripts/eval.php
```

Result: 17/17. For the 16 queries with expected products, an expected product is at rank 1; the 17th (`xqzwv`) expects no results. The criterion is lenient (one expected product anywhere in the top 5), so the 6 unseen queries are the meaningful ones.

Real searches (`php scripts/search.php "<query>"`, top 3 by English name):

| Query | Top 3 |
|---|---|
| שואב אבק | Electrolux Cordless Stick Vacuum with Motorized Brush; Samsung Cordless Stick Vacuum with Dust Sensor Display; Dyson Cordless Stick Vacuum with Laser Dust Detection |
| iPhone 16 | Apple Pro Smartphone Triple Camera 6.7 inch 512GB; Apple Smartphone 6.1 inch 128GB; Apple Compact Smartphone 5.4 inch 128GB |
| אייפון | Apple Smartphone 6.1 inch 128GB; Apple Pro Smartphone Triple Camera 6.7 inch 512GB; Apple Compact Smartphone 5.4 inch 128GB |
| מתנה למישהו שאוהב לבשל | Gourmet House Luxury Gourmet Gift Set in Wooden Box; Gourmet House Gourmet Spice Set of Ten; Gourmet House Tea and Chocolate Set for a Cozy Evening |
| משהו שיעזור לנקות שערות של כלב מהספה | FurAway Deshedding Brush for Dogs and Cats; GroomEasy Pet Grooming Clipper; Bissell Compact Carpet Cleaner for Homes with Pets |
| blender (English) | Braun Immersion Hand Blender; Ninja High Speed Blender; Kenwood Compact Stand Mixer |
| אוזניות bluetooth (mixed) | SoftSleep Thin Bluetooth Sleep Headphones; Poly Business Headset with Bluetooth and USB; Sennheiser Classic In-Ear Headphones with Microphone |

For the vague pet-hair query only the first result is clearly on target (a deshedding brush); the next two are weaker matches.

Speed: `took_ms` measured against production with curl, 8 queries x 3 requests, warm cache: 71 to 90 ms (server-side search time, excluding network). The first request after a deploy is slower because the vectors are reloaded into APCu.

## Known limitations and next steps

- Semantic noise after the relevant results (for example air purifiers after air fryers for "מטגן אוויר"): add a reranker.
- No synonym handling and no click signals.
- Brute-force vector search is fine for 1,000 products; a larger catalog needs an ANN index.
- No CI yet: `php -l` and `scripts/eval.php` should run on every push.
- Deploys are not atomic (`git merge` in place, then an Apache reload).
- The site uses an sslip.io hostname instead of a real domain.
- Prices are estimates generated by Claude during enrichment, not catalog data.
- Product images are the placeholders from the provided CSV.

## Working with Claude

**Workflow.** Claude chat for design and review, Claude Code for writing code. I read every change before running or committing it. `.claude/settings.json` denies Claude Code read access to `.env`.

**How I verified its output.** The eval set; manual review of enrichment samples; reading the code instead of trusting summaries; checking claims against `git log`; curl tests in production.

Three examples:

- **P0084.** Reviewing enrichment samples, I found that Claude's output for the silicone ice tray had a Hebrew use case meaning "freezing babies and food", "הקפאת תינוקות ואוכל". I fixed it by hand, and the fix was overwritten by the enrichment script, which was still running. I re-applied it after the script completed and checked the final `data/products_enriched.json`: it now reads "הקפאת אוכל לתינוקות" (freezing baby food). Lesson: don't edit a file that a running process is still writing.
- **deploy.sh.** Claude Code's summary said the warm-up error message mentions that Apache was already reloaded; the code did not. Separately, a design flaw shared by me and both Claude instances: lint ran after `git pull`, on the assumption that code only goes live on reload, but OPcache revalidates timestamps, so a broken file could be served before the check. The script now lints the incoming revision before applying it.
- **Eval expectations.** The eval flagged failures for coffee machines and 65" TVs. The error was in my expected answers (incomplete lists), not in the engine.

Smaller catches:

- Brand inference rule removed from the enrichment prompt (inconsistent with "don't add keys").
- `temperature` was rejected by the API (400): removed, and retries are limited to transient errors.
- "Not a JSON array" failures: raw response is now logged and parsing is tolerant.
- Hebrew romanizations removed from transliterations. For the CSV typo "מטהג" (P0001), the enrichment prompt asks for the correct spelling in the transliterations: P0001 now has "מטגן אוויר חם" there, so it is searchable under the correct word. Its name and description still contain the original typo.
- `JSON_THROW_ON_ERROR` accepted without a catch (invalid UTF-8 is not transient).
- Code review: deprecated `VALUES()` in the upsert, `curl_close()` deprecated in PHP 8.5, embeddings sorted by `index`.
- Claude Code wrongly claimed files were uncommitted; checked with `git log`.
- The rollback hint in deploy.sh missed `composer install`: a gap in my own spec.

## Secrets

No API keys or passwords are in the code or the repository. They live only in `.env` (git-ignored), locally and on the server.
