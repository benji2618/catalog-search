CREATE TABLE products (
  id          VARCHAR(8)    PRIMARY KEY,
  name        VARCHAR(255)  NOT NULL,
  name_en     VARCHAR(255)  NULL,
  brand       VARCHAR(64)   NULL,
  category    VARCHAR(64)   NOT NULL,
  subcategory VARCHAR(64)   NOT NULL,
  description TEXT          NOT NULL,
  attributes  VARCHAR(255)  NOT NULL,
  image_url   VARCHAR(255)  NOT NULL,
  price       DECIMAL(10,2) NULL,
  search_text TEXT          NOT NULL,
  embedding   BLOB          NULL,
  FULLTEXT KEY ft_name   (name, name_en, brand) WITH PARSER ngram,
  FULLTEXT KEY ft_search (search_text)          WITH PARSER ngram
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE query_cache (
  query_hash CHAR(64)     PRIMARY KEY,
  query_text VARCHAR(500) NOT NULL,
  embedding  BLOB         NOT NULL,
  created_at TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
