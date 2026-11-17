-- =====================================================================================
-- Footwear Inventory - FRESH INSTALL ONLY
--
-- Creates every table in an EMPTY database. It refuses to run if any of the app's tables
-- already exist - for an existing database run migrations/001_colorways_permissions.sql.
--
--   C:\xampp\mysql\bin\mysql.exe -uroot -e "CREATE DATABASE shoe_inventory CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci"
--   C:\xampp\mysql\bin\mysql.exe -uroot --default-character-set=utf8mb4 --abort-source-on-error shoe_inventory -e "source C:/xampp/htdocs/shoe-inventory/schema.sql"
-- (--abort-source-on-error makes the client stop at the first error instead of carrying on.)
--
-- No account is created here. The first time login.php is opened with an empty users
-- table, it shows a one-time "create the first admin" form. Only ONE admin can ever exist;
-- every account created afterwards is staff.
-- =====================================================================================

SET NAMES utf8mb4;
SET SESSION sql_mode = 'STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION';

DELIMITER $$
BEGIN NOT ATOMIC
    IF DATABASE() IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Stopped: no database selected. Create/select shoe_inventory first.';
    END IF;
    IF EXISTS (SELECT 1 FROM information_schema.TABLES
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME IN ('users', 'shoes', 'shoe_sizes', 'shoe_colorways', 'stock_movements',
                                     'permissions', 'user_permissions', 'schema_migrations')) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Stopped: tables already exist. Use migrations/001_colorways_permissions.sql instead.';
    END IF;
END$$
DELIMITER ;


