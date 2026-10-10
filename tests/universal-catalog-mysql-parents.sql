-- Disposable minimal parent schema with exact canonical category/subcategory identity contracts.
CREATE TABLE categories (
 id VARCHAR(50) NOT NULL PRIMARY KEY,
 name VARCHAR(100) NOT NULL,
 type VARCHAR(20) NOT NULL,
 system_key VARCHAR(80),
 is_system TINYINT NOT NULL DEFAULT 0,
 is_active TINYINT NOT NULL DEFAULT 1,
 UNIQUE KEY uq_category_name_type (name,type),
 UNIQUE KEY uq_category_system_key(system_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE subcategories (
 id VARCHAR(50) NOT NULL PRIMARY KEY,
 category_id VARCHAR(50) NOT NULL,
 category_name VARCHAR(100) NOT NULL,
 name VARCHAR(100) NOT NULL,
 system_key VARCHAR(80),
 is_system TINYINT NOT NULL DEFAULT 0,
 is_active TINYINT NOT NULL DEFAULT 1,
 UNIQUE KEY uq_subcategory_category_name(category_id,name),
 CONSTRAINT fk_subcategories_category FOREIGN KEY (category_id) REFERENCES categories(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
