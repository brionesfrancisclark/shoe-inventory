-- =====================================================================================
-- Footwear Inventory - Migration 001
-- Colorways, automatic shoe-model grouping, stock history, staff permissions,
-- and the single-administrator rule.
--
-- RUN AGAINST: the database named in db.php (shoe_inventory). Take a backup first.
-- From cmd or PowerShell, exactly like this (one line):
--   C:\xampp\mysql\bin\mysql.exe -uroot --default-character-set=utf8mb4 --abort-source-on-error shoe_inventory -e "source C:/xampp/htdocs/shoe-inventory/migrations/001_colorways_permissions.sql"
-- --abort-source-on-error makes the client stop at the first error. Without it the client
-- would report the error and carry on with the next statement. Never add --force.
--
-- WHAT IT DOES, IN ORDER
--   0. Safety checks - stops before changing anything if the data is not ready.
--   1. Groups shoes by brand + model name (one listing per model).
--   2. Adds colorways. Every shoe gets an "Original" colorway that holds its existing stock.
--   3. Makes every stock row belong to exactly one colorway.
--   4. Adds the stock history log, starting with each size's current stock.
--   5. Adds the staff permission tables and the permission checklist.
--   6. Allows only one administrator account, and protects it.
--   7. Prints a short report. Every column ending in _expect_0 must show 0.
--
-- SAFE TO RUN AGAIN: each step skips work that is already done.
-- NOTHING IS DROPPED except the old rule "one stock row per shoe + size" (uq_shoe_size),
-- which is replaced by "one stock row per colorway + size".
--
-- TRANSACTIONS: MariaDB saves each CREATE/ALTER immediately (ROLLBACK cannot undo it),
-- so only the data steps run inside transactions. If any statement fails, the client stops
-- at that statement and reports it; fix the problem and run the whole file again.
-- =====================================================================================

SET NAMES utf8mb4;
-- For this session only: refuse invalid data instead of silently adjusting it.
SET SESSION sql_mode = 'STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION';


-- -------------------------------------------------------------------------------------
-- 0. Safety checks (read-only). If one of these stops the script (run with
--    --abort-source-on-error as shown above), NOTHING was changed.
-- -------------------------------------------------------------------------------------
DELIMITER $$
BEGIN NOT ATOMIC
    DECLARE n INT DEFAULT 0;

    IF DATABASE() IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Stopped: no database selected. Nothing was changed.';
    END IF;

    SELECT COUNT(*) INTO n FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('shoes', 'shoe_sizes', 'users');
    IF n < 3 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Stopped: shoes, shoe_sizes or users table not found. Wrong database? Nothing was changed.';
    END IF;

    SELECT COUNT(*) INTO n FROM users WHERE role = 'admin';
    IF n > 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Stopped: more than one admin account. Make the extra ones staff and run again. Nothing was changed.';
    END IF;

    SELECT COUNT(*) INTO n FROM (
        SELECT 1 FROM shoes
         GROUP BY LOWER(TRIM(REGEXP_REPLACE(brand, '[[:space:]]+', ' '))) COLLATE utf8mb4_bin,
                  LOWER(TRIM(REGEXP_REPLACE(name,  '[[:space:]]+', ' '))) COLLATE utf8mb4_bin
        HAVING COUNT(*) > 1
    ) AS duplicate_models;
    IF n > 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Stopped: duplicate shoe models (see verify_inventory.sql part 4a). Nothing was changed.';
    END IF;
END$$
DELIMITER ;