-- Accounts. admin_slot + uq_single_admin allow only ONE admin account.
CREATE TABLE users (
    id              INT UNSIGNED          NOT NULL AUTO_INCREMENT,
    username        VARCHAR(30)           NOT NULL,
    display_name    VARCHAR(80)           NOT NULL,
    password_hash   VARCHAR(255)          NOT NULL,
    role            ENUM('admin','staff') NOT NULL DEFAULT 'staff',
    is_active       TINYINT(1)            NOT NULL DEFAULT 1,
    failed_attempts INT UNSIGNED          NOT NULL DEFAULT 0,
    locked_until    BIGINT UNSIGNED       NULL,
    last_login      BIGINT UNSIGNED       NULL,
    created_at      BIGINT UNSIGNED       NOT NULL,
    admin_slot      TINYINT UNSIGNED AS (IF(role = 'admin', 1, NULL)) STORED
                    COMMENT '1 = the admin account; unique, so only one admin can exist',
    PRIMARY KEY (id),
    UNIQUE KEY username (username),
    UNIQUE KEY uq_single_admin (admin_slot)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Shoe models: brand, name, category and price are shared by all colorways.
-- brand_key + name_key make "Nike / Air Force 1" and " NIKE / air  force 1 " the same model.
CREATE TABLE shoes (
    id        INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    name      VARCHAR(150)  NOT NULL,
    brand     VARCHAR(100)  NOT NULL,
    category  VARCHAR(100)  NOT NULL,
    price     DECIMAL(10,2) NOT NULL,
    image_url VARCHAR(500)  DEFAULT NULL,
    brand_key VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin
              AS (LOWER(TRIM(REGEXP_REPLACE(brand, '[[:space:]]+', ' ')))) STORED
              COMMENT 'brand in lower case, spaces tidied (model grouping)',
    name_key  VARCHAR(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin
              AS (LOWER(TRIM(REGEXP_REPLACE(name, '[[:space:]]+', ' ')))) STORED
              COMMENT 'model name in lower case, spaces tidied (model grouping)',
    PRIMARY KEY (id),
    UNIQUE KEY uq_shoe_model (brand_key, name_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Colorways of a model, each with its own name, description and image.
CREATE TABLE shoe_colorways (
    id          INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    shoe_id     INT UNSIGNED      NOT NULL,
    name        VARCHAR(80)       NOT NULL,
    name_key    VARCHAR(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin
                AS (LOWER(TRIM(REGEXP_REPLACE(name, '[[:space:]]+', ' ')))) STORED
                COMMENT 'colorway name in lower case, spaces tidied (duplicate check)',
    description VARCHAR(255)      NULL,
    image_path  VARCHAR(255)      NULL COMMENT 'uploaded image: uploads/colorways/<32 hex>.<ext>',
    image_url   VARCHAR(500)      NULL COMMENT 'or an external http(s) image link',
    sort_order  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    created_at  BIGINT UNSIGNED   NOT NULL,
    updated_at  BIGINT UNSIGNED   NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_colorway_name (shoe_id, name_key),
    UNIQUE KEY uq_colorway_shoe (id, shoe_id),
    CONSTRAINT fk_colorway_shoe FOREIGN KEY (shoe_id) REFERENCES shoes (id) ON DELETE CASCADE,
    CONSTRAINT chk_colorway_name       CHECK (name_key <> ''),
    CONSTRAINT chk_colorway_image_path CHECK (image_path IS NULL OR image_path REGEXP '^uploads/colorways/[0-9a-f]{32}[.](jpg|png|webp|gif)$'),
    CONSTRAINT chk_colorway_image_url  CHECK (image_url IS NULL OR image_url REGEXP '^https?://[^[:space:]]+$'),
    CONSTRAINT chk_colorway_one_image  CHECK (image_path IS NULL OR image_url IS NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Stock: one row per colorway + size. The colorway must belong to the same shoe.
CREATE TABLE shoe_sizes (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    shoe_id     INT UNSIGNED NOT NULL,
    colorway_id INT UNSIGNED NOT NULL,
    size_number DECIMAL(3,1) NOT NULL,
    quantity    INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY uq_colorway_size (colorway_id, size_number),
    KEY idx_sizes_shoe (shoe_id),
    KEY idx_sizes_colorway_shoe (colorway_id, shoe_id),
    CONSTRAINT fk_sizes_shoe FOREIGN KEY (shoe_id) REFERENCES shoes (id) ON DELETE CASCADE,
    CONSTRAINT fk_sizes_colorway FOREIGN KEY (colorway_id, shoe_id)
        REFERENCES shoe_colorways (id, shoe_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Stock history: who changed what, when, before and after. Names are copied into each
-- row and links become NULL (never deleted) when shoes/colorways/sizes are removed.
CREATE TABLE stock_movements (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    created_at      BIGINT UNSIGNED NOT NULL COMMENT 'unix time',
    user_id         INT UNSIGNED    NULL COMMENT 'who made the change; NULL = recorded by the system',
    movement_type   ENUM('opening','initial','stock_in','stock_out','adjustment','size_removed','colorway_deleted','shoe_deleted') NOT NULL,
    shoe_id         INT UNSIGNED    NULL,
    colorway_id     INT UNSIGNED    NULL,
    size_id         INT UNSIGNED    NULL,
    brand           VARCHAR(100)    NOT NULL COMMENT 'names copied at the time of the change',
    shoe_name       VARCHAR(150)    NOT NULL,
    colorway_name   VARCHAR(80)     NOT NULL,
    size_number     DECIMAL(3,1)    NOT NULL,
    quantity_before INT UNSIGNED    NOT NULL,
    quantity_after  INT UNSIGNED    NOT NULL,
    quantity_change INT AS (CAST(quantity_after AS SIGNED) - CAST(quantity_before AS SIGNED)) STORED,
    note            VARCHAR(255)    NULL,
    PRIMARY KEY (id),
    KEY idx_mov_created (created_at),
    KEY idx_mov_shoe (shoe_id, created_at),
    KEY idx_mov_colorway (colorway_id, created_at),
    KEY idx_mov_size (size_id, created_at),
    KEY idx_mov_user (user_id, created_at),
    CONSTRAINT fk_mov_user     FOREIGN KEY (user_id)     REFERENCES users (id)          ON DELETE RESTRICT,
    CONSTRAINT fk_mov_shoe     FOREIGN KEY (shoe_id)     REFERENCES shoes (id)          ON DELETE SET NULL,
    CONSTRAINT fk_mov_colorway FOREIGN KEY (colorway_id) REFERENCES shoe_colorways (id) ON DELETE SET NULL,
    CONSTRAINT fk_mov_size     FOREIGN KEY (size_id)     REFERENCES shoe_sizes (id)     ON DELETE SET NULL,
    CONSTRAINT chk_mov_stock_in  CHECK (movement_type <> 'stock_in'  OR quantity_after > quantity_before),
    CONSTRAINT chk_mov_stock_out CHECK (movement_type <> 'stock_out' OR quantity_after < quantity_before),
    CONSTRAINT chk_mov_start     CHECK (movement_type NOT IN ('opening', 'initial') OR quantity_before = 0),
    CONSTRAINT chk_mov_removed   CHECK (movement_type NOT IN ('size_removed', 'colorway_deleted', 'shoe_deleted') OR quantity_after = 0),
    CONSTRAINT chk_mov_size_rule CHECK (movement_type <> 'size_removed' OR quantity_before = 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Staff permission checklist and assignments (the admin always has full access).
CREATE TABLE permissions (
    id                    SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
    code                  VARCHAR(50)       NOT NULL COMMENT 'used by the PHP code, e.g. stock.in',
    category              VARCHAR(50)       NOT NULL COMMENT 'checklist heading',
    label                 VARCHAR(80)       NOT NULL,
    description           VARCHAR(255)      NOT NULL DEFAULT '',
    requires_id           SMALLINT UNSIGNED NULL COMMENT 'permission that must also be granted',
    default_for_new_staff TINYINT(1)        NOT NULL DEFAULT 0 COMMENT '1 = given to new staff accounts',
    sort_order            SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY uq_permission_code (code),
    KEY idx_permission_requires (requires_id),
    CONSTRAINT fk_permission_requires FOREIGN KEY (requires_id) REFERENCES permissions (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE user_permissions (
    user_id       INT UNSIGNED      NOT NULL,
    permission_id SMALLINT UNSIGNED NOT NULL,
    granted_by    INT UNSIGNED      NULL COMMENT 'admin who granted it; NULL = granted by this migration',
    granted_at    BIGINT UNSIGNED   NOT NULL,
    PRIMARY KEY (user_id, permission_id),
    KEY idx_up_permission (permission_id),
    KEY idx_up_granted_by (granted_by),
    CONSTRAINT fk_up_user       FOREIGN KEY (user_id)       REFERENCES users (id)       ON DELETE CASCADE,
    CONSTRAINT fk_up_permission FOREIGN KEY (permission_id) REFERENCES permissions (id) ON DELETE CASCADE,
    CONSTRAINT fk_up_granted_by FOREIGN KEY (granted_by)    REFERENCES users (id)       ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE schema_migrations (
    name       VARCHAR(100)    NOT NULL,
    applied_at BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


START TRANSACTION;

INSERT INTO permissions (code, category, label, description, default_for_new_staff, sort_order) VALUES
    ('shoes.view',             'Products & Shoe Colorways', 'View Shoes',              'See shoe models and open the shoe details page.', 1, 10),
    ('shoes.add',              'Products & Shoe Colorways', 'Add Shoes',               'Create new shoe models with their first colorways and starting stock.', 0, 20),
    ('shoes.edit',             'Products & Shoe Colorways', 'Edit Shoes',              'Change a model''s name, brand, category and price.', 0, 30),
    ('shoes.delete',           'Products & Shoe Colorways', 'Delete Shoes',            'Delete a whole shoe model and its stock. Stock history is kept.', 0, 40),
    ('colorways.view',         'Products & Shoe Colorways', 'View Colorways',          'See each model''s colorways and their images.', 1, 50),
    ('colorways.add',          'Products & Shoe Colorways', 'Add Colorways',           'Add new colorways, with sizes and starting stock, to existing models.', 0, 60),
    ('colorways.edit',         'Products & Shoe Colorways', 'Edit Colorways',          'Rename colorways, change descriptions, and add or remove sizes.', 0, 70),
    ('colorways.delete',       'Products & Shoe Colorways', 'Delete Colorways',        'Delete a colorway and its stock. A model''s last colorway cannot be deleted.', 0, 80),
    ('images.upload',          'Products & Shoe Colorways', 'Upload Shoe Images',      'Attach an image when adding a shoe or colorway.', 0, 90),
    ('images.change',          'Products & Shoe Colorways', 'Change Shoe Images',      'Replace or remove an existing colorway image.', 0, 100),
    ('inventory.view',         'Inventory Management',      'View Inventory',          'Open the inventory dashboard and see stock for every size.', 1, 110),
    ('stock.in',               'Inventory Management',      'Stock In',                'Add received pairs to a size.', 0, 120),
    ('stock.out',              'Inventory Management',      'Stock Out',               'Remove pairs from a size. Stock can never go below 0.', 0, 130),
    ('stock.adjust',           'Inventory Management',      'Stock Adjustment',        'Set an exact count, for example after a physical stock check.', 0, 140),
    ('inventory.history',      'Inventory Management',      'View Inventory History',  'See who changed stock, when, and by how much.', 0, 150),
    ('inventory.low_stock',    'Inventory Management',      'View Low Stock',          'See low-stock counts, filters and alerts.', 1, 160),
    ('inventory.out_of_stock', 'Inventory Management',      'View Out-of-Stock Items', 'See out-of-stock counts, filters and alerts.', 1, 170)
ON DUPLICATE KEY UPDATE
    category = VALUES(category), label = VALUES(label), description = VALUES(description),
    default_for_new_staff = VALUES(default_for_new_staff), sort_order = VALUES(sort_order);

UPDATE permissions p
  JOIN (          SELECT 'shoes.add'              AS code, 'shoes.view'     AS needs
        UNION ALL SELECT 'shoes.edit',                     'shoes.view'
        UNION ALL SELECT 'shoes.delete',                   'shoes.view'
        UNION ALL SELECT 'colorways.view',                 'shoes.view'
        UNION ALL SELECT 'colorways.add',                  'colorways.view'
        UNION ALL SELECT 'colorways.edit',                 'colorways.view'
        UNION ALL SELECT 'colorways.delete',               'colorways.view'
        UNION ALL SELECT 'images.upload',                  'colorways.view'
        UNION ALL SELECT 'images.change',                  'colorways.view'
        UNION ALL SELECT 'inventory.view',                 'colorways.view'
        UNION ALL SELECT 'stock.in',                       'inventory.view'
        UNION ALL SELECT 'stock.out',                      'inventory.view'
        UNION ALL SELECT 'stock.adjust',                   'inventory.view'
        UNION ALL SELECT 'inventory.history',              'inventory.view'
        UNION ALL SELECT 'inventory.low_stock',            'inventory.view'
        UNION ALL SELECT 'inventory.out_of_stock',         'inventory.view'
       ) d ON d.code = p.code
  JOIN permissions r ON r.code = d.needs
   SET p.requires_id = r.id;

-- A fresh install already has the final structure, so the migration has nothing to do.
INSERT INTO schema_migrations (name, applied_at) VALUES
    ('001_existing_staff_permissions', UNIX_TIMESTAMP()),
    ('001_colorways_permissions',      UNIX_TIMESTAMP());

COMMIT;


-- Staff can never be promoted to admin, and the only admin account cannot be deleted,
-- deactivated or changed to staff.
DELIMITER $$
CREATE OR REPLACE TRIGGER trg_users_protect_admin_update
BEFORE UPDATE ON users FOR EACH ROW
BEGIN
    IF OLD.role <> 'admin' AND NEW.role = 'admin' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Staff accounts cannot be promoted to administrator.';
    END IF;
    IF OLD.role = 'admin' AND NEW.role <> 'admin'
       AND NOT EXISTS (SELECT 1 FROM users WHERE role = 'admin' AND id <> OLD.id) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'The only administrator account cannot be changed to staff.';
    END IF;
    IF OLD.role = 'admin' AND OLD.is_active = 1 AND NEW.is_active <> 1
       AND NOT EXISTS (SELECT 1 FROM users WHERE role = 'admin' AND is_active = 1 AND id <> OLD.id) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'The only administrator account cannot be deactivated.';
    END IF;
END$$

CREATE OR REPLACE TRIGGER trg_users_protect_admin_delete
BEFORE DELETE ON users FOR EACH ROW
BEGIN
    IF OLD.role = 'admin'
       AND NOT EXISTS (SELECT 1 FROM users WHERE role = 'admin' AND id <> OLD.id) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'The only administrator account cannot be deleted.';
    END IF;
END$$
DELIMITER ;
