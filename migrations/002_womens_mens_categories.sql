-- =====================================================================================
-- Dazzling Footwear - Migration 002
-- Women's Shoes and Men's Shoes: the two main product categories.
--
-- RUN AGAINST: the database named in db.php (shoe_inventory), AFTER migration 001.
-- Take a backup first (see README, "Upgrading to Women's and Men's Shoes").
-- From cmd or PowerShell, exactly like this (one line):
--   C:\xampp\mysql\bin\mysql.exe -uroot --default-character-set=utf8mb4 --abort-source-on-error shoe_inventory -e "source C:/xampp/htdocs/shoe-inventory/migrations/002_womens_mens_categories.sql"
-- --abort-source-on-error makes the client stop at the first error. Never add --force.
--
-- WHAT IT DOES, IN ORDER
--   0. Safety checks - stops before changing anything if the database is not ready.
--   1. Adds shoes.main_category: 'women' = Women's Shoes, 'men' = Men's Shoes.
--      It is one new column on the existing shoes table - no second product table.
--      The existing free-text category (Running, Basketball, Casual, ...) is kept as it is.
--   2. Updates the description of the "Edit Shoes" permission (it now covers the main category too).
--   3. Records the migration and prints a short report. Every column ending in _expect_0 must show 0.
--
-- EXISTING SHOES ARE NOT CHANGED. Their main category starts empty (NULL), because the
-- database cannot know whether a model is sold as women's or men's. They stay listed under
-- "All Shoes", and the Inventory page shows them under "Unassigned" until someone with the
-- "Edit Shoes" permission picks Women's Shoes or Men's Shoes. Every NEW model must have one.
--
-- SAFE TO RUN AGAIN: each step skips work that is already done. Nothing is dropped or deleted.
-- The previous version of the pages keeps working after this migration (the new column is
-- optional), so the database can be upgraded just before the new PHP files are copied in.
--
-- TRANSACTIONS: MariaDB saves each ALTER TABLE immediately (ROLLBACK cannot undo it), so only
-- the data step runs inside a transaction. To undo this migration, restore the backup, or see
-- "UNDO" at the end of this file.
-- =====================================================================================

SET NAMES utf8mb4;
-- For this session only: refuse invalid data instead of silently adjusting it.
SET SESSION sql_mode = 'STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION';


-- -------------------------------------------------------------------------------------
-- 0. Safety checks (read-only). If one of these stops the script, NOTHING was changed.
-- -------------------------------------------------------------------------------------
DELIMITER $$
BEGIN NOT ATOMIC
    DECLARE n INT DEFAULT 0;

    IF DATABASE() IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Stopped: no database selected. Nothing was changed.';
    END IF;

    SELECT COUNT(*) INTO n FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('shoes', 'shoe_colorways', 'schema_migrations');
    IF n < 3 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Stopped: run migrations/001_colorways_permissions.sql first (or wrong database?). Nothing was changed.';
    END IF;
END$$

BEGIN NOT ATOMIC
    IF NOT EXISTS (SELECT 1 FROM schema_migrations WHERE name = '001_colorways_permissions') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Stopped: migration 001 has not finished. Run migrations/001_colorways_permissions.sql first. Nothing was changed.';
    END IF;
END$$
DELIMITER ;


-- -------------------------------------------------------------------------------------
-- 1. The main category of each shoe model
--    One value per model, shared by all its colorways (like brand, category and price).
--    NULL = not chosen yet; only models from before this migration can have NULL - the app
--    requires Women's Shoes or Men's Shoes when a model is added or edited.
--    The ENUM lists the allowed values. The CHECK also refuses anything else when the
--    server is not in strict mode (the XAMPP default), where an ENUM alone would quietly
--    store an empty value instead of refusing it.
-- -------------------------------------------------------------------------------------
ALTER TABLE shoes
    ADD COLUMN IF NOT EXISTS main_category ENUM('women', 'men') NULL DEFAULT NULL
        COMMENT 'women = Women''s Shoes, men = Men''s Shoes; NULL = not chosen yet'
        AFTER brand;

ALTER TABLE shoes ADD KEY IF NOT EXISTS idx_shoes_main_category (main_category);

ALTER TABLE shoes
    ADD CONSTRAINT IF NOT EXISTS chk_shoe_main_category
        CHECK (main_category IS NULL OR main_category IN ('women', 'men'));


-- -------------------------------------------------------------------------------------
-- 2. Permission text: "Edit Shoes" now also covers the main category.
--    (No new permission: adding a model needs "Add Shoes" and changing one needs
--    "Edit Shoes", exactly as for its category and price.)
-- -------------------------------------------------------------------------------------
START TRANSACTION;

UPDATE permissions
   SET description = 'Change a model''s name, brand, main category (Women''s or Men''s Shoes), category and price.'
 WHERE code = 'shoes.edit';

INSERT INTO schema_migrations (name, applied_at) VALUES ('002_womens_mens_categories', UNIX_TIMESTAMP())
    ON DUPLICATE KEY UPDATE name = name;

COMMIT;


-- -------------------------------------------------------------------------------------
-- 3. Report
-- -------------------------------------------------------------------------------------
SELECT DATABASE()                                               AS migrated_database,
       (SELECT COUNT(*) FROM shoes)                             AS shoe_models,
       (SELECT COUNT(*) FROM shoes WHERE main_category = 'women') AS womens_shoes,
       (SELECT COUNT(*) FROM shoes WHERE main_category = 'men')   AS mens_shoes,
       (SELECT COUNT(*) FROM shoes WHERE main_category IS NULL)   AS not_assigned_yet,
       (SELECT COUNT(*) FROM shoe_colorways)                    AS colorways,
       (SELECT COUNT(*) FROM shoe_sizes)                        AS size_rows,
       (SELECT COALESCE(SUM(quantity), 0) FROM shoe_sizes)      AS pairs_in_stock;

SELECT (SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'shoes' AND COLUMN_NAME = 'main_category') = 0
                                                                        AS column_missing_expect_0,
       (SELECT COUNT(*) FROM information_schema.CHECK_CONSTRAINTS
         WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'shoes'
           AND CONSTRAINT_NAME = 'chk_shoe_main_category') = 0         AS check_missing_expect_0,
       (SELECT COUNT(*) FROM shoes
         WHERE main_category IS NOT NULL AND main_category NOT IN ('women', 'men')) AS invalid_values_expect_0;


-- -------------------------------------------------------------------------------------
-- UNDO (only if you really need to go back; restoring the backup is the simplest way).
-- Run these lines by hand, then put back the previous PHP files:
--   ALTER TABLE shoes DROP CONSTRAINT IF EXISTS chk_shoe_main_category;
--   ALTER TABLE shoes DROP KEY IF EXISTS idx_shoes_main_category;
--   ALTER TABLE shoes DROP COLUMN IF EXISTS main_category;
--   DELETE FROM schema_migrations WHERE name = '002_womens_mens_categories';
-- -------------------------------------------------------------------------------------