-- Records which one-time steps have run, so re-running this file never repeats them.
CREATE TABLE IF NOT EXISTS schema_migrations (
    name       VARCHAR(100)    NOT NULL,
    applied_at BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- -------------------------------------------------------------------------------------
-- 1. One listing per shoe model
--    The database fills brand_key / name_key itself: lower case, runs of spaces or tabs
--    reduced to one space, ends trimmed. "Nike / Air Force 1" and " NIKE / air  force 1 "
--    get identical keys, so the unique rule refuses a second copy - even when two people
--    save at the same moment. Accents and punctuation are NOT ignored, so "Air Force 1",
--    "Air Force 1 Low" and "Air Forcé 1" stay separate models.
-- -------------------------------------------------------------------------------------
ALTER TABLE shoes
    ADD COLUMN IF NOT EXISTS brand_key VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin
        AS (LOWER(TRIM(REGEXP_REPLACE(brand, '[[:space:]]+', ' ')))) STORED
        COMMENT 'brand in lower case, spaces tidied (model grouping)',
    ADD COLUMN IF NOT EXISTS name_key VARCHAR(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin
        AS (LOWER(TRIM(REGEXP_REPLACE(name, '[[:space:]]+', ' ')))) STORED
        COMMENT 'model name in lower case, spaces tidied (model grouping)';

ALTER TABLE shoes ADD UNIQUE KEY IF NOT EXISTS uq_shoe_model (brand_key, name_key);


-- -------------------------------------------------------------------------------------
-- 2. Colorways
--    A colorway belongs to one shoe model and has its own name, description and image.
--    The image is EITHER an uploaded file (image_path) OR an http(s) link (image_url).
--    image_path may only look like uploads/colorways/<32 hex chars>.<jpg|png|webp|gif>,
--    so a bad value can never point the app at another file on the server.
-- -------------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS shoe_colorways (
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

-- New column first allows NULL; step 3 makes it required once every row is filled in.
ALTER TABLE shoe_sizes ADD COLUMN IF NOT EXISTS colorway_id INT UNSIGNED NULL AFTER shoe_id;

-- Data step (one transaction): give every shoe an "Original" colorway and move its
-- existing stock rows into it. The shoe's current image link is copied to the colorway
-- (only if it is a valid http(s) link); shoes.image_url itself is left untouched.
START TRANSACTION;

INSERT INTO shoe_colorways (shoe_id, name, image_url, sort_order, created_at)
SELECT s.id,
       'Original',
       CASE WHEN s.image_url REGEXP '^https?://[^[:space:]]+$' THEN s.image_url END,
       0,
       UNIX_TIMESTAMP()
  FROM shoes s
 WHERE NOT EXISTS (SELECT 1 FROM shoe_colorways c WHERE c.shoe_id = s.id)
 ORDER BY s.id;

UPDATE shoe_sizes z
  JOIN shoe_colorways c ON c.shoe_id = z.shoe_id AND c.name_key = 'original'
   SET z.colorway_id = c.id
 WHERE z.colorway_id IS NULL;

COMMIT;


-- -------------------------------------------------------------------------------------
-- 3. Every stock row belongs to exactly one colorway
--    * colorway_id becomes required.
--    * The colorway must belong to the same shoe as the row (composite foreign key), so
--      stock can never be attached to another model's colorway.
--    * One row per colorway + size: Chicago Red US 9 and Royal Blue US 9 are separate rows,
--      so changing one can never change the other.
--    * Deleting a colorway deletes only its own stock rows.
-- -------------------------------------------------------------------------------------
DELIMITER $$
BEGIN NOT ATOMIC
    IF EXISTS (SELECT 1 FROM shoe_sizes WHERE colorway_id IS NULL) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Stopped before step 3: some stock rows have no colorway. Steps 1-2 are saved; run again.';
    END IF;
END$$
DELIMITER ;

-- Keep an index on shoe_id for fk_sizes_shoe before the old unique key (its index) goes.
ALTER TABLE shoe_sizes ADD KEY IF NOT EXISTS idx_sizes_shoe (shoe_id);
ALTER TABLE shoe_sizes DROP KEY IF EXISTS uq_shoe_size;
ALTER TABLE shoe_sizes MODIFY COLUMN colorway_id INT UNSIGNED NOT NULL;
ALTER TABLE shoe_sizes ADD UNIQUE KEY IF NOT EXISTS uq_colorway_size (colorway_id, size_number);
ALTER TABLE shoe_sizes ADD KEY IF NOT EXISTS idx_sizes_colorway_shoe (colorway_id, shoe_id);
ALTER TABLE shoe_sizes
    ADD CONSTRAINT fk_sizes_colorway FOREIGN KEY IF NOT EXISTS (colorway_id, shoe_id)
        REFERENCES shoe_colorways (id, shoe_id) ON DELETE CASCADE;


-- -------------------------------------------------------------------------------------
-- 4. Stock history
--    One row per stock change: who, when, what kind of change, before and after.
--    Brand / model / colorway / size names are COPIED into each row, and the links to
--    shoe, colorway and size are set to NULL (never deleted) if those are removed later,
--    so history survives renames and deletions. A user who has history cannot be
--    deleted (users.php already disables such accounts instead).
--    quantity_change is calculated by the database (after - before).
-- -------------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS stock_movements (
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

-- Data step: an "opening" entry for every existing size that has no history yet,
-- so history starts from the stock on hand today (0 -> current quantity).
START TRANSACTION;

INSERT INTO stock_movements
       (created_at, user_id, movement_type, shoe_id, colorway_id, size_id,
        brand, shoe_name, colorway_name, size_number, quantity_before, quantity_after, note)
SELECT UNIX_TIMESTAMP(), NULL, 'opening', s.id, c.id, z.id,
       s.brand, s.name, c.name, z.size_number, 0, z.quantity,
       'Stock on hand when history tracking started'
  FROM shoe_sizes z
  JOIN shoe_colorways c ON c.id = z.colorway_id
  JOIN shoes s          ON s.id = z.shoe_id
 WHERE NOT EXISTS (SELECT 1 FROM stock_movements m WHERE m.size_id = z.id)
 ORDER BY z.id;

COMMIT;


-- -------------------------------------------------------------------------------------
-- 5. Staff permissions
--    permissions      = the checklist (only modules that exist in this system).
--    user_permissions = which staff account has which permission (one row each; the
--                       primary key makes duplicate assignments impossible).
--    Admin accounts are not listed here: the PHP checks always allow the admin.
-- -------------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS permissions (
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

CREATE TABLE IF NOT EXISTS user_permissions (
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

-- Prerequisites, e.g. "Edit Shoes" also needs "View Shoes".
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

COMMIT;

-- One-time step: staff accounts that exist today keep exactly what they can do today
-- (view inventory + change stock quantities). Runs only once, so a later "Clear All"
-- by the admin is never undone by running this file again.
START TRANSACTION;

INSERT INTO user_permissions (user_id, permission_id, granted_by, granted_at)
SELECT u.id, p.id, NULL, UNIX_TIMESTAMP()
  FROM users u
  JOIN permissions p ON p.code IN ('shoes.view', 'colorways.view', 'inventory.view', 'inventory.low_stock',
                                   'inventory.out_of_stock', 'stock.in', 'stock.out', 'stock.adjust')
 WHERE u.role = 'staff'
   AND NOT EXISTS (SELECT 1 FROM schema_migrations m WHERE m.name = '001_existing_staff_permissions')
   AND NOT EXISTS (SELECT 1 FROM user_permissions x WHERE x.user_id = u.id AND x.permission_id = p.id);

INSERT INTO schema_migrations (name, applied_at) VALUES ('001_existing_staff_permissions', UNIX_TIMESTAMP())
    ON DUPLICATE KEY UPDATE name = name;

COMMIT;


-- -------------------------------------------------------------------------------------
-- 6. Single administrator
--    admin_slot is 1 for the admin and NULL for staff. A unique key allows many NULLs but
--    only one 1, so the database refuses a second admin (insert OR promotion), even if the
--    PHP checks were bypassed. The triggers stop staff from ever being promoted, and stop
--    the ONLY admin account from being deleted, deactivated or changed to staff.
--    (Because only one admin can exist, that always means: the admin is protected.)
--    Password, name, login counters etc. of the admin can still be updated normally.
-- -------------------------------------------------------------------------------------
ALTER TABLE users
    ADD COLUMN IF NOT EXISTS admin_slot TINYINT UNSIGNED AS (IF(role = 'admin', 1, NULL)) STORED
        COMMENT '1 = the admin account; unique, so only one admin can exist';

ALTER TABLE users ADD UNIQUE KEY IF NOT EXISTS uq_single_admin (admin_slot);

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


-- -------------------------------------------------------------------------------------
-- 7. Done - record it and print a report
-- -------------------------------------------------------------------------------------
INSERT INTO schema_migrations (name, applied_at) VALUES ('001_colorways_permissions', UNIX_TIMESTAMP())
    ON DUPLICATE KEY UPDATE name = name;

SELECT DATABASE()                                          AS migrated_database,
       (SELECT COUNT(*) FROM shoes)                        AS shoe_models,
       (SELECT COUNT(*) FROM shoe_colorways)               AS colorways,
       (SELECT COUNT(*) FROM shoe_sizes)                   AS size_rows,
       (SELECT COALESCE(SUM(quantity), 0) FROM shoe_sizes) AS pairs_in_stock,
       (SELECT COUNT(*) FROM stock_movements)              AS history_entries,
       (SELECT COUNT(*) FROM permissions)                  AS permissions,
       (SELECT COUNT(*) FROM users WHERE role = 'admin')   AS admin_accounts,
       (SELECT COUNT(*) FROM users WHERE role = 'staff')   AS staff_accounts;

SELECT (SELECT COUNT(*) FROM shoes s
         WHERE NOT EXISTS (SELECT 1 FROM shoe_colorways c WHERE c.shoe_id = s.id))          AS shoes_without_colorway_expect_0,
       (SELECT COUNT(*) FROM shoe_sizes z
          JOIN shoe_colorways c ON c.id = z.colorway_id
         WHERE c.shoe_id <> z.shoe_id)                                                      AS stock_in_wrong_model_expect_0,
       (SELECT COUNT(*) FROM shoe_sizes z
         WHERE z.quantity <> (SELECT COALESCE(SUM(m.quantity_change), 0)
                                FROM stock_movements m WHERE m.size_id = z.id))             AS stock_not_matching_history_expect_0,
       (SELECT COUNT(*) FROM users WHERE role = 'admin') > 1                                AS extra_admins_expect_0;
