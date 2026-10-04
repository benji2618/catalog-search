CREATE TABLE products (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  sku         VARCHAR(64)   NULL,
  name        VARCHAR(255)  NOT NULL,
  name_he     VARCHAR(255)  NULL,
  name_en     VARCHAR(255)  NULL,
  category    VARCHAR(128)  NULL,
  image_url   VARCHAR(1024) NULL,
  price       DECIMAL(10,2) NULL,
  search_text TEXT          NOT NULL,
  embedding   BLOB          NULL,
  FULLTEXT KEY ft_name   (name, name_he, name_en) WITH PARSER ngram,
  FULLTEXT KEY ft_search (search_text)             WITH PARSER ngram
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE query_cache (
  query_hash CHAR(64)     PRIMARY KEY,
  query_text VARCHAR(500) NOT NULL,
  embedding  BLOB         NOT NULL,
  created_at TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
